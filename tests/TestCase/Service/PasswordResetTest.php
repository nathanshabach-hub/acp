<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\PasswordReset;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use DomainException;

final class PasswordResetTest extends TestCase
{
    public function testSharedEmailTokensResetOnlyTheirOwnAccount(): void
    {
        $db = ConnectionManager::get('default');
        if (!$db->execute("SHOW TABLES LIKE 'password_reset_tokens'")->fetch('num')) {
            $this->markTestSkipped('Password reset migration is required.');
        }
        $email = bin2hex(random_bytes(12)) . '@example.invalid';
        $ids = [];
        $tokens = [];
        $service = new PasswordReset($db);
        $original = password_hash('original-password', PASSWORD_BCRYPT);
        try {
            for ($i = 0; $i < 2; $i++) {
                $db->execute(
                    "INSERT INTO users (user_type, first_name, email_address, password, status, activation_status)
                     VALUES ('Teacher_Parent', 'PasswordResetSharedEmailFixture', ?, ?, 1, 1)",
                    [$email, $original]
                );
                $id = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
                $ids[] = $id;
                $tokens[] = $service->issue($id, $email);
            }
            $this->assertNotSame($tokens[0], $tokens[1]);
            $service->consume($tokens[0], 'first-account-password');
            $this->assertSame($original, $db->execute('SELECT password FROM users WHERE id = ?', [$ids[1]])->fetchColumn(0));
            $service->consume($tokens[1], 'second-account-password');
            foreach ($ids as $index => $id) {
                $password = $index === 0 ? 'first-account-password' : 'second-account-password';
                $this->assertTrue(password_verify($password, $db->execute('SELECT password FROM users WHERE id = ?', [$id])->fetchColumn(0)));
            }
        } finally {
            foreach ($ids as $id) {
                $db->execute('DELETE FROM password_reset_tokens WHERE user_id = ?', [$id]);
                $db->execute("DELETE FROM users WHERE id = ? AND first_name = 'PasswordResetSharedEmailFixture'", [$id]);
            }
        }
    }

    public function testTokensAreSingleUseAndExpireForAllSupportedRoles(): void
    {
        $db = ConnectionManager::get('default');
        if (!$db->execute("SHOW TABLES LIKE 'password_reset_tokens'")->fetch('num')) {
            $this->markTestSkipped('Password reset migration is required.');
        }
        $ids = [];
        try {
            foreach (['School', 'Teacher_Parent', 'Judge'] as $type) {
                $email = bin2hex(random_bytes(12)) . '@example.invalid';
                $db->execute(
                    'INSERT INTO users (user_type, first_name, email_address, password, status, activation_status) VALUES (?, ?, ?, ?, 1, 1)',
                    [$type, 'PasswordResetTestFixture', $email, password_hash('old-fixture-password', PASSWORD_BCRYPT)]
                );
                $id = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
                $ids[] = $id;
                $service = new PasswordReset($db);
                $token = $service->issue($id, $email);
                $this->assertIsString($token);
                $this->assertNull($service->issue($id, $email));
                foreach (['12345', str_repeat('a', 73)] as $invalidPassword) {
                    try {
                        $service->consume($token, $invalidPassword);
                        $this->fail('A password outside the length limits was accepted.');
                    } catch (DomainException $error) {
                        $this->assertStringContainsString('at least 6 characters', $error->getMessage());
                    }
                }
                $service->consume($token, '123456');
                $hash = $db->execute('SELECT password FROM users WHERE id = ?', [$id])->fetchColumn(0);
                $this->assertTrue(password_verify('123456', $hash));
                try {
                    $service->consume($token, 'another-fixture-password');
                    $this->fail('A consumed token was accepted.');
                } catch (DomainException $error) {
                    $this->assertStringContainsString('invalid or has expired', $error->getMessage());
                }
                $db->execute('UPDATE password_reset_tokens SET created = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE user_id = ?', [$id]);
                $expired = $service->issue($id, $email);
                $db->execute('UPDATE password_reset_tokens SET expires = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE user_id = ?', [$id]);
                try {
                    $service->consume($expired, 'another-fixture-password');
                    $this->fail('An expired token was accepted.');
                } catch (DomainException $error) {
                    $this->assertStringContainsString('invalid or has expired', $error->getMessage());
                }
                $db->execute('UPDATE password_reset_tokens SET created = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE user_id = ?', [$id]);
                $changed = $service->issue($id, $email);
                $db->execute('UPDATE users SET password = ? WHERE id = ?', [password_hash('changed-password-fixture', PASSWORD_BCRYPT), $id]);
                try {
                    $service->consume($changed, 'another-fixture-password');
                    $this->fail('A token issued before a password change was accepted.');
                } catch (DomainException $error) {
                    $this->assertStringContainsString('invalid or has expired', $error->getMessage());
                }
            }
        } finally {
            foreach ($ids as $id) {
                $db->execute('DELETE FROM password_reset_tokens WHERE user_id = ?', [$id]);
                $db->execute('DELETE FROM users WHERE id = ? AND first_name = ?', [$id, 'PasswordResetTestFixture']);
            }
        }
    }
}
