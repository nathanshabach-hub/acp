<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class AccountIdentityProtectionTest extends TestCase
{
    use IntegrationTestTrait;

    public function testSchoolProfileRejectsCredentialChanges(): void
    {
        $school = ConnectionManager::get('default')->execute(
            "SELECT id FROM users WHERE user_type = 'School' AND status = 1 AND activation_status = 1 LIMIT 1"
        )->fetch('assoc');
        if (!$school) {
            $this->markTestSkipped('An active school account is required.');
        }
        $this->session(['user_id' => (int)$school['id'], 'user_type' => 'School']);
        $this->enableCsrfToken();
        $this->post('/users/editprofile', ['Users' => ['email_address' => 'unauthorized@example.invalid']]);
        $this->assertResponseCode(400);
    }

    public function testProfileMutationRequiresCsrf(): void
    {
        $this->post('/users/editprofile', ['Users' => ['first_name' => 'Example']]);
        $this->assertResponseCode(403);
    }

    public function testSchoolCannotModifySupervisorCredentials(): void
    {
        $supervisor = ConnectionManager::get('default')->execute(
            "SELECT t.slug, t.school_id FROM users t JOIN users school ON school.id = t.school_id
             WHERE t.user_type = 'Teacher_Parent' AND t.slug IS NOT NULL AND school.user_type = 'School'
               AND school.status = 1 AND school.activation_status = 1 LIMIT 1"
        )->fetch('assoc');
        if (!$supervisor) {
            $this->markTestSkipped('A supervisor belonging to an active school is required.');
        }
        $this->session(['user_id' => (int)$supervisor['school_id'], 'user_type' => 'School']);
        $this->enableCsrfToken();
        $this->post('/users/editteacher/' . $supervisor['slug'], ['Users' => ['password' => 'not-a-real-password']]);
        $this->assertResponseCode(400);
    }
}
