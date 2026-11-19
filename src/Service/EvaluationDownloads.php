<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;
use DomainException;
use RuntimeException;

final class EvaluationDownloads
{
    public const BATCH_SIZE = 10;

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function requireStorage(): void
    {
        foreach (['evaluation_download_batches', 'evaluation_download_completions'] as $name) {
            $table = $this->db->execute('SHOW TABLE STATUS WHERE Name = ?', [$name])->fetch('assoc');
            if (!$table) {
                throw new DomainException('Evaluation downloads require migration 018. Please contact the events team.');
            }
            if (strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
                throw new RuntimeException('Evaluation download tracking requires InnoDB storage.');
            }
        }
    }

    public function roster(int $registrationId): array
    {
        $this->requireStorage();
        return $this->db->execute(<<<'SQL'
SELECT s.id, s.slug, u.first_name, u.middle_name, u.last_name, c.completed,
    EXISTS (
        SELECT 1 FROM events e
        JOIN judgeevaluations j ON j.event_id=e.id AND j.conventionregistration_id=s.conventionregistration_id
        WHERE FIND_IN_SET(e.id, REPLACE(s.event_ids, ' ', '')) > 0
        AND (
            (e.group_event_yes_no=0 AND j.student_id=s.student_id)
            OR (e.group_event_yes_no<>0 AND EXISTS (
                SELECT 1 FROM crstudentevents g
                WHERE g.conventionregistration_id=s.conventionregistration_id
                AND g.student_id=s.student_id AND g.event_id=e.id
                AND g.group_name<>'' AND g.group_name=j.group_name
            ))
        )
    ) AS has_evaluations
FROM conventionregistrationstudents s
JOIN users u ON u.id=s.student_id
LEFT JOIN evaluation_download_completions c ON c.registration_id=s.conventionregistration_id
    AND c.registration_student_id=s.id
WHERE s.conventionregistration_id=?
ORDER BY u.first_name, u.middle_name, u.last_name, s.id
SQL, [$registrationId])->fetchAll('assoc');
    }

    public function select(int $registrationId, mixed $ids): array
    {
        if (!is_array($ids) || count($ids) < 1 || count($ids) > self::BATCH_SIZE) {
            throw new DomainException('Select between 1 and 10 students.');
        }
        $selected = [];
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]*\z/', (string)$id)
                || isset($selected[(int)$id])) {
                throw new DomainException('Select distinct, valid students.');
            }
            $selected[(int)$id] = true;
        }
        $students = array_values(array_filter($this->roster($registrationId),
            static fn(array $student): bool => isset($selected[(int)$student['id']])));
        if (count($students) !== count($ids)) {
            throw new DomainException('One or more selected students are not in this convention registration.');
        }
        foreach ($students as $student) {
            if (!(bool)$student['has_evaluations']) {
                throw new DomainException('One or more selected students have no evaluation forms available.');
            }
        }
        return $students;
    }

    public function prepared(int $schoolId, int $registrationId, array $ids): string
    {
        $this->select($registrationId, $ids);
        $token = ResultExportToken::create();
        $this->db->execute(
            'INSERT INTO evaluation_download_batches (token,school_id,registration_id,student_ids,created,expires)
             VALUES (?,?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL 1 HOUR))',
            [$token, $schoolId, $registrationId, json_encode(array_values($ids), JSON_THROW_ON_ERROR)]
        );
        return $token;
    }

    public function complete(int $schoolId, int $registrationId, string $token): int
    {
        $this->requireStorage();
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new DomainException('Invalid download confirmation.');
        }
        return $this->db->transactional(function () use ($schoolId, $registrationId, $token): int {
            $batch = $this->db->execute(
                'SELECT * FROM evaluation_download_batches
                 WHERE token=? AND school_id=? AND registration_id=? AND (expires>NOW() OR received IS NOT NULL)
                 FOR UPDATE',
                [$token, $schoolId, $registrationId]
            )->fetch('assoc');
            if (!$batch) {
                throw new DomainException('This download confirmation has expired or belongs to another registration. Download the batch again.');
            }
            $ids = json_decode($batch['student_ids'], true, 512, JSON_THROW_ON_ERROR);
            if ($batch['received'] !== null) {
                return count($ids);
            }
            $this->select($registrationId, $ids);
            foreach ($ids as $id) {
                $this->db->execute(
                    'INSERT INTO evaluation_download_completions (registration_id,registration_student_id,completed,batch_token)
                     VALUES (?,?,NOW(),?) ON DUPLICATE KEY UPDATE completed=NOW(), batch_token=VALUES(batch_token)',
                    [$registrationId, $id, $token]
                );
            }
            $this->db->execute('UPDATE evaluation_download_batches SET received=NOW() WHERE token=?', [$token]);
            return count($ids);
        });
    }
}
