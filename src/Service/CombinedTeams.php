<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;
use DomainException;
use RuntimeException;

final class CombinedTeams
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function available(): bool
    {
        return (bool)$this->db->execute("SHOW TABLES LIKE 'combined_teams'")->fetch('num');
    }

    public function students(int $registrationId, int $eventId): array
    {
        return $this->db->execute(
            "SELECT DISTINCT u.id, u.first_name, u.middle_name, u.last_name
             FROM crstudentevents e
             JOIN conventionregistrations r ON r.id = e.conventionregistration_id
             JOIN conventionregistrationstudents s ON s.conventionregistration_id = r.id AND s.student_id = e.student_id AND s.status = 1
             JOIN users u ON u.id = e.student_id AND u.school_id = r.user_id AND u.user_type = 'Student' AND u.status = 1
             WHERE r.id = ? AND e.event_id = ?
             ORDER BY u.first_name, u.last_name",
            [$registrationId, $eventId]
        )->fetchAll('assoc');
    }

    public function request(int $registrationId, int $schoolId, int $requesterId, int $eventId, int $partnerId, array $ownIds, array $partnerIds): int
    {
        $this->requireStorage();
        return $this->db->transactional(function () use ($registrationId, $schoolId, $requesterId, $eventId, $partnerId, $ownIds, $partnerIds) {
            $source = $this->registration($registrationId);
            if ((int)$source['user_id'] !== $schoolId || $schoolId === $partnerId) {
                throw new DomainException('Choose another school registered for the same convention.');
            }
            $partner = $this->db->execute(
                'SELECT * FROM conventionregistrations WHERE conventionseason_id = ? AND user_id = ? FOR UPDATE',
                [$source['conventionseason_id'], $partnerId]
            )->fetchAll('assoc');
            if (count($partner) !== 1) {
                throw new DomainException('The partner school must have one registration in this convention season.');
            }
            $this->registration((int)$partner[0]['id']);
            $event = $this->event($eventId, (int)$source['conventionseason_id']);
            $this->checkUnlocked((int)$source['conventionseason_id']);
            $ownIds = $this->validateIds($ownIds, $registrationId, $eventId);
            $partnerIds = $this->validateIds($partnerIds, (int)$partner[0]['id'], $eventId);
            $allIds = array_merge($ownIds, $partnerIds);
            $this->checkSize($event, count($allIds));
            $low = min($schoolId, $partnerId);
            $high = max($schoolId, $partnerId);
            if ($this->db->execute(
                'SELECT id FROM combined_teams WHERE conventionseason_id = ? AND event_id = ? AND school_low_id = ? AND school_high_id = ?',
                [$source['conventionseason_id'], $eventId, $low, $high]
            )->fetch('assoc')) {
                throw new DomainException('A combined-team request already exists for these schools and this event. Check the request list.');
            }
            $this->checkMembersAvailable($allIds, $eventId, (int)$source['conventionseason_id']);
            $now = date('Y-m-d H:i:s');
            $this->db->execute(
                'INSERT INTO combined_teams (conventionseason_id,event_id,school_low_id,school_high_id,source_registration_id,partner_registration_id,requested_by_user_id,status,group_name,created,modified) VALUES (?,?,?,?,?,?,?,2,?,?,?)',
                [$source['conventionseason_id'], $eventId, $low, $high, $registrationId, $partner[0]['id'], $requesterId, 'Combined ' . $low . '-' . $high, $now, $now]
            );
            $teamId = (int)$this->db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
            foreach ([[$registrationId, $ownIds], [(int)$partner[0]['id'], $partnerIds]] as [$regId, $ids]) {
                foreach ($ids as $id) {
                    $this->db->execute(
                        'INSERT INTO combined_team_members (team_id,conventionseason_id,event_id,registration_id,student_id) VALUES (?,?,?,?,?)',
                        [$teamId, $source['conventionseason_id'], $eventId, $regId, $id]
                    );
                }
            }
            return $teamId;
        });
    }

    public function decide(int $teamId, int $adminId, bool $approve): void
    {
        $this->requireStorage();
        $this->db->transactional(function () use ($teamId, $adminId, $approve) {
            $team = $this->db->execute('SELECT * FROM combined_teams WHERE id = ? FOR UPDATE', [$teamId])->fetch('assoc');
            if (!$team || (int)$team['status'] !== 2) {
                throw new DomainException('This request has already been decided or no longer exists.');
            }
            if (!$approve) {
                $this->db->execute('UPDATE combined_teams SET status = 0, approved_by_admin_id = ?, modified = NOW() WHERE id = ?', [$adminId, $teamId]);
                $this->db->execute('UPDATE combined_team_members SET reserved = NULL WHERE team_id = ?', [$teamId]);
                return;
            }
            $source = $this->registration((int)$team['source_registration_id']);
            $partner = $this->registration((int)$team['partner_registration_id']);
            if ($source['conventionseason_id'] !== $partner['conventionseason_id']
                || (int)$source['conventionseason_id'] !== (int)$team['conventionseason_id']
                || min((int)$source['user_id'], (int)$partner['user_id']) !== (int)$team['school_low_id']
                || max((int)$source['user_id'], (int)$partner['user_id']) !== (int)$team['school_high_id']) {
                throw new DomainException('The school registrations have changed. This team cannot be approved.');
            }
            $this->checkUnlocked((int)$team['conventionseason_id']);
            $event = $this->event((int)$team['event_id'], (int)$team['conventionseason_id']);
            $members = $this->members($teamId);
            foreach ([$source, $partner] as $registration) {
                $ids = array_column(array_filter($members, static fn(array $m): bool => (int)$m['registration_id'] === (int)$registration['id']), 'student_id');
                $this->validateIds($ids, (int)$registration['id'], (int)$event['id']);
            }
            $this->checkSize($event, count($members));
            $name = $team['group_name'];
            foreach ([$source, $partner] as $registration) {
                $existing = $this->db->execute(
                    'SELECT id FROM eventsubmissions WHERE conventionregistration_id = ? AND event_id = ? AND group_name = ?',
                    [$registration['id'], $event['id'], $name]
                )->fetch('assoc');
                if ($existing) {
                    throw new DomainException('A submission already uses the combined team name. Resolve it before approval.');
                }
            }
            foreach ($members as $member) {
                $owner = (int)$member['registration_id'] === (int)$source['id'] ? $source : $partner;
                $other = (int)$owner['id'] === (int)$source['id'] ? $partner : $source;
                $entries = $this->db->execute(
                    'SELECT id, group_name FROM crstudentevents WHERE conventionregistration_id = ? AND student_id = ? AND event_id = ? FOR UPDATE',
                    [$owner['id'], $member['student_id'], $event['id']]
                )->fetchAll('assoc');
                if (count($entries) !== 1 || trim((string)$entries[0]['group_name']) !== '') {
                    throw new DomainException('A selected student is already grouped or their event entry changed. No changes were made.');
                }
                $this->db->execute('UPDATE crstudentevents SET group_name = ?, modified = NOW() WHERE id = ?', [$name, $entries[0]['id']]);
                if ($this->db->execute('SELECT id FROM crstudentevents WHERE conventionregistration_id = ? AND student_id = ? AND event_id = ?', [$other['id'], $member['student_id'], $event['id']])->fetch('assoc')) {
                    throw new DomainException('A selected student already has an entry at the partner school. Resolve the old combination before approval.');
                }
                $this->db->execute(
                    'INSERT INTO crstudentevents (slug,conventionregistration_id,conventionseason_id,convention_id,user_id,season_id,season_year,student_id,event_id,event_id_number,group_name,created,modified) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
                    ['combined-team-' . $teamId . '-' . $other['id'] . '-' . $member['student_id'], $other['id'], $other['conventionseason_id'], $other['convention_id'], $other['user_id'], $other['season_id'], $other['season_year'], $member['student_id'], $event['id'], $event['event_id_number'], $name]
                );
                // Preserve existing scheduling/results integrations, but mark these rows as team-managed.
                $this->db->execute(
                    'INSERT INTO combinerequests (slug,conventionregistration_id,conventionseason_id,convention_id,user_id,combine_with_user_id,season_id,season_year,event_id,event_id_number,student_name,status,created,modified,combined_team_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW(),?)',
                    ['combined-team-' . $teamId . '-member-' . $member['student_id'], $owner['id'], $owner['conventionseason_id'], $owner['convention_id'], $owner['user_id'], $other['user_id'], $owner['season_id'], $owner['season_year'], $event['id'], $event['event_id_number'], $member['student_name'], $teamId]
                );
            }
            $this->db->execute(
                'INSERT INTO eventsubmissions (slug,conventionregistration_id,conventionseason_id,convention_id,user_id,season_id,season_year,event_id,event_id_number,group_name,student_id,combined_team_id,created,modified) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,NOW(),NOW())',
                ['combined-team-submission-' . $teamId, $source['id'], $source['conventionseason_id'], $source['convention_id'], $source['user_id'], $source['season_id'], $source['season_year'], $event['id'], $event['event_id_number'], $name, $teamId]
            );
            $this->db->execute('UPDATE combined_teams SET status = 1, approved_by_admin_id = ?, modified = NOW() WHERE id = ?', [$adminId, $teamId]);
        });
    }

    public function listing(?int $registrationId = null, ?int $seasonId = null): array
    {
        if (!$this->available()) {
            return [];
        }
        $where = '1=1';
        $params = [];
        if ($registrationId !== null) {
            $where .= ' AND (t.source_registration_id = ? OR t.partner_registration_id = ?)';
            $params = [$registrationId, $registrationId];
        }
        if ($seasonId) {
            $where .= ' AND t.conventionseason_id = ?';
            $params[] = $seasonId;
        }
        $teams = $this->db->execute(
            "SELECT t.*, a.first_name AS source_school, b.first_name AS partner_school, e.event_name, e.event_id_number, c.name AS convention_name
             FROM combined_teams t JOIN conventionregistrations r ON r.id = t.source_registration_id
             JOIN users a ON a.id = r.user_id JOIN conventionregistrations p ON p.id = t.partner_registration_id
             JOIN users b ON b.id = p.user_id JOIN events e ON e.id = t.event_id JOIN conventions c ON c.id = r.convention_id
             WHERE $where ORDER BY t.id DESC",
            $params
        )->fetchAll('assoc');
        foreach ($teams as &$team) {
            $team['members'] = $this->members((int)$team['id']);
        }
        return $teams;
    }

    public function approvedForGroup(int $registrationId, int $eventId, string $groupName): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $team = $this->db->execute(
            'SELECT * FROM combined_teams WHERE status = 1 AND event_id = ? AND group_name = ? AND (source_registration_id = ? OR partner_registration_id = ?)',
            [$eventId, $groupName, $registrationId, $registrationId]
        )->fetch('assoc');
        return $team ?: null;
    }

    public function sharedSubmissionIds(int $registrationId): array
    {
        if (!$this->available()) {
            return [];
        }

        return array_map('intval', array_column($this->db->execute(
            'SELECT s.id FROM eventsubmissions s JOIN combined_teams t ON t.id = s.combined_team_id WHERE t.status = 1 AND (t.source_registration_id = ? OR t.partner_registration_id = ?)',
            [$registrationId, $registrationId]
        )->fetchAll('assoc'), 'id'));
    }

    public function groupLabels(int $seasonId, int $eventId): array
    {
        if (!$this->available()) {
            return [];
        }
        $rows = $this->db->execute(
            "SELECT t.group_name, CONCAT(a.first_name, ' + ', b.first_name) AS school_label
             FROM combined_teams t JOIN users a ON a.id = t.school_low_id JOIN users b ON b.id = t.school_high_id
             WHERE t.status = 1 AND t.conventionseason_id = ? AND t.event_id = ?", [$seasonId, $eventId]
        )->fetchAll('assoc');
        $labels = [];
        foreach ($rows as $row) {
            $labels['group:' . $row['group_name']] = $row['school_label'];
        }
        return $labels;
    }

    public function submissionLinks(int $registrationId): array
    {
        if (!$this->available()) {
            return [];
        }
        $rows = $this->db->execute(
            'SELECT s.id, e.slug AS event_slug, ce.slug AS entry_slug, rs.slug AS student_slug
             FROM combined_teams t JOIN eventsubmissions s ON s.combined_team_id = t.id
             JOIN events e ON e.id = t.event_id
             JOIN combined_team_members m ON m.team_id = t.id AND m.registration_id = ?
             JOIN conventionregistrationstudents rs ON rs.conventionregistration_id = m.registration_id AND rs.student_id = m.student_id
             JOIN crstudentevents ce ON ce.conventionregistration_id = m.registration_id AND ce.student_id = m.student_id AND ce.event_id = t.event_id AND ce.group_name = t.group_name
             WHERE t.status = 1 ORDER BY m.student_id', [$registrationId]
        )->fetchAll('assoc');
        $links = [];
        foreach ($rows as $row) {
            $links[(int)$row['id']] ??= ['controller' => 'Eventsubmissions', 'action' => 'submitgroupevent', $row['student_slug'], $row['event_slug'], $row['entry_slug']];
        }
        return $links;
    }

    public function requiredEvents(int $registrationId, int $studentId): array
    {
        if (!$this->available()) {
            return [];
        }
        return array_map('intval', array_column($this->db->execute(
            'SELECT m.event_id FROM combined_team_members m JOIN combined_teams t ON t.id = m.team_id WHERE t.status IN (1,2) AND m.registration_id = ? AND m.student_id = ?',
            [$registrationId, $studentId]
        )->fetchAll('assoc'), 'event_id'));
    }

    public function managedStudent(int $seasonId, int $eventId, int $studentId): bool
    {
        return $this->available() && (bool)$this->db->execute(
            'SELECT m.id FROM combined_team_members m JOIN combined_teams t ON t.id = m.team_id WHERE t.status IN (1,2) AND m.conventionseason_id = ? AND m.event_id = ? AND m.student_id = ?',
            [$seasonId, $eventId, $studentId]
        )->fetch('assoc');
    }

    public function approvedMembers(int $registrationId, int $eventId): array
    {
        if (!$this->available()) {
            return [];
        }
        return array_map('intval', array_column($this->db->execute(
            'SELECT m.student_id FROM combined_team_members m JOIN combined_teams t ON t.id = m.team_id WHERE t.status = 1 AND t.event_id = ? AND (t.source_registration_id = ? OR t.partner_registration_id = ?)',
            [$eventId, $registrationId, $registrationId]
        )->fetchAll('assoc'), 'student_id'));
    }

    private function members(int $teamId): array
    {
        return $this->db->execute(
            "SELECT m.*, CONCAT_WS(' ', u.first_name, NULLIF(u.middle_name, ''), u.last_name) AS student_name, school.first_name AS school_name
             FROM combined_team_members m JOIN users u ON u.id = m.student_id
             JOIN conventionregistrations r ON r.id = m.registration_id JOIN users school ON school.id = r.user_id
             WHERE m.team_id = ? ORDER BY m.registration_id, u.first_name, u.last_name", [$teamId]
        )->fetchAll('assoc');
    }

    private function registration(int $id): array
    {
        $row = $this->db->execute(
            "SELECT r.* FROM conventionregistrations r JOIN users u ON u.id = r.user_id
             JOIN conventionseasons cs ON cs.id = r.conventionseason_id AND cs.convention_id = r.convention_id AND cs.season_id = r.season_id AND cs.season_year = r.season_year
             WHERE r.id = ? AND u.user_type = 'School' AND u.status = 1 AND u.activation_status = 1 FOR UPDATE", [$id]
        )->fetch('assoc');
        if (!$row) {
            throw new DomainException('An active school convention registration is required.');
        }
        return $row;
    }

    private function event(int $id, int $seasonId): array
    {
        $event = $this->db->execute(
            'SELECT e.* FROM events e JOIN conventionseasonevents ce ON ce.event_id = e.id WHERE e.id = ? AND ce.conventionseasons_id = ? AND e.status = 1 AND e.group_event_yes_no = 1 LIMIT 1',
            [$id, $seasonId]
        )->fetch('assoc');
        if (!$event) {
            throw new DomainException('Choose a team/group event offered in this convention season.');
        }
        return $event;
    }

    private function validateIds(array $ids, int $registrationId, int $eventId): array
    {
        $valid = array_map('intval', array_column($this->students($registrationId, $eventId), 'id'));
        $selected = [];
        foreach ($ids as $id) {
            if (!is_scalar($id) || !ctype_digit((string)$id) || !in_array((int)$id, $valid, true)) {
                throw new DomainException('Select only active registered students already entered in this event.');
            }
            $selected[] = (int)$id;
        }
        if (!$selected) {
            throw new DomainException('Select at least one student from each school.');
        }
        return array_values(array_unique($selected));
    }

    private function checkSize(array $event, int $count): void
    {
        if ($count < (int)$event['min_no'] || ((int)$event['max_no'] > 0 && $count > (int)$event['max_no'])) {
            throw new DomainException('The combined student count must meet the event minimum and maximum team sizes.');
        }
    }

    private function checkMembersAvailable(array $ids, int $eventId, int $seasonId): void
    {
        foreach ($ids as $id) {
            if ($this->db->execute(
                'SELECT id FROM combined_team_members WHERE conventionseason_id = ? AND event_id = ? AND student_id = ? AND reserved = 1',
                [$seasonId, $eventId, $id]
            )->fetch('assoc')) {
                throw new DomainException('A selected student already belongs to a combined-team request for this event.');
            }
            if ($this->db->execute(
                "SELECT id FROM crstudentevents WHERE conventionseason_id = ? AND event_id = ? AND student_id = ? AND TRIM(COALESCE(group_name, '')) <> ''",
                [$seasonId, $eventId, $id]
            )->fetch('assoc')) {
                throw new DomainException('A selected student is already in a group. Remove that grouping before requesting a combined team.');
            }
        }
    }

    private function checkUnlocked(int $seasonId): void
    {
        $season = $this->db->execute('SELECT * FROM conventionseasons WHERE id = ?', [$seasonId])->fetch('assoc');
        $open = $season['submissions_open'] ?? $season['submission_open'] ?? null;
        if (is_string($open) && strlen($open) === 1 && ord($open) <= 1) {
            $open = ord($open);
        }
        if (!$season || $open === null || (int)$open !== 1) {
            throw new DomainException('Combined teams cannot be changed while submissions are locked.');
        }
    }

    private function requireStorage(): void
    {
        if (!$this->available()) {
            throw new RuntimeException('Run migration 009 before enabling combined-team requests.');
        }
    }
}
