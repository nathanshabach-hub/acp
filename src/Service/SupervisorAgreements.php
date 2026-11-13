<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;
use DomainException;

final class SupervisorAgreements
{
    public const VERSION = 1;
    public const DECLARATIONS = [
        'I have read the SPONSORS information page and agree to abide by and support these standards.',
        'I hereby accept responsibility for the supervision of the named student(s) and will ensure that my student(s) abide by the Convention Guidelines at all times during this Convention.',
        'I have obtained the relevant medical information for the student(s) I am sponsoring, and have it at Student Convention, either in my possession or in the possession of my school\'s First Aid Officer.',
        'I understand that I am responsible for the medical care of the student(s) that I am sponsoring, and have any prescription medications that my student(s) may require.',
    ];

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function available(): bool
    {
        $table = $this->db->execute("SHOW TABLE STATUS WHERE Name='supervisor_agreements'")->fetch('assoc');
        if ($table && strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
            throw new \RuntimeException('Supervisor agreements require InnoDB storage.');
        }
        return (bool)$table;
    }

    private function requireStorage(): void
    {
        if (!$this->available() || !(new SchoolNotifications($this->db))->available()) {
            throw new DomainException('Supervisor agreements are unavailable until migrations 012 and 014 are applied. Please contact the events team.');
        }
    }

    public static function protects(string $controller, string $action): bool
    {
        $controller = strtolower($controller);
        $action = strtolower($action);
        return in_array($controller, ['groups', 'heartevents', 'combinerequests'], true)
            || ($controller === 'eventsubmissions' && in_array($action, [
                'viewlist', 'submitnewevent', 'removesubmission', 'submitstudentevent', 'submitgroupevent',
            ], true))
            || ($controller === 'conventionregistrations' && in_array($action, [
                'studentevents', 'studenteventlist', 'addstudentevent', 'managestudentevents',
                'reviewstudentevents', 'editstudentevent', 'removestudentevent',
                'packageregistration', 'packageregistrationprint',
            ], true))
            || ($controller === 'homes' && in_array($action, ['eventsubmissions', 'checkstudentevent'], true));
    }

    public function roster(int $supervisorId, int $conventionId, int $seasonId, int $year, bool $lock = false): array
    {
        return $this->db->execute(
            "SELECT s.id, s.student_id, student.first_name, student.last_name
             FROM conventionregistrationstudents s
             JOIN conventionregistrations r ON r.id=s.conventionregistration_id AND r.status=1
             JOIN users student ON student.id=s.student_id AND student.user_type='Student'
             JOIN users supervisor ON supervisor.id=s.teacher_parent_id AND supervisor.user_type='Teacher_Parent'
             WHERE s.teacher_parent_id=? AND s.convention_id=? AND s.season_id=? AND s.season_year=?
             AND s.status=1 AND s.user_id=r.user_id
             AND student.school_id=r.user_id AND supervisor.school_id=r.user_id
             AND r.convention_id=s.convention_id AND r.season_id=s.season_id AND r.season_year=s.season_year
             ORDER BY s.id" . ($lock ? ' FOR UPDATE' : ''),
            [$supervisorId, $conventionId, $seasonId, $year]
        )->fetchAll('assoc');
    }

    public static function rosterHash(array $students): string
    {
        return hash('sha256', json_encode($students, JSON_THROW_ON_ERROR));
    }

    public function ensure(int $supervisorId, int $conventionId, int $seasonId, int $year): void
    {
        if (!$this->roster($supervisorId, $conventionId, $seasonId, $year)) {
            return;
        }
        $this->requireStorage();
        $this->db->transactional(function () use ($supervisorId, $conventionId, $seasonId, $year): void {
            $this->db->execute(
                'INSERT INTO supervisor_agreements (supervisor_id,convention_id,season_id,season_year,created)
                 VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE id=id',
                [$supervisorId, $conventionId, $seasonId, $year]
            );
            $agreement = $this->db->execute(
                'SELECT a.*, c.name AS convention_name FROM supervisor_agreements a
                 JOIN conventions c ON c.id=a.convention_id
                 WHERE a.supervisor_id=? AND a.convention_id=? AND a.season_id=? AND a.season_year=?',
                [$supervisorId, $conventionId, $seasonId, $year]
            )->fetch('assoc');
            if (!$agreement) {
                throw new DomainException('The convention for this supervisor agreement could not be found.');
            }
            if (!$agreement['accepted_at']) {
                (new SchoolNotifications($this->db))->supervisorAgreement($agreement);
            }
        });
    }

