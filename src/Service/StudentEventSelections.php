<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;
use DomainException;
use RuntimeException;

final class StudentEventSelections
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function review(int $id): ?array
    {
        return $this->db->execute('SELECT * FROM student_event_reviews WHERE student_registration_id = ?', [$id])->fetch('assoc') ?: null;
    }

    public static function allowsIndividualAge(int $age, int $ageGroup, bool $isTeamEvent, string $category): bool
    {
        if ($isTeamEvent) {
            return true;
        }
        $isPhysicalEducation = strcasecmp(trim($category), 'Physical Education') === 0;
        if ($isPhysicalEducation && $ageGroup === 3) {
            return $age >= 14 && $age < 17;
        }
        if ($ageGroup === 4) {
            return $age >= ($isPhysicalEducation ? 17 : 16);
        }
        return true;
    }

    public function context(int $id, int $actorId, string $role): array
    {
        $row = $this->db->execute(
            "SELECT s.*, r.user_id AS school_id, r.conventionseason_id AS registration_season_id,
             r.season_year - student.birth_year AS eligibility_age
             FROM conventionregistrationstudents s
             JOIN conventionregistrations r ON r.id = s.conventionregistration_id
             JOIN users student ON student.id = s.student_id
             JOIN users school ON school.id = r.user_id
             WHERE s.id = ? AND s.status = 1 AND r.status = 1 AND s.user_id = r.user_id
             AND student.user_type = 'Student' AND student.school_id = r.user_id
             AND student.status = 1 AND student.activation_status = 1
             AND school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1", [$id]
        )->fetch('assoc');
        if (!$row || !in_array($role, ['School', 'Student', 'Teacher_Parent'], true)) {
            throw new DomainException('Active student event registration not found in your school.');
        }
        $actor = $this->db->execute(
            'SELECT id, user_type, school_id FROM users WHERE id = ? AND status = 1 AND activation_status = 1', [$actorId]
        )->fetch('assoc');
        if (!$actor || $actor['user_type'] !== $role
            || ($role === 'School' && (int)$row['school_id'] !== $actorId)
            || ($role === 'Student' && (int)$row['student_id'] !== $actorId)
            || ($role === 'Teacher_Parent' && ((int)$actor['school_id'] !== (int)$row['school_id']
                || (int)$row['teacher_parent_id'] !== $actorId))) {
            throw new DomainException('You can only access your own school student event registrations.');
        }
        return $row;
    }

    public function studentLocked(array $registration): bool
    {
        $review = $this->review((int)$registration['id']);
        return $review ? $review['status'] === 'approved' : trim((string)$registration['event_ids']) !== '';
    }

    public function assertUploadAllowed(int $registrationId, int $eventId, int $studentId): void
    {
        $row = $this->context($registrationId, $studentId, 'Student');
        $review = $this->review($registrationId);
        if (($review && $review['status'] !== 'approved')
            || !in_array($eventId, $this->ids(explode(',', (string)$row['event_ids'])), true)) {
            throw new DomainException('Your school must approve your event choices before you can upload submissions.');
        }
        if (!$this->db->execute(
            'SELECT id FROM crstudentevents WHERE conventionregistration_id = ? AND student_id = ? AND event_id = ?',
            [$row['conventionregistration_id'], $studentId, $eventId]
        )->fetch('assoc')) {
            throw new DomainException('This event is not registered for you.');
        }
    }

    public function save(
        int $id,
        int $actorId,
        string $role,
        array $requested,
        array $allowedIds,
        array $limits,
        callable $autoSubmit,
        ?int $approvalRevision = null
    ): string {
        return $this->db->transactional(function () use ($id, $actorId, $role, $requested, $allowedIds, $limits, $autoSubmit, $approvalRevision) {
            $this->db->execute('SELECT id FROM conventionregistrationstudents WHERE id = ? FOR UPDATE', [$id])->fetch('assoc');
            $registration = $this->context($id, $actorId, $role);
            $review = $this->review($id);
            if ($approvalRevision !== null) {
                if ($role !== 'School' || !$review || $review['status'] !== 'pending'
                    || (int)$review['revision'] !== $approvalRevision) {
                    throw new DomainException('The selection has changed or was already reviewed. Reload before approving.');
                }
                $requested = explode(',', $review['event_ids']);
            } else {
                if ($role === 'Student' && $this->studentLocked($registration)) {
                    throw new DomainException('Your choices are approved and locked. Ask your school or assigned supervisor to make changes.');
                }
                $open = $this->db->execute(
                    'SELECT id FROM conventionseasons WHERE id = ? AND registration_start_date <= NOW() AND registration_end_date >= NOW()',
                    [$registration['registration_season_id']]
                )->fetch('assoc');
                if (!$open) {
                    throw new DomainException('Event registration is closed.');
                }
            }
            $ids = $this->ids($requested);
            if (count($ids) < (int)$limits['min_events_student'] || count($ids) > (int)$limits['max_events_student']) {
                throw new DomainException('Select between ' . (int)$limits['min_events_student'] . ' and ' . (int)$limits['max_events_student'] . ' events.');
            }
            if (array_diff($ids, array_map('intval', $allowedIds))) {
                throw new DomainException('One or more events are unavailable for this convention, age or gender. No changes were saved.');
            }
            $events = [];
            $mediaCounts = [];
            if ($ids) {
                $events = $this->db->execute(
                    'SELECT e.*, d.name AS division_name, c.name AS category_name
                     FROM events e JOIN divisions d ON d.id = e.division_id
                     JOIN eventcategories c ON c.id = d.eventcategory_id
                     WHERE e.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids
                )->fetchAll('assoc');
            }
            if (count($events) !== count($ids)) {
                throw new DomainException('One or more events no longer exist.');
            }
            foreach ($events as $event) {
                if (!self::allowsIndividualAge((int)$registration['eligibility_age'], (int)$event['event_grp_name'],
                    (int)$event['group_event_yes_no'] === 1, (string)$event['category_name'])) {
                    throw new DomainException('Individual event age requirements are not met. PE U17 requires ages 14-16; Open requires age 17 for PE or 16 for other divisions, in the convention year.');
                }
                $name = strtoupper(trim($event['division_name']));
                if (in_array($name, ['PHOTOGRAPHY', 'DESIGN AND TECHNOLOGY', 'DESIGN & TECHNOLOGY'], true)) {
                    $mediaCounts[$name] = ($mediaCounts[$name] ?? 0) + 1;
                }
            }
            if (array_sum($mediaCounts) > 5 || ($mediaCounts && max($mediaCounts) > 3)) {
                throw new DomainException('Media Arts allows five events combined and three per division.');
            }
            $required = (new CombinedTeams($this->db))->requiredEvents((int)$registration['conventionregistration_id'], (int)$registration['student_id']);
            if (array_diff($required, $ids)) {
                throw new DomainException('Events in pending or approved combined teams cannot be removed individually.');
            }
            $pending = $role === 'Student';
            $csv = implode(',', $ids);
            if (!$pending) {
                $this->apply($registration, $events, $autoSubmit);
            }
            $this->db->execute(
                'INSERT INTO student_event_reviews (student_registration_id,status,event_ids,revision,submitted,approved,approved_by)
                 VALUES (?,?,?,1,NOW(),?,?)
                 ON DUPLICATE KEY UPDATE status=VALUES(status),event_ids=VALUES(event_ids),revision=revision+1,
                 submitted=NOW(),approved=VALUES(approved),approved_by=VALUES(approved_by)',
                [$id, $pending ? 'pending' : 'approved', $csv, $pending ? null : date('Y-m-d H:i:s'), $pending ? null : $actorId]
            );
            return $pending ? 'pending' : 'approved';
        });
    }

    private function apply(array $registration, array $events, callable $autoSubmit): void
    {
        $registrationId = (int)$registration['conventionregistration_id'];
        $studentId = (int)$registration['student_id'];
        $ids = array_map(static fn($event) => (int)$event['id'], $events);
        $existing = $this->db->execute(
            'SELECT id,event_id FROM crstudentevents WHERE conventionregistration_id = ? AND student_id = ?', [$registrationId, $studentId]
        )->fetchAll('assoc');
        foreach ($existing as $row) {
            if (!in_array((int)$row['event_id'], $ids, true)) {
                $this->db->execute('DELETE FROM crstudentevents WHERE id = ?', [$row['id']]);
                $this->db->execute(
                    'DELETE FROM eventsubmissions WHERE conventionregistration_id = ? AND student_id = ? AND event_id = ? AND combined_team_id IS NULL',
                    [$registrationId, $studentId, $row['event_id']]
                );
            }
        }
        foreach ($events as $event) {
            if (!in_array((int)$event['id'], array_map(static fn($row) => (int)$row['event_id'], $existing), true)) {
                $this->db->execute(
                    'INSERT INTO crstudentevents (slug,conventionregistration_id,conventionseason_id,convention_id,user_id,season_id,season_year,student_id,event_id,event_id_number,created)
                     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
                    ['student-event-' . bin2hex(random_bytes(12)), $registrationId, $registration['registration_season_id'], $registration['convention_id'],
                        $registration['school_id'], $registration['season_id'], $registration['season_year'], $studentId, $event['id'], $event['event_id_number']]
                );
                if ((int)$event['auto_submission'] === 1 && (int)$event['group_event_yes_no'] === 0) {
                    if ($autoSubmit(['event_id' => (int)$event['id'], 'conventionregistration_id' => $registrationId, 'student_id' => $studentId]) !== true) {
                        throw new RuntimeException('Automatic event submission could not be saved.');
                    }
                }
            }
        }
        $this->db->execute('UPDATE conventionregistrationstudents SET event_ids = ?,modified = NOW() WHERE id = ?', [implode(',', $ids), $registration['id']]);
    }

    private function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }
            if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value <= 0) {
                throw new DomainException('Invalid event selection.');
            }
            $ids[] = (int)$value;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }
}
