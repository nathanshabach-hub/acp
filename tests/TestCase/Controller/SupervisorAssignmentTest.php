<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\Admin\ConventionregistrationsController as AdminController;
use App\Controller\HomesController;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\UriInterface;

final class SupervisorAssignmentTest extends TestCase
{
    public function testBothReassignmentPathsRejectMismatchedGender(): void
    {
        $db = ConnectionManager::get('default');
        $row = $db->execute(
            "SELECT s.id, s.slug, s.teacher_parent_id, s.conventionregistration_id, s.user_id, t.teacher_id AS target
             FROM conventionregistrationstudents s
             JOIN users pupil ON pupil.id = s.student_id
             JOIN users school ON school.id = s.user_id AND school.user_type = 'School'
                  AND school.status = 1 AND school.activation_status = 1
             JOIN conventionregistrations r ON r.id = s.conventionregistration_id AND r.user_id = s.user_id
             JOIN conventionregistrationteachers t ON t.conventionregistration_id = s.conventionregistration_id
                  AND t.user_id = s.user_id AND t.status = 1
             JOIN users teacher ON teacher.id = t.teacher_id AND teacher.user_type = 'Teacher_Parent'
             WHERE s.status = 1 AND pupil.gender IN ('Male', 'Female')
               AND teacher.gender IN ('Male', 'Female') AND pupil.gender <> teacher.gender LIMIT 1"
        )->fetch('assoc');
        if (!$row) {
            $this->markTestSkipped('A school registration with an opposite-gender supervisor is required.');
        }
        $session = new Session();
        $session->write('user_id', $row['user_id']);
        $session->write('user_type', 'School');
        $session->write('sess_selected_convention_registration_id', $row['conventionregistration_id']);
        $request = new ServerRequest(['session' => $session, 'environment' => ['REQUEST_METHOD' => 'POST']]);
        $response = (new HomesController($request))->assignteachertostudent($row['slug'], $row['target']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('matching genders', (string)$response->getBody());

        $adminSession = new Session();
        $adminSession->write('admin_id', 1);
        $adminRequest = (new ServerRequest(['session' => $adminSession, 'environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withParsedBody(['student_registration_id' => $row['id'], 'new_teacher_parent_id' => $row['target'], 'return_slug' => 'test']);
        $response = (new SupervisorAssignmentAdminTestController($adminRequest))->reassignstudentsupervisor();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('matching genders', (string)json_encode($adminSession->read('Flash')));
        $this->assertSame((int)$row['teacher_parent_id'], (int)$db->execute(
            'SELECT teacher_parent_id FROM conventionregistrationstudents WHERE id = ?', [$row['id']]
        )->fetchColumn(0));
    }
}

final class SupervisorAssignmentAdminTestController extends AdminController
{
    public function redirect(UriInterface|array|string $url, int $status = 302): ?Response
    {
        return $this->getResponse()->withStatus($status);
    }
}
