<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;
use Cake\Utility\Security;
use DomainException;
use RuntimeException;

final class StudentPortalAccounts
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const INVALID = 'Invalid student ID, password or setup code. Ask your school for help.';

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function ensure(int $studentId): array
    {
        $existing = $this->account($studentId);
        if ($existing) {
            return $existing;
        }
        if (!$this->db->execute("SELECT id FROM users WHERE id = ? AND user_type = 'Student' AND school_id > 0", [$studentId])->fetch('assoc')) {
            throw new DomainException('A student in a school Global Students List is required.');
        }
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $id = $this->randomCode(6);
            try {
                $this->db->execute('INSERT INTO student_portal_accounts (student_id,login_id) VALUES (?,?)', [$studentId, $id]);
                return $this->account($studentId);
            } catch (\Cake\Database\Exception\QueryException $error) {
                // Retry only confirmed duplicate-ID allocation races, never other storage failures.
                $existing = $this->account($studentId);
                if ($existing) {
                    return $existing;
                }
                if (!$this->db->execute('SELECT student_id FROM student_portal_accounts WHERE login_id = ?', [$id])->fetch('assoc')) {
                    throw $error;
                }
            }
        }
        throw new RuntimeException('Unable to allocate a unique student ID.');
    }

    public function account(int $studentId): ?array
    {
        $row = $this->db->execute('SELECT * FROM student_portal_accounts WHERE student_id = ?', [$studentId])->fetch('assoc');
        return $row ?: null;
    }

    public function issue(int $studentId, int $schoolId): array
    {
        $this->eligible($studentId, $schoolId);
        $this->ensure($studentId);
        return $this->db->transactional(function () use ($studentId, $schoolId) {
            $row = $this->lockedAccount($studentId);
            return $this->issueLocked($studentId, $schoolId, $row);
        });
    }

    public function bulkStudents(int $schoolId, array $ids, bool $withoutPassword): array
    {
        $params = [$schoolId];
        $where = '';
        if ($withoutPassword) {
            $where = ' AND p.password_hash IS NULL';
        } else {
            if (!$ids) {
                throw new DomainException('Select at least one active student.');
            }
            foreach ($ids as $id) {
                if (!is_int($id) || $id <= 0) {
                    throw new DomainException('Invalid student selection.');
                }
            }
            $ids = array_values(array_unique($ids));
            $where = ' AND u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $rows = $this->db->execute(
            "SELECT u.id, u.first_name, u.middle_name, u.last_name, p.login_id, p.password_hash IS NOT NULL AS has_password
             FROM users u JOIN users school ON school.id = u.school_id
             LEFT JOIN student_portal_accounts p ON p.student_id = u.id
             WHERE u.school_id = ? AND u.user_type = 'Student' AND u.status = 1 AND u.activation_status = 1
             AND school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1" . $where .
             ' ORDER BY u.id', $params
        )->fetchAll('assoc');
        if (!$withoutPassword && count($rows) !== count($ids)) {
            throw new DomainException('No codes were issued. Select only active, verified students from your own school.');
        }
        if (!$rows) {
            throw new DomainException('No active, verified students without a password were found.');
        }
        return $rows;
    }

    public function issueBulk(int $schoolId, array $ids, bool $withoutPassword): array
    {
        return $this->db->transactional(function () use ($schoolId, $ids, $withoutPassword) {
            $students = $this->bulkStudents($schoolId, $ids, $withoutPassword);
            $issued = [];
            foreach ($students as $student) {
                $id = (int)$student['id'];
                $this->ensure($id);
                $row = $this->lockedAccount($id);
                if ($withoutPassword && $row['password_hash'] !== null) {
                    throw new DomainException('No codes were issued. A student has just created a password. Please review the list again.');
                }
                $code = $this->issueLocked($id, $schoolId, $row);
                $issued[] = [
                    'name' => trim(implode(' ', array_filter([$student['first_name'], $student['middle_name'], $student['last_name']], static fn($part) => $part !== null && $part !== ''))),
                    'login_id' => $code['login_id'],
                    'code' => $code['code'],
                    'expires' => $code['expires'],
                ];
            }
            return $issued;
        });
    }

    private function issueLocked(int $studentId, int $schoolId, array $row): array
    {
        $this->eligible($studentId, $schoolId);
        if ($row['recent_setup']) {
            throw new DomainException('No code changes were saved. Wait one minute before issuing another code for a student in this selection.');
        }
        $code = $this->randomCode(16);
        $this->db->execute(
            'UPDATE student_portal_accounts SET setup_hash = ?, setup_expires = DATE_ADD(NOW(), INTERVAL 7 DAY), setup_issued = NOW() WHERE student_id = ?',
            [hash('sha256', $code), $studentId]
        );
        $expires = $this->db->execute('SELECT setup_expires FROM student_portal_accounts WHERE student_id = ?', [$studentId])->fetchColumn(0);
        return ['login_id' => $row['login_id'], 'code' => $code, 'expires' => $expires];
    }

    public function allowAttempt(string $ip, string $loginId): bool
    {
        $allowed = true;
        foreach (['ip:' . $ip => 30, 'id:' . strtoupper(trim($loginId)) => 10] as $key => $limit) {
            $subject = hash_hmac('sha256', 'student-portal:' . $key, Security::getSalt());
            $this->db->execute(
                'INSERT INTO student_portal_rate_limits (subject,window_start,attempts) VALUES (?,NOW(),1)
                 ON DUPLICATE KEY UPDATE attempts = IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE),1,attempts + 1),
                 window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE),NOW(),window_start)',
                [$subject]
            );
            $count = (int)$this->db->execute('SELECT attempts FROM student_portal_rate_limits WHERE subject = ?', [$subject])->fetchColumn(0);
            $allowed = $count <= $limit && $allowed;
        }
        return $allowed;
    }

    public function setup(string $loginId, string $code, string $password, string $confirmation): void
    {
        $this->validatePassword($password, $confirmation);
        $loginId = strtoupper(trim($loginId));
        $code = strtoupper(trim($code));
        if (!preg_match('/\A[2-9A-HJ-NP-Z]{6}\z/', $loginId) || !preg_match('/\A[2-9A-HJ-NP-Z]{16}\z/', $code)) {
            throw new DomainException(self::INVALID);
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->db->transactional(function () use ($loginId, $code, $hash) {
            $row = $this->db->execute('SELECT *, setup_expires > NOW() AS setup_valid FROM student_portal_accounts WHERE login_id = ? FOR UPDATE', [$loginId])->fetch('assoc');
            if (!$row || !$row['setup_hash'] || !$row['setup_valid']
                || !hash_equals($row['setup_hash'], hash('sha256', $code))) {
                throw new DomainException(self::INVALID);
            }
            $this->eligible((int)$row['student_id']);
            $this->db->execute(
                'UPDATE student_portal_accounts SET password_hash = ?, setup_hash = NULL, setup_expires = NULL, credential_version = credential_version + 1 WHERE student_id = ?',
                [$hash, $row['student_id']]
            );
        });
    }

    public function authenticate(string $loginId, string $password): array
    {
        $loginId = strtoupper(trim($loginId));
        return $this->db->transactional(function () use ($loginId, $password) {
            $row = $this->db->execute('SELECT * FROM student_portal_accounts WHERE login_id = ? FOR UPDATE', [$loginId])->fetch('assoc');
            $hash = $row['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
            $valid = strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $hash);
            if (!$row || !$row['password_hash'] || !$valid) {
                throw new DomainException(self::INVALID);
            }
            $this->eligible((int)$row['student_id']);
            $this->db->execute('UPDATE student_portal_accounts SET first_login = COALESCE(first_login,NOW()) WHERE student_id = ?', [$row['student_id']]);
            return $row;
        });
    }

    public function changePassword(int $studentId, string $old, string $password, string $confirmation): int
    {
        $this->validatePassword($password, $confirmation);
        return $this->db->transactional(function () use ($studentId, $old, $password) {
            $row = $this->lockedAccount($studentId);
            $this->eligible($studentId);
            if (!$row['password_hash'] || strlen($old) > 72 || str_contains($old, "\0") || !password_verify($old, $row['password_hash'])) {
                throw new DomainException('Old password is not correct.');
            }
            if (password_verify($password, $row['password_hash'])) {
                throw new DomainException('Choose a different new password.');
            }
            $this->db->execute(
                'UPDATE student_portal_accounts SET password_hash = ?, setup_hash = NULL, setup_expires = NULL, credential_version = credential_version + 1 WHERE student_id = ?',
                [password_hash($password, PASSWORD_BCRYPT), $studentId]
            );
            return (int)$row['credential_version'] + 1;
        });
    }

    public function schoolAccessValid(int $studentId, int $schoolId): bool
    {
        if ($studentId <= 0 || $schoolId <= 0) {
            return false;
        }
        try {
            $this->eligible($studentId, $schoolId);
        } catch (DomainException) {
            return false;
        }
        return true;
    }

    public function sessionValid(int $studentId, int $version): bool
    {
        $row = $this->account($studentId);
        if (!$row || !$row['first_login'] || !$row['password_hash'] || $version < 1 || (int)$row['credential_version'] !== $version) {
            return false;
        }
        try {
            $this->eligible($studentId);
        } catch (DomainException) {
            return false;
        }
        return true;
    }

    private function eligible(int $studentId, ?int $schoolId = null): void
    {
        $row = $this->db->execute(
            "SELECT u.school_id FROM users u JOIN users school ON school.id = u.school_id
             WHERE u.id = ? AND u.user_type = 'Student' AND u.status = 1 AND u.activation_status = 1
             AND school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1", [$studentId]
        )->fetch('assoc');
        if (!$row || ($schoolId !== null && (int)$row['school_id'] !== $schoolId)) {
            throw new DomainException('An active student in your school Global Students List and an active school account are required.');
        }
    }

    private function lockedAccount(int $studentId): array
    {
        $row = $this->db->execute('SELECT *, setup_issued > DATE_SUB(NOW(), INTERVAL 1 MINUTE) AS recent_setup FROM student_portal_accounts WHERE student_id = ? FOR UPDATE', [$studentId])->fetch('assoc');
        if (!$row) {
            throw new DomainException('Student portal account not found. Contact the events team.');
        }
        return $row;
    }

    private function validatePassword(string $password, string $confirmation): void
    {
        if (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")
            || mb_strlen($password, 'UTF-8') < 6 || strlen($password) > 72 || $password !== $confirmation) {
            throw new DomainException('Enter matching passwords of at least 6 characters and no more than 72 bytes.');
        }
    }

    private function randomCode(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $code;
    }
}