    public function forSupervisor(int $supervisorId): array
    {
        $this->requireStorage();
        $rows = $this->db->execute(
            'SELECT a.*, c.name AS convention_name FROM supervisor_agreements a
             JOIN conventions c ON c.id=a.convention_id
             WHERE a.supervisor_id=? ORDER BY a.created DESC,a.id DESC',
            [$supervisorId]
        )->fetchAll('assoc');
        $agreements = [];
        foreach ($rows as $row) {
            $row['students'] = $this->roster($supervisorId, (int)$row['convention_id'], (int)$row['season_id'], (int)$row['season_year']);
            if ($row['students']) {
                $agreements[] = $row;
            }
        }
        return $agreements;
    }

    public function pending(int $supervisorId, int $registrationId): bool
    {
        $context = $this->db->execute(
            "SELECT r.convention_id,r.season_id,r.season_year FROM conventionregistrations r
             JOIN users u ON u.school_id=r.user_id AND u.user_type='Teacher_Parent'
             WHERE r.id=? AND u.id=? AND r.status=1",
            [$registrationId, $supervisorId]
        )->fetch('assoc');
        if (!$context) {
            throw new DomainException('Choose an active convention registration for your own school.');
        }
        if (!$this->roster($supervisorId, (int)$context['convention_id'], (int)$context['season_id'], (int)$context['season_year'])) {
            return false;
        }
        $this->requireStorage();
        return !$this->db->execute(
            'SELECT id FROM supervisor_agreements WHERE supervisor_id=? AND convention_id=? AND season_id=? AND season_year=? AND accepted_at IS NOT NULL',
            [$supervisorId, $context['convention_id'], $context['season_id'], $context['season_year']]
        )->fetch('assoc');
    }

    public function accept(int $supervisorId, int $agreementId, string $rosterHash): void
    {
        $this->requireStorage();
        $this->db->transactional(function () use ($supervisorId, $agreementId, $rosterHash): void {
            $context = $this->db->execute(
                'SELECT * FROM supervisor_agreements WHERE id=? AND supervisor_id=?', [$agreementId, $supervisorId]
            )->fetch('assoc');
            if (!$context) {
                throw new DomainException('Your supervisor agreement could not be found.');
            }
            // Use the same context lock as student assignment before locking the agreement.
            $teacher = $this->db->execute(
                'SELECT id FROM conventionregistrationteachers WHERE teacher_id=? AND convention_id=?
                 AND season_id=? AND season_year=? AND status=1 ORDER BY id LIMIT 1 FOR UPDATE',
                [$supervisorId, $context['convention_id'], $context['season_id'], $context['season_year']]
            )->fetch('assoc');
            $account = $this->db->execute(
                "SELECT first_name,last_name FROM users WHERE id=? AND user_type='Teacher_Parent' AND status=1 AND activation_status=1",
                [$supervisorId]
            )->fetch('assoc');
            if (!$teacher || !$account) {
                throw new DomainException('An active verified supervisor registered for this convention is required.');
            }
            $agreement = $this->db->execute('SELECT * FROM supervisor_agreements WHERE id=? FOR UPDATE', [$agreementId])->fetch('assoc');
            if ($agreement['accepted_at']) {
                return;
            }
            $students = $this->roster($supervisorId, (int)$context['convention_id'], (int)$context['season_id'], (int)$context['season_year'], true);
            if (!$students || !hash_equals(self::rosterHash($students), $rosterHash)) {
                throw new DomainException('Your assigned student list has changed. Review the current list before accepting.');
            }
            if (trim((string)$account['first_name']) === '' || trim((string)$account['last_name']) === '') {
                throw new DomainException('Add your first and last name to your profile before signing.');
            }
            $signature = trim($account['first_name']) . ' ' . trim($account['last_name']);
            $snapshot = json_encode(['declarations' => self::DECLARATIONS, 'students' => $students], JSON_THROW_ON_ERROR);
            $this->db->execute(
                'UPDATE supervisor_agreements SET accepted_at=NOW(),accepted_by=?,signature=?,declaration_version=?,snapshot=?
                 WHERE id=? AND supervisor_id=? AND accepted_at IS NULL',
                [$supervisorId, $signature, self::VERSION, $snapshot, $agreementId, $supervisorId]
            );
        });
    }
}
