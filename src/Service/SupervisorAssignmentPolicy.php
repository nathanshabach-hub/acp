<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;
use Cake\ORM\TableRegistry;
use DomainException;

final class SupervisorAssignmentPolicy
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * The callback must perform the assignment while the registration row remains locked.
     *
     * @param list<int> $studentIds
     * @param list<int> $existingAssignmentIds
     */
    public function assign(
        int $supervisorId,
        int $conventionId,
        int $seasonId,
        int $seasonYear,
        array $studentIds,
        array $existingAssignmentIds,
        callable $save
    ): mixed {
        return $this->connection->transactional(function () use (
            $supervisorId, $conventionId, $seasonId, $seasonYear, $studentIds, $existingAssignmentIds, $save
        ) {
            foreach (['conventionregistrations', 'conventionregistrationteachers', 'conventionregistrationstudents'] as $table) {
                $status = $this->connection->execute('SHOW TABLE STATUS WHERE Name = ?', [$table])->fetch('assoc');
                if (!$status || strcasecmp((string)$status['Engine'], 'InnoDB') !== 0) {
                    throw new DomainException('Student assignments are unavailable until the registration tables support transactional locking. Please contact the events team.');
                }
            }
            $registration = $this->connection->execute(
                'SELECT id FROM conventionregistrationteachers WHERE teacher_id = ? AND convention_id = ? AND season_id = ? AND season_year = ? AND status = 1 ORDER BY id LIMIT 1 FOR UPDATE',
                [$supervisorId, $conventionId, $seasonId, $seasonYear]
            )->fetch('assoc');
            if (!$registration) {
                throw new DomainException('The supervisor is not registered for this convention.');
            }
            $supervisor = $this->connection->execute(
                'SELECT id, gender FROM users WHERE id = ? AND user_type = ?',
                [$supervisorId, 'Teacher_Parent']
            )->fetch('assoc');
            if (!$supervisor) {
                throw new DomainException('The selected supervisor was not found.');
            }
            $gender = $this->gender((string)$supervisor['gender']);
            if ($gender === null) {
                throw new DomainException('The supervisor must have a valid male or female gender before students can be assigned.');
            }

            $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
            if (!$studentIds || in_array(0, $studentIds, true)) {
                throw new DomainException('Select at least one valid student.');
            }
            $users = TableRegistry::getTableLocator()->get('Users');
            $students = $users->find()->where(['Users.id IN' => $studentIds, 'Users.user_type' => 'Student'])->all();
            if ($students->count() !== count($studentIds)) {
                throw new DomainException('One or more selected students could not be found.');
            }
            foreach ($students as $student) {
                $studentGender = $this->gender((string)$student->gender);
                if ($studentGender === null) {
                    throw new DomainException('Each student must have a valid male or female gender before assignment.');
                }
                if ($studentGender !== $gender) {
                    throw new DomainException('Students and their supervisor must have matching genders. The parent-child exception is not available yet.');
                }
            }

            $rows = $this->connection->execute(
                'SELECT id FROM conventionregistrationstudents WHERE teacher_parent_id = ? AND convention_id = ? AND season_id = ? AND season_year = ? AND status = 1 FOR UPDATE',
                [$supervisorId, $conventionId, $seasonId, $seasonYear]
            )->fetchAll('assoc');
            $count = 0;
            foreach ($rows as $row) {
                if (!in_array((int)$row['id'], $existingAssignmentIds, true)) {
                    $count++;
                }
            }
            if ($count + count($studentIds) > 10) {
                throw new DomainException('A supervisor can be assigned no more than 10 students for this convention. Currently assigned: ' . $count . '.');
            }
            $result = $save();
            if ($result !== false) {
                (new SupervisorAgreements($this->connection))->ensure($supervisorId, $conventionId, $seasonId, $seasonYear);
            }
            return $result;
        });
    }

    /** @param list<int> $assignmentIds */
    public function activate(array $assignmentIds, ?int $schoolId = null, ?int $registrationId = null): void
    {
        $assignmentIds = array_values(array_unique(array_filter(array_map('intval', $assignmentIds), fn($id) => $id > 0)));
        sort($assignmentIds);
        if (!$assignmentIds) {
            throw new DomainException('Select student registrations to activate.');
        }
        $this->connection->transactional(function () use ($assignmentIds, $schoolId, $registrationId) {
            foreach ($assignmentIds as $id) {
                $row = $this->connection->execute(
                    'SELECT * FROM conventionregistrationstudents WHERE id = ? FOR UPDATE', [$id]
                )->fetch('assoc');
                if (!$row || ($schoolId !== null && (int)$row['user_id'] !== $schoolId)
                    || ($registrationId !== null && (int)$row['conventionregistration_id'] !== $registrationId)) {
                    throw new DomainException('A selected student registration could not be found.');
                }
                if ((int)$row['status'] === 1) {
                    continue;
                }
                $this->assign((int)$row['teacher_parent_id'], (int)$row['convention_id'], (int)$row['season_id'],
                    (int)$row['season_year'], [(int)$row['student_id']], [], function () use ($id) {
                        $this->connection->execute('UPDATE conventionregistrationstudents SET status = 1 WHERE id = ?', [$id]);
                    });
            }
        });
    }

    /** @param list<int|string> $assignmentIds */
    public function unassign(array $assignmentIds, int $schoolId, int $registrationId): int
    {
        if (!$assignmentIds || $schoolId <= 0 || $registrationId <= 0) {
            throw new DomainException('Select at least one student from your convention registration.');
        }
        foreach ($assignmentIds as $id) {
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id <= 0) {
                throw new DomainException('Select valid student registrations.');
            }
        }
        $assignmentIds = array_values(array_unique(array_map('intval', $assignmentIds)));
        sort($assignmentIds);
        foreach (['conventionregistrations', 'conventionregistrationstudents'] as $table) {
            $status = $this->connection->execute('SHOW TABLE STATUS WHERE Name = ?', [$table])->fetch('assoc');
            if (!$status || strcasecmp((string)$status['Engine'], 'InnoDB') !== 0) {
                throw new DomainException('Student assignments require transactional storage. Please contact the events team.');
            }
        }
        return $this->connection->transactional(function () use ($assignmentIds, $schoolId, $registrationId): int {
            $registration = $this->connection->execute(
                'SELECT id FROM conventionregistrations WHERE id=? AND user_id=? FOR UPDATE',
                [$registrationId, $schoolId]
            )->fetch('assoc');
            if (!$registration) {
                throw new DomainException('Choose a convention registration for your own school.');
            }
            $changed = [];
            foreach ($assignmentIds as $id) {
                $row = $this->connection->execute(
                    'SELECT teacher_parent_id FROM conventionregistrationstudents
                     WHERE id=? AND user_id=? AND conventionregistration_id=? FOR UPDATE',
                    [$id, $schoolId, $registrationId]
                )->fetch('assoc');
                if (!$row) {
                    throw new DomainException('A selected student is not in this school and convention registration. Nothing was changed.');
                }
                if ((int)$row['teacher_parent_id'] > 0) {
                    $changed[] = $id;
                }
            }
            if ((new CrossSchoolSponsorship($this->connection))->hasPending($assignmentIds)) {
                throw new DomainException('Cancel pending cross-school sponsorship requests before removing these supervisor assignments. Nothing was changed.');
            }
            foreach ($changed as $id) {
                $this->connection->execute(
                    'UPDATE conventionregistrationstudents SET teacher_parent_id=0
                     WHERE id=? AND user_id=? AND conventionregistration_id=?',
                    [$id, $schoolId, $registrationId]
                );
            }
            return count($changed);
        });
    }

    private function gender(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'm', 'male' => 'male',
            'f', 'female' => 'female',
            default => null,
        };
    }
}
