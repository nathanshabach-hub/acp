<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\ConventionregistrationsController;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\UriInterface;

final class ConventionRegistrationPeopleTest extends TestCase
{
    public function testMultipleSupervisorsCanBeAddedWithoutDuplicates(): void
    {
        $this->checkSubmission('teacher');
    }

    public function testMultipleStudentsCanBeAddedWithoutDuplicates(): void
    {
        $this->checkSubmission('student');
    }

    private function checkSubmission(string $type): void
    {
        $connection = ConnectionManager::get('default');
        $registrations = $connection->execute(
            "SELECT cr.id, cr.user_id, cr.season_year FROM conventionregistrations cr
             INNER JOIN conventionseasons cs ON cs.id = cr.conventionseason_id
             INNER JOIN users school ON school.id = cr.user_id
             WHERE school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1
               AND NOW() BETWEEN cs.registration_start_date AND cs.registration_end_date"
        )->fetchAll('assoc');

        $registration = null;
        $personIds = [];
        $teacherId = null;
        foreach ($registrations as $candidate) {
            $teacherId = $connection->execute(
                "SELECT t.teacher_id FROM conventionregistrationteachers t
                 INNER JOIN users supervisor ON supervisor.id = t.teacher_id
                 WHERE t.conventionregistration_id = ? AND t.status = 1
                   AND supervisor.gender IN ('Male', 'Female')
                   AND (SELECT COUNT(*) FROM conventionregistrationstudents assigned
                        WHERE assigned.teacher_parent_id = t.teacher_id AND assigned.convention_id = t.convention_id
                          AND assigned.season_id = t.season_id AND assigned.season_year = t.season_year AND assigned.status = 1) <= 8
                 LIMIT 1",
                [$candidate['id']]
            )->fetchColumn(0);
            if ($type === 'student' && !$teacherId) {
                continue;
            }
            if ($type === 'teacher') {
                $people = $connection->execute(
                    "SELECT u.id FROM users u WHERE u.school_id = ? AND u.user_type = 'Teacher_Parent'
                     AND u.status <> 2 AND NOT EXISTS
                     (SELECT 1 FROM conventionregistrationteachers t WHERE t.conventionregistration_id = ? AND t.teacher_id = u.id)
                     LIMIT 2",
                    [$candidate['user_id'], $candidate['id']]
                )->fetchAll('assoc');
            } else {
                $people = $connection->execute(
                    "SELECT u.id FROM users u INNER JOIN users supervisor ON supervisor.id = ?
                     WHERE u.school_id = ? AND u.user_type = 'Student' AND u.gender = supervisor.gender
                     AND u.status <> 2 AND ? - u.birth_year BETWEEN 11 AND 20 AND NOT EXISTS
                     (SELECT 1 FROM conventionregistrationstudents s WHERE s.conventionregistration_id = ? AND s.student_id = u.id)
                     LIMIT 2",
                    [$teacherId, $candidate['user_id'], $candidate['season_year'], $candidate['id']]
                )->fetchAll('assoc');
            }
            if (count($people) === 2) {
                $registration = $candidate;
                $personIds = array_column($people, 'id');
                break;
            }
        }
        if ($registration === null) {
            $this->markTestSkipped('An open registration with two eligible unregistered people is required.');
        }

        $session = new Session();
        $session->write('user_id', $registration['user_id']);
        $session->write('user_type', 'School');
        $session->write('sess_selected_convention_registration_id', $registration['id']);
        $action = $type === 'teacher' ? 'addteacher' : 'addstudent';
        $field = $type === 'teacher' ? 'Conventionregistrationteachers' : 'Conventionregistrationstudents';
        $idField = $type === 'teacher' ? 'teacher_ids' : 'student_ids';
        $request = (new ServerRequest([
            'url' => '/conventionregistrations/' . $action,
            'session' => $session,
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody([$field => [$idField => $personIds, 'teacher_parent_id' => $teacherId]]);
        $table = $type === 'teacher' ? 'conventionregistrationteachers' : 'conventionregistrationstudents';
        $column = $type === 'teacher' ? 'teacher_id' : 'student_id';
        $connection->begin();
        try {
            $controller = new RegistrationPeopleTestController($request);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $response = $controller->{$action}();
                $this->assertSame(302, $response->getStatusCode());
                $count = $connection->execute(
                    "SELECT COUNT(*) FROM $table WHERE conventionregistration_id = ? AND $column IN (?, ?)",
                    [$registration['id'], $personIds[0], $personIds[1]]
                )->fetchColumn(0);
                $this->assertSame(2, (int)$count);
            }
        } finally {
            $connection->rollback();
        }
    }
}

final class RegistrationPeopleTestController extends ConventionregistrationsController
{
    public function redirect(UriInterface|array|string $url, int $status = 302): ?Response
    {
        return $this->getResponse()->withStatus($status);
    }
}
