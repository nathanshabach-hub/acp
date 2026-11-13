<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\ConventionregistrationsController;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;

final class SchoolRegisteredJudgesTest extends TestCase
{
    public function testListingIsScopedToSchoolAndSelectedConvention(): void
    {
        $db = ConnectionManager::get('default');
        $registrations = $db->execute(
            "SELECT r.id,r.user_id,r.conventionseason_id FROM conventionregistrations r
             JOIN users u ON u.id = r.user_id
             WHERE u.user_type = 'School' AND u.status = 1 AND u.activation_status = 1"
        )->fetchAll('assoc');
        if (!$registrations) {
            $this->markTestSkipped('An active school convention registration is required.');
        }
        foreach ($registrations as $registration) {
            $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
            $session->write([
                'user_id' => (int)$registration['user_id'], 'user_type' => 'School',
                'sess_selected_convention_registration_id' => (int)$registration['id'],
            ]);
            $request = new ServerRequest([
                'url' => '/conventionregistrations/judgesregistered', 'session' => $session,
                'params' => ['controller' => 'Conventionregistrations', 'action' => 'judgesregistered'],
                'environment' => ['REQUEST_METHOD' => 'GET'],
            ]);
            $controller = new ConventionregistrationsController($request);
            $controller->judgesregistered();
            $expected = $db->execute(
                "SELECT u.id FROM users u WHERE u.school_id = ?
                 AND (u.user_type = 'Judge' OR (u.user_type = 'Teacher_Parent' AND u.is_judge = 1))
                 AND EXISTS (SELECT 1 FROM conventionregistrations r WHERE r.user_id = u.id AND r.conventionseason_id = ?)",
                [$registration['user_id'], $registration['conventionseason_id']]
            )->fetchAll('assoc');
            $actual = array_map('intval', $controller->viewBuilder()->getVar('judges')->extract('id')->toList());
            $expectedIds = array_map('intval', array_column($expected, 'id'));
            sort($actual);
            sort($expectedIds);
            $this->assertSame($expectedIds, $actual);
        }
        $registration = $registrations[0];
        foreach ([0, (int)$db->execute(
            'SELECT id FROM conventionregistrations WHERE user_id <> ? LIMIT 1', [$registration['user_id']]
        )->fetchColumn(0)] as $invalidId) {
            $session->write(['user_id' => (int)$registration['user_id'], 'sess_selected_convention_registration_id' => $invalidId]);
            $controller = new class($request) extends ConventionregistrationsController {
                public function redirect($url, int $status = 302): ?Response
                {
                    return new Response(['status' => $status]);
                }
            };
            $this->assertSame(302, $controller->judgesregistered()->getStatusCode());
            $this->assertNull($controller->viewBuilder()->getVar('judges'));
        }
    }
}
