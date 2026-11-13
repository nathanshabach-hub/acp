<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class BulkStudentArchiveTest extends TestCase
{
    use IntegrationTestTrait;

    public function testArchiveRequiresPost(): void
    {
        $this->session(['user_id' => 1, 'user_type' => 'School']);
        $this->get('/users/archivestudents');
        $this->assertResponseCode(405);
    }

    public function testArchiveRequiresCsrf(): void
    {
        $this->session(['user_id' => 1, 'user_type' => 'School']);
        $this->post('/users/archivestudents', ['student_ids' => [1]]);
        $this->assertResponseCode(403);
    }

    public function testSupervisorCannotBulkArchive(): void
    {
        $this->session(['user_id' => 1, 'user_type' => 'Teacher_Parent']);
        $this->enableCsrfToken();
        $this->post('/users/archivestudents', ['student_ids' => [1]]);
        $this->assertResponseCode(403);
    }
}
