<?php
declare(strict_types=1);

namespace App\Service;

use App\Deployment\Environment;
use Cake\Datasource\ConnectionInterface;
use App\Mailer\PortalMailer as Mailer;
use Cake\Mailer\Transport\SmtpTransport;
use Cake\Utility\Security;
use DomainException;
use RuntimeException;

final class PasswordReset
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function configuration(): array
    {
        $config = Environment::settings()['passwordResetMail'] ?? null;
        if (Environment::settings()['environment'] === 'local') {
            $path = getenv('ACP_PASSWORD_RESET_CONFIG');
            if (is_string($path) && $path !== '') {
                $file = realpath($path);
                if ($file === false || !is_file($file) || is_link($path)
                    || str_starts_with($file, ROOT . DIRECTORY_SEPARATOR)
                    || (fileperms($file) & 0027) !== 0) {
                    throw new RuntimeException('Password reset email configuration must be a protected file outside the repository.');
                }
                $config = require $file;
            } else {
                $localFile = ROOT . '/config/password_reset.local.php';
                if (is_file($localFile) && !is_link($localFile) && (fileperms($localFile) & 0077) === 0) {
                    $config = require $localFile;
                }
            }
        }
        if (!is_array($config) || ($config['enabled'] ?? false) !== true) {
            throw new RuntimeException('Password reset email is not configured.');
        }
        foreach (['host', 'username', 'password', 'sender', 'notify'] as $key) {
            if (!is_string($config[$key] ?? null) || trim($config[$key]) === '') {
                throw new RuntimeException('Password reset email configuration is incomplete.');
            }
        }
        if (!is_int($config['port'] ?? null) || $config['port'] < 1 || $config['port'] > 65535
            || filter_var($config['sender'], FILTER_VALIDATE_EMAIL) === false
            || filter_var($config['notify'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Password reset email configuration is invalid.');
        }
        if (Environment::settings()['environment'] === 'local') {
            if (!is_array($config['allowedRecipients'] ?? null) || !$config['allowedRecipients']) {
                throw new RuntimeException('Local password reset requires an explicit test-recipient allowlist.');
            }
            foreach ($config['allowedRecipients'] as $recipient) {
                if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException('Local password reset test recipients are invalid.');
                }
            }
            if (!in_array(strtolower($config['notify']), array_map('strtolower', $config['allowedRecipients']), true)) {
                throw new RuntimeException('The local notification recipient must be an approved test recipient.');
            }
        }
        foreach (['password_reset_tokens', 'password_reset_rate_limits'] as $name) {
            $table = $this->connection->execute('SHOW TABLE STATUS WHERE Name = ?', [$name])->fetch('assoc');
            if (!$table || strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
                throw new RuntimeException('Password reset storage is unavailable or not transactional.');
            }
        }
        return $config;
    }

    public function recipientAllowed(array $config, string $address): bool
    {
        if (Environment::settings()['environment'] !== 'local') {
            return true;
        }
        return ($config['allowAllEligibleAccounts'] ?? false) === true
            || in_array(strtolower($address), array_map('strtolower', $config['allowedRecipients'] ?? []), true);
    }

    public function allowRequest(string $ip): bool
    {
        $subject = hash_hmac('sha256', 'reset-ip:' . $ip, Security::getSalt());
        $this->connection->execute(
            'INSERT INTO password_reset_rate_limits (subject, window_start, attempts) VALUES (?, NOW(), 1)
             ON DUPLICATE KEY UPDATE attempts = IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, attempts + 1),
             window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), window_start)',
            [$subject]
        );
        return (int)$this->connection->execute(
            'SELECT attempts FROM password_reset_rate_limits WHERE subject = ?', [$subject]
        )->fetchColumn(0) <= 5;
    }

    public function issue(int $userId, string $address): ?string
    {
        return $this->connection->transactional(function () use ($userId, $address) {
            $row = $this->connection->execute(
                'SELECT created FROM password_reset_tokens WHERE user_id = ? FOR UPDATE', [$userId]
            )->fetch('assoc');
            if ($row && strtotime((string)$row['created']) > time() - 60) {
                return null;
            }
            $credential = $this->connection->execute("SELECT COALESCE(password, '') FROM users WHERE id = ?", [$userId])->fetchColumn(0);
            if (!is_string($credential)) {
                throw new RuntimeException('The account no longer exists.');
            }
            $token = bin2hex(random_bytes(32));
            $this->connection->execute(
                'INSERT INTO password_reset_tokens (user_id, token_hash, email_address, credential_hash, expires, created, consumed)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW(), NULL)
                 ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), email_address = VALUES(email_address),
                 credential_hash = VALUES(credential_hash), expires = VALUES(expires), created = VALUES(created), consumed = NULL',
                [$userId, hash('sha256', $token), $address, hash('sha256', $credential)]
            );
            return $token;
        });
    }

    public function revoke(int $userId, string $token): void
    {
        $this->connection->execute(
            'UPDATE password_reset_tokens SET consumed = NOW() WHERE user_id = ? AND token_hash = ? AND consumed IS NULL',
            [$userId, hash('sha256', $token)]
        );
    }

    public function consume(string $token, string $password): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new DomainException('The reset link is invalid or has expired.');
        }
        if (mb_strlen($password, 'UTF-8') < 6 || strlen($password) > 72) {
            throw new DomainException('Choose a password of at least 6 characters and no more than 72 bytes.');
        }
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        if (!is_string($newHash)) {
            throw new RuntimeException('Unable to secure the new password.');
        }
        $hash = hash('sha256', $token);
        $user = $this->connection->transactional(function () use ($hash) {
            $row = $this->connection->execute(
                "SELECT t.user_id, t.email_address, t.credential_hash FROM password_reset_tokens t
                 JOIN users u ON u.id = t.user_id AND u.email_address = t.email_address AND SHA2(COALESCE(u.password, ''), 256) = t.credential_hash
                 WHERE t.token_hash = ? AND t.consumed IS NULL AND t.expires > NOW()
                   AND u.status = 1 AND u.activation_status = 1
                   AND u.user_type IN ('School', 'Teacher_Parent', 'Judge') FOR UPDATE",
                [$hash]
            )->fetch('assoc');
            if (!$row) {
                throw new DomainException('The reset link is invalid or has expired.');
            }
            $this->connection->execute('UPDATE password_reset_tokens SET consumed = NOW() WHERE token_hash = ?', [$hash]);
            return $row;
        });
        $updated = $this->connection->execute(
            "UPDATE users SET password = ?, forgot_password_status = 0 WHERE id = ? AND email_address = ?
             AND status = 1 AND activation_status = 1 AND user_type IN ('School', 'Teacher_Parent', 'Judge')
             AND SHA2(COALESCE(password, ''), 256) = ?",
            [$newHash, $user['user_id'], $user['email_address'], $user['credential_hash']]
        )->rowCount();
        if ($updated !== 1) {
            throw new RuntimeException('Password reset could not be completed. Request a new link.');
        }
    }

    public function send(array $config, string $to, string $subject, array $viewVars): void
    {
        if (!$this->recipientAllowed($config, $to)) {
            throw new RuntimeException('Password reset email is restricted to approved local test recipients.');
        }
        $transport = new SmtpTransport([
            'host' => $config['host'], 'port' => $config['port'],
            'username' => $config['username'], 'password' => $config['password'],
            'tls' => true, 'timeout' => 15,
        ]);
        $mailer = new Mailer();
        $mailer->viewBuilder()->setTemplate('password_reset')->setLayout('password_reset');
        $mailer->setViewVars($viewVars + [
            'notification' => false,
            'baseUrl' => rtrim(HTTP_PATH, '/'),
            'contactEmail' => $config['notify'],
            'localTest' => Environment::settings()['environment'] === 'local',
            'subject' => $subject,
        ]);
        $mailer->setTransport($transport)->setFrom([$config['sender'] => 'Accelerate Educational Ministries'])->setTo($to)
            ->setEmailFormat('both')->setSubject($subject)->deliver();
    }
}
