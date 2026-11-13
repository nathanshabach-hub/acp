<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\ConventionregistrationsController;
use App\Service\SupervisorAssignmentPolicy;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Routing\Router;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run supervisor unassignment fixtures locally only.');
}
Router::reload();
(require dirname(__DIR__, 2) . '/config/routes.php')(Router::createRouteBuilder('/'));
$db = ConnectionManager::get('default');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$reject = static function (callable $call, string $type) use ($check): void {
    try {
        $call();
    } catch (Throwable $error) {
        if (!$error instanceof $type) {
            throw $error;
        }
        $check(true, 'Expected rejection');
        return;
    }
    throw new RuntimeException('Expected rejection was missing.');
};
$clone = static function (string $table, array $row) use ($db): int {
    unset($row['id']);
    $row['slug'] = 'bulk-unassign-test-' . bin2hex(random_bytes(10));
    $db->execute("INSERT INTO $table (`" . implode('`,`', array_keys($row)) . '`) VALUES (' .
        implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
    return (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
};
$db->enableSavePoints();
$db->begin();
try {
    $source = $db->execute("SELECT r.* FROM conventionregistrations r JOIN users u ON u.id=r.user_id
        WHERE u.user_type='School' AND u.status=1 AND u.activation_status=1 LIMIT 1")->fetch('assoc');
    $student = $db->execute('SELECT * FROM conventionregistrationstudents WHERE teacher_parent_id>0 LIMIT 1')->fetch('assoc');
    if (!$source || !$student) {
        throw new RuntimeException('An active school registration and assigned student fixture source are required.');
    }
    $school = (int)$source['user_id'];
    $registration = $clone('conventionregistrations', $source);
    $otherRegistration = $clone('conventionregistrations', $source);
    $student['user_id'] = $school;
    $student['conventionregistration_id'] = $registration;
    $student['event_ids'] = '1,2,3';
    $ids = [$clone('conventionregistrationstudents', $student), $clone('conventionregistrationstudents', $student)];
    $student['conventionregistration_id'] = $otherRegistration;
    $outsideId = $clone('conventionregistrationstudents', $student);
    $before = $db->execute('SELECT * FROM conventionregistrationstudents WHERE id IN (?,?) ORDER BY id', $ids)->fetchAll('assoc');
    $policy = new SupervisorAssignmentPolicy($db);
    foreach ([[], ['invalid'], [[1]], [-1], [0]] as $bad) {
        $reject(fn() => $policy->unassign($bad, $school, $registration), DomainException::class);
    }
    $reject(fn() => $policy->unassign($ids, $school + 1000000, $registration), DomainException::class);
    $reject(fn() => $policy->unassign([$ids[0], $outsideId], $school, $registration), DomainException::class);
    $check($db->execute('SELECT * FROM conventionregistrationstudents WHERE id IN (?,?) ORDER BY id', $ids)->fetchAll('assoc') === $before,
        'Mixed convention selection is rejected without partial updates');
    $db->execute("INSERT INTO cross_school_sponsorship_requests
        (student_registration_id,origin_school_id,sponsor_school_id,supervisor_id,status,created)
        VALUES (?,?,?,?,'pending_school',NOW())", [$ids[1], $school, $school, $student['teacher_parent_id']]);
    $sponsorship = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    $reject(fn() => $policy->unassign($ids, $school, $registration), DomainException::class);
    $check($db->execute('SELECT * FROM conventionregistrationstudents WHERE id IN (?,?) ORDER BY id', $ids)->fetchAll('assoc') === $before,
        'Pending sponsorship blocks the entire batch');
    $db->execute('DELETE FROM cross_school_sponsorship_requests WHERE id=?', [$sponsorship]);

    $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
    $session->write(['user_id' => $school, 'user_type' => 'School', 'sess_selected_convention_registration_id' => $registration]);
    $request = new ServerRequest([
        'session' => $session, 'environment' => ['REQUEST_METHOD' => 'POST'],
        'params' => ['controller' => 'Conventionregistrations', 'action' => 'unassignsupervisors', 'plugin' => null, '_ext' => null],
        'post' => ['student_registration_ids' => array_map('strval', $ids), 'confirm_unassign' => '1'],
    ]);
    Router::setRequest($request);
    $reject(fn() => (new ConventionregistrationsController($request->withMethod('GET')))->unassignsupervisors(),
        \Cake\Http\Exception\MethodNotAllowedException::class);
    (new ConventionregistrationsController($request->withParsedBody(['student_registration_ids' => $ids])))->unassignsupervisors();
    $check($db->execute('SELECT * FROM conventionregistrationstudents WHERE id IN (?,?) ORDER BY id', $ids)->fetchAll('assoc') === $before,
        'Missing explicit confirmation preserves assignments');
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return new \Cake\Http\Response();
        }
    };
    $reject(fn() => (new \App\Middleware\ScopedPublicationCsrfMiddleware())->process($request, $handler),
        \Cake\Http\Exception\InvalidCsrfTokenException::class);
    $controller = new ConventionregistrationsController($request);
    $controller->unassignsupervisors();
    $check($controller->getResponse()->getStatusCode() === 302
        && str_contains($controller->getResponse()->getHeaderLine('Location'), '/conventionregistrations/students'),
        'Bulk POST redirects to updated registration list');
    $after = $db->execute('SELECT * FROM conventionregistrationstudents WHERE id IN (?,?) ORDER BY id', $ids)->fetchAll('assoc');
    foreach ($after as $index => $row) {
        $check((int)$row['teacher_parent_id'] === 0, 'Selected supervisor assignment cleared');
        $row['teacher_parent_id'] = $before[$index]['teacher_parent_id'];
        $check($row === $before[$index], 'Registration, events, status and all other student data unchanged');
    }
    $check((int)$db->execute('SELECT teacher_parent_id FROM conventionregistrationstudents WHERE id=?', [$outsideId])->fetchColumn(0) > 0,
        'Non-selected registration unaffected');
    $check($policy->unassign($ids, $school, $registration) === 0, 'Repeated request is idempotent');
    $db->execute('UPDATE conventionregistrationstudents SET teacher_parent_id=? WHERE id IN (?,?)',
        [$student['teacher_parent_id'], ...$ids]);
    $check($policy->unassign([$ids[0], $ids[0], (string)$ids[1]], $school, $registration) === 2, 'Duplicate IDs counted once');
    $session->write('user_type', 'Teacher_Parent');
    $reject(fn() => (new ConventionregistrationsController($request))->unassignsupervisors(),
        \Cake\Http\Exception\ForbiddenException::class);
    $session->delete('user_id');
    $response = (new ConventionregistrationsController($request))->unassignsupervisors();
    $check($response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), '/users/login'),
        'Anonymous POST requires login');
} finally {
    $db->rollback(true);
}
echo "PASS: $checks bulk supervisor unassignment checks. All fixtures rolled back.\n";
