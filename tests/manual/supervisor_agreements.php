<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\AppController;
use App\Controller\UsersController;
use App\Service\SchoolNotifications;
use App\Service\SupervisorAgreements;
use App\Service\SupervisorAssignmentPolicy;
use Cake\Datasource\ConnectionManager;
use Cake\Event\Event;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Routing\Router;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run supervisor agreement fixtures locally only.');
}
Router::reload();
(require dirname(__DIR__, 2) . '/config/routes.php')(Router::createRouteBuilder('/'));
$db = ConnectionManager::get('default');
$service = new SupervisorAgreements($db);
if (!$service->available()) {
    throw new RuntimeException('Apply migration 014 before running these checks.');
}
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
    $row['slug'] = 'agreement-test-' . bin2hex(random_bytes(10));
    $db->execute("INSERT INTO $table (`" . implode('`,`', array_keys($row)) . '`) VALUES (' .
        implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
    return (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
};
$session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
$request = static function (int $id, string $role, string $controller, string $action, string $method = 'GET', array $body = [], array $pass = []) use ($session): ServerRequest {
    $session->write(['user_id' => $id, 'user_type' => $role]);
    $session->delete('school_student_access');
    $request = new ServerRequest([
        'session' => $session, 'environment' => ['REQUEST_METHOD' => $method, 'HTTP_ACCEPT' => 'text/html'],
        'params' => ['controller' => $controller, 'action' => $action, 'pass' => $pass, 'plugin' => null, '_ext' => null],
        'post' => $body,
    ]);
    Router::setRequest($request);
    return $request;
};
$db->enableSavePoints();
$db->begin();
try {
    $source = $db->execute(
        "SELECT t.* FROM conventionregistrationteachers t
         JOIN conventionregistrations r ON r.id=t.conventionregistration_id
         JOIN conventions c ON c.id=r.convention_id
         JOIN users u ON u.id=t.teacher_id AND u.school_id=r.user_id
         WHERE u.user_type='Teacher_Parent' AND u.status=1 AND u.activation_status=1
         AND u.first_name<>'' AND u.last_name<>'' AND LOWER(u.gender) IN ('male','female','m','f')
         AND t.status=1 AND EXISTS (SELECT id FROM users s WHERE s.school_id=u.school_id
         AND s.user_type='Student' AND LOWER(s.gender)=LOWER(u.gender)) LIMIT 1"
    )->fetch('assoc');
    if (!$source) {
        throw new RuntimeException('A registered, verified supervisor with a same-gender school student is required.');
    }
    $supervisor = (int)$source['teacher_id'];
    $account = $db->execute('SELECT * FROM users WHERE id=?', [$supervisor])->fetch('assoc');
    $school = (int)$account['school_id'];
    $student = $db->execute("SELECT id FROM users WHERE school_id=? AND user_type='Student' AND LOWER(gender)=LOWER(?) LIMIT 1",
        [$school, $account['gender']])->fetch('assoc');
    $registrationRow = $db->execute('SELECT * FROM conventionregistrations WHERE id=?', [$source['conventionregistration_id']])->fetch('assoc');
    $studentRow = $db->execute('SELECT * FROM conventionregistrationstudents LIMIT 1')->fetch('assoc');
    if (!$registrationRow || !$studentRow) {
        throw new RuntimeException('Transactional registration fixture sources are required.');
    }
    // Isolate the context without inserting any records into MyISAM users/conventions.
    $year = 2098;
    $convention = (int)$registrationRow['convention_id'];
    $season = (int)$registrationRow['season_id'];
    $db->execute('DELETE FROM supervisor_agreements WHERE supervisor_id=? AND convention_id=? AND season_id=? AND season_year IN (?,?)',
        [$supervisor, $convention, $season, $year, $year + 1]);
    $registrationRow['season_year'] = $year;
    $registrationRow['status'] = 1;
    $registration = $clone('conventionregistrations', $registrationRow);
    $source['conventionregistration_id'] = $registration;
    $source['season_year'] = $year;
    $source['convention_id'] = $convention;
    $source['season_id'] = $season;
    $source['user_id'] = $school;
    $clone('conventionregistrationteachers', $source);
    $studentRow = array_replace($studentRow, [
        'conventionregistration_id' => $registration, 'convention_id' => $convention,
        'season_id' => $season, 'season_year' => $year, 'user_id' => $school,
        'student_id' => (int)$student['id'], 'teacher_parent_id' => 0, 'status' => 1,
    ]);
    $studentRegistration = $clone('conventionregistrationstudents', $studentRow);
    $policy = new SupervisorAssignmentPolicy($db);
    $assign = static function (int $id) use ($policy, $supervisor, $convention, $season, $year, $student, $db): void {
        $policy->assign($supervisor, $convention, $season, $year, [(int)$student['id']], [$id],
            fn() => $db->execute('UPDATE conventionregistrationstudents SET teacher_parent_id=? WHERE id=?', [$supervisor, $id]));
    };
    $assign($studentRegistration);
    $agreement = $db->execute(
        'SELECT * FROM supervisor_agreements WHERE supervisor_id=? AND convention_id=? AND season_id=? AND season_year=?',
        [$supervisor, $convention, $season, $year]
    )->fetch('assoc');
    $check($agreement && !$agreement['accepted_at'], 'School assignment creates a pending agreement');
    $agreementId = (int)$agreement['id'];
    $notice = $db->execute('SELECT * FROM school_notifications WHERE school_id=? AND event_key=?',
        [$supervisor, 'supervisor-agreement:' . $agreementId])->fetch('assoc');
    $check($notice && $notice['kind'] === 'supervisor_agreement' && !$notice['read_at'], 'Assignment creates a personal unread notice');
    $service->ensure($supervisor, $convention, $season, $year);
    $check((int)$db->execute('SELECT COUNT(*) FROM school_notifications WHERE school_id=? AND event_key=?',
        [$supervisor, 'supervisor-agreement:' . $agreementId])->fetchColumn(0) === 1, 'Repeated assignment/import does not duplicate notices');
    $check($service->pending($supervisor, $registration), 'Pending supervisor is blocked');
    (new SchoolNotifications($db))->update($supervisor, (int)$notice['id'], 'archive');
    $check(in_array($agreementId, array_map(fn($row) => (int)$row['id'], $service->forSupervisor($supervisor)), true),
        'Archived agreements remain actionable in Notifications');

    $session->write(['sess_selected_convention_registration_id' => $registration, 'current_session_profile_type' => 'Supervisor']);
    $routes = [
        ['Conventionregistrations', 'studentevents'], ['Groups', 'viewlist'], ['Heartevents', 'viewlist'],
        ['Eventsubmissions', 'viewlist'], ['Conventionregistrations', 'packageregistration'],
        ['Groups', 'eventgroups'], ['Groups', 'removestudentfromgroup'], ['Heartevents', 'addnew'],
        ['Heartevents', 'removedocument'], ['Combinerequests', 'viewlist'], ['Combinerequests', 'addrequest'],
        ['Combinerequests', 'decideteam'], ['Conventionregistrations', 'managestudentevents'],
        ['Conventionregistrations', 'packageregistrationprint'], ['Eventsubmissions', 'submitnewevent'],
        ['Eventsubmissions', 'removesubmission'], ['Eventsubmissions', 'submitstudentevent'],
        ['Eventsubmissions', 'submitgroupevent'], ['Homes', 'eventsubmissions'], ['Homes', 'checkstudentevent'],
    ];
    foreach ($routes as [$controller, $action]) {
        foreach (['GET', 'POST'] as $method) {
            $controllerClass = 'App\\Controller\\' . $controller . 'Controller';
            $app = new $controllerClass($request($supervisor, 'Teacher_Parent', $controller, $action, $method));
            $event = new Event('Controller.beforeFilter', $app);
            $app->beforeFilter($event);
            $check($event->isStopped() && $app->getResponse()->getStatusCode() === 303
                && str_contains($app->getResponse()->getHeaderLine('Location'), '/users/notifications'),
                "$method $controller/$action is blocked before the action runs");
            $check(str_contains($app->getResponse()->getHeaderLine('Cache-Control'), 'no-store'), 'Protected response is private');
        }
    }
    $ajax = $request($supervisor, 'Teacher_Parent', 'Groups', 'eventgroups', 'POST')
        ->withHeader('X-Requested-With', 'XMLHttpRequest');
    $app = new AppController($ajax);
    $app->beforeFilter(new Event('Controller.beforeFilter', $app));
    $check($app->getResponse()->getStatusCode() === 403
        && isset(json_decode((string)$app->getResponse()->getBody(), true)['error']), 'AJAX receives an explicit error, not success HTML');
    foreach (['Supervisor', 'Judge'] as $profile) {
        $session->write('current_session_profile_type', $profile);
        $app = new AppController($request($supervisor, 'Teacher_Parent', 'Groups', 'viewlist'));
        $event = new Event('Controller.beforeFilter', $app);
        $app->beforeFilter($event);
        $check($event->isStopped(), 'Switching profiles cannot bypass supervisor workflow gates');
    }
    foreach ([['Users', 'notifications'], ['Users', 'editprofile'], ['Users', 'logout'],
        ['Conventionregistrations', 'judgeevents'], ['Conventionregistrations', 'savejudgetotalscore'],
        ['Eventsubmissions', 'spellingseventsentries'], ['Eventsubmissions', 'distanceseventsentries']] as [$controller, $action]) {
        $app = new AppController($request($supervisor, 'Teacher_Parent', $controller, $action));
        $event = new Event('Controller.beforeFilter', $app);
        $app->beforeFilter($event);
        $check(!$event->isStopped(), "$controller/$action remains available");
    }
    $app = new AppController($request($school, 'School', 'Groups', 'viewlist'));
    $event = new Event('Controller.beforeFilter', $app);
    $app->beforeFilter($event);
    $check(!$event->isStopped(), 'School can continue working before supervisor acceptance');

    $roster = $service->roster($supervisor, $convention, $season, $year);
    $hash = SupervisorAgreements::rosterHash($roster);
    $other = (int)$db->execute("SELECT id FROM users WHERE user_type='Teacher_Parent' AND status=1 AND activation_status=1 AND id<>? LIMIT 1",
        [$supervisor])->fetchColumn(0);
    $reject(fn() => $service->accept($other, $agreementId, $hash), DomainException::class);
    $reject(fn() => $service->accept($school, $agreementId, $hash), DomainException::class);
    $reject(fn() => (new UsersController($request($school, 'School', 'Users', 'acceptsupervisoragreement', 'POST')))
        ->acceptsupervisoragreement((string)$agreementId), \Cake\Http\Exception\ForbiddenException::class);
    $reject(fn() => (new UsersController($request($supervisor, 'Teacher_Parent', 'Users', 'acceptsupervisoragreement')))
        ->acceptsupervisoragreement((string)$agreementId), \Cake\Http\Exception\MethodNotAllowedException::class);
    $unsigned = new UsersController($request($supervisor, 'Teacher_Parent', 'Users', 'acceptsupervisoragreement', 'POST', ['roster_hash' => $hash]));
    $unsigned->acceptsupervisoragreement((string)$agreementId);
    $check($service->pending($supervisor, $registration), 'A missing tick cannot sign the agreement');
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return new \Cake\Http\Response();
        }
    };
    $post = $request($supervisor, 'Teacher_Parent', 'Users', 'acceptsupervisoragreement', 'POST',
        ['agree' => '1', 'roster_hash' => $hash, 'signature' => 'Forged name']);
    $reject(fn() => (new \App\Middleware\ScopedPublicationCsrfMiddleware())->process($post, $handler),
        \Cake\Http\Exception\InvalidCsrfTokenException::class);
    $secondStudentRegistration = $clone('conventionregistrationstudents', $studentRow);
    $assign($secondStudentRegistration);
    $reject(fn() => $service->accept($supervisor, $agreementId, $hash), DomainException::class);
    $check($service->pending($supervisor, $registration), 'A stale displayed roster cannot be signed');
    $roster = $service->roster($supervisor, $convention, $season, $year);
    $hash = SupervisorAgreements::rosterHash($roster);
    $post = $post->withParsedBody(['agree' => '1', 'roster_hash' => $hash, 'signature' => 'Forged name']);
    $accept = new UsersController($post);
    $accept->acceptsupervisoragreement((string)$agreementId);
    $saved = $db->execute('SELECT * FROM supervisor_agreements WHERE id=?', [$agreementId])->fetch('assoc');
    $check($saved['signature'] === trim($account['first_name']) . ' ' . trim($account['last_name'])
        && (int)$saved['accepted_by'] === $supervisor && $saved['accepted_at'], 'Signature comes only from the personal account name');
    $snapshot = json_decode($saved['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    $check($snapshot['declarations'] === SupervisorAgreements::DECLARATIONS && $snapshot['students'] === $roster
        && (int)$saved['declaration_version'] === SupervisorAgreements::VERSION, 'Exact declaration version and named roster are preserved');
    $check(!$service->pending($supervisor, $registration), 'Acceptance unlocks this convention');
    $service->accept($supervisor, $agreementId, $hash);
    $check($db->execute('SELECT * FROM supervisor_agreements WHERE id=?', [$agreementId])->fetch('assoc') === $saved,
        'Repeated acceptance preserves the original signature and time');
    foreach (array_slice($routes, 0, 5) as [$controller, $action]) {
        $app = new AppController($request($supervisor, 'Teacher_Parent', $controller, $action));
        $event = new Event('Controller.beforeFilter', $app);
        $app->beforeFilter($event);
        $check(!$event->isStopped(), "$controller/$action unlocks after acceptance");
    }
    $policy->unassign([$studentRegistration, $secondStudentRegistration], $school, $registration);
    $assign($studentRegistration);
    $check(!$service->pending($supervisor, $registration)
        && $db->execute('SELECT * FROM supervisor_agreements WHERE id=?', [$agreementId])->fetch('assoc') === $saved,
        'Roster removal and reassignment do not reset acceptance for this convention');
    $registrationRow['season_year'] = $year + 1;
    $nextRegistration = $clone('conventionregistrations', $registrationRow);
    $source['conventionregistration_id'] = $nextRegistration;
    $source['season_year'] = $year + 1;
    $clone('conventionregistrationteachers', $source);
    $studentRow['conventionregistration_id'] = $nextRegistration;
    $studentRow['season_year'] = $year + 1;
    $studentRow['teacher_parent_id'] = $supervisor;
    $nextStudent = $clone('conventionregistrationstudents', $studentRow);
    $service->ensure($supervisor, $convention, $season, $year + 1);
    $check($service->pending($supervisor, $nextRegistration), 'Acceptance does not carry into another convention year');
    $nextSlug = $db->execute('SELECT slug FROM conventionregistrationstudents WHERE id=?', [$nextStudent])->fetchColumn(0);
    $app = new AppController($request($supervisor, 'Teacher_Parent', 'Eventsubmissions', 'submitgroupevent', 'POST', [], [$nextSlug]));
    $event = new Event('Controller.beforeFilter', $app);
    $app->beforeFilter($event);
    $check($event->isStopped(), 'An accepted selected convention cannot bypass the target upload convention');

    $inbox = new UsersController($request($supervisor, 'Teacher_Parent', 'Users', 'notifications'));
    $inbox->notifications();
    $view = new \App\View\AppView($inbox->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $view->disableAutoLayout();
    $view->set($inbox->viewBuilder()->getVars());
    $html = $view->render('Users/school_notifications');
    $check(!str_contains($html, 'data-supervisor-consent') && !str_contains($html, 'supervisor-agreement-form'),
        'Inbox displays rows only, not expanded agreement forms');
    $nextAgreement = $db->execute('SELECT * FROM supervisor_agreements WHERE supervisor_id=? AND convention_id=? AND season_id=? AND season_year=?',
        [$supervisor, $convention, $season, $year + 1])->fetch('assoc');
    $nextNotice = $db->execute('SELECT * FROM school_notifications WHERE school_id=? AND event_key=?',
        [$supervisor, 'supervisor-agreement:' . $nextAgreement['id']])->fetch('assoc');
    $check(str_contains($html, h($nextNotice['subject'])) && str_contains($html, '/notificationaction/' . $nextNotice['id'] . '/read/open')
        && !$nextNotice['read_at'], 'Agreement waits unread in the Gmail-style message list');
    $open = new UsersController($request($supervisor, 'Teacher_Parent', 'Users', 'notificationaction', 'POST'));
    $open->notificationaction((string)$nextNotice['id'], 'read', 'open');
    $check(str_contains($open->getResponse()->getHeaderLine('Location'), 'message=' . $nextNotice['id'])
        && (new SchoolNotifications($db))->message($supervisor, (int)$nextNotice['id'])['read_at'] !== null,
        'Opening the agreement row marks only that message read and opens its detail');
    $renderMessage = static function (int $messageId) use ($supervisor, $request): string {
        $detail = new UsersController($request($supervisor, 'Teacher_Parent', 'Users', 'notifications')
            ->withQueryParams(['message' => (string)$messageId]));
        $detail->notifications();
        $detailView = new \App\View\AppView($detail->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
        $detailView->disableAutoLayout();
        $detailView->set($detail->viewBuilder()->getVars());
        return $detailView->render('Users/school_notifications');
    };
    $detailHtml = $renderMessage((int)$nextNotice['id']);
    $check(str_contains($detailHtml, 'data-supervisor-consent') && str_contains($detailHtml, 'data-supervisor-signature')
        && str_contains($detailHtml, 'acceptsupervisoragreement') && str_contains($detailHtml, '_csrfToken'),
        'Opened agreement message has the signable form and CSRF');
    $check(substr_count($detailHtml, 'class="supervisor-agreement-form"') === 1
        && !str_contains($detailHtml, 'id="supervisor-agreement-' . $agreementId . '"'),
        'Message detail displays only its own agreement, never another convention');
    $acceptedHtml = $renderMessage((int)$notice['id']);
    $check(str_contains($acceptedHtml, h($saved['signature'])) && str_contains($acceptedHtml, 'Students named when you signed')
        && !str_contains($acceptedHtml, 'data-supervisor-consent'), 'Archived accepted message retains signature and historical roster without an unsigned form');
    $check((new SchoolNotifications($db))->message($supervisor, (int)$notice['id'])['read_at'] === null,
        'Rendering a message directly does not mutate its read state');
    (new SchoolNotifications($db))->update($supervisor, (int)$nextNotice['id'], 'archive');
    $check(str_contains($renderMessage((int)$nextNotice['id']), 'data-supervisor-consent'),
        'Archived pending agreement can still be opened and accepted');
    $db->execute('UPDATE conventionregistrationstudents SET teacher_parent_id=0 WHERE id=?', [$nextStudent]);
    $check(str_contains($renderMessage((int)$nextNotice['id']), 'There is no agreement to accept')
        && !str_contains($renderMessage((int)$nextNotice['id']), 'data-supervisor-consent'),
        'A message with no remaining assigned students cannot expose an acceptance form');
    $db->execute('UPDATE conventionregistrationstudents SET teacher_parent_id=? WHERE id=?', [$supervisor, $nextStudent]);
    $check(!str_contains($html, 'approval unavailable') && !str_contains($html, 'nothing has been signed'), 'Preview-only messages have been removed');
    $session->write(['sess_selected_convention_registration_id' => $nextRegistration, 'current_session_profile_type' => 'Supervisor']);
    $inbox->beforeRender(new Event('Controller.beforeRender', $inbox));
    $view->set($inbox->viewBuilder()->getVars());
    $sidebar = $view->element('user_left_menu');
    $check($inbox->viewBuilder()->getVar('supervisorAgreementRequired')
        && substr_count($sidebar, 'fa-lock') === 6 && !str_contains($sidebar, '/groups/viewlist'),
        'Locked sidebar routes all five workflows to Notifications (plus the existing password lock icon)');
    $session->delete('user_id');
    $anonymous = new UsersController($post);
    $response = $anonymous->acceptsupervisoragreement((string)$agreementId);
    $check($response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), '/users/login'),
        'Anonymous acceptance requires login');
} finally {
    $db->rollback(true);
}
echo "PASS: $checks supervisor agreement checks. All fixtures rolled back; no external emails sent.\n";
