<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class SupervisorApprovalPreviewTest extends TestCase
{
    use IntegrationTestTrait;

    public function testLegacySupervisorPasswordLinksCannotChangePassword(): void
    {
        $db = ConnectionManager::get('default');
        $user = $db->execute(
            "SELECT id, email_address, password FROM users WHERE user_type = 'Teacher_Parent' LIMIT 1"
        )->fetch('assoc');
        if (!$user) {
            $this->markTestSkipped('A supervisor account is required.');
        }

        $suffix = rawurlencode($user['email_address']) . '/' . md5($user['email_address']) .
            '/' . $user['id'] . '/' . md5((string)$user['id']);
        foreach (['teachersetpassword', 'resetpassword'] as $action) {
            $this->enableCsrfToken();
            $this->post('/users/' . $action . '/' . $suffix, ['Users' => ['password' => 'not-a-real-password']]);
            $this->assertRedirectContains('/users/login');
            $password = $db->execute('SELECT password FROM users WHERE id = ?', [$user['id']])->fetchColumn(0);
            $this->assertSame($user['password'], $password);
        }
    }

    public function testNotificationsRequireSupervisorLoginAndCannotAcceptApproval(): void
    {
        $db = ConnectionManager::get('default');
        $userId = $db->execute(
            "SELECT id FROM users WHERE user_type = 'Teacher_Parent' AND status = 1 AND activation_status = 1 LIMIT 1"
        )->fetchColumn(0);
        if (!$userId) {
            $this->markTestSkipped('An active supervisor account is required.');
        }

        $this->get('/users/notifications');
        $this->assertRedirectContains('/users/login');
        $this->session(['user_id' => (int)$userId, 'user_type' => 'Teacher_Parent']);
        $this->get('/users/notifications');
        $this->assertResponseOk();
        $this->assertResponseContains('nothing has been signed or submitted');
        $this->assertStringContainsString('no-store', $this->_response->getHeaderLine('Cache-Control'));
        $this->post('/users/notifications', []);
        $this->assertResponseCode(405);
    }

    public function testSchoolCannotPreviewAnotherSchoolsForms(): void
    {
        $db = ConnectionManager::get('default');
        $school = $db->execute(
            "SELECT id FROM users WHERE user_type = 'School' AND status = 1 AND activation_status = 1 LIMIT 1"
        )->fetchColumn(0);
        $registration = $school ? $db->execute(
            'SELECT id FROM conventionregistrations WHERE user_id <> ? LIMIT 1', [$school]
        )->fetchColumn(0) : false;
        if (!$registration) {
            $this->markTestSkipped('Two schools with a registration are required.');
        }

        $this->session([
            'user_id' => (int)$school,
            'user_type' => 'School',
            'sess_selected_convention_registration_id' => (int)$registration,
        ]);
        $this->get('/conventionregistrations/permissionsformadult');
        $this->assertResponseCode(403);
    }
}
