<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\UsersController;
use App\Service\SchoolNotifications;
use App\Utility\LegacyEmail;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Message;
use Cake\Mailer\Transport\DebugTransport;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run school inbox fixtures locally only.');
}
\Cake\Routing\Router::reload();
(require dirname(__DIR__, 2) . '/config/routes.php')(\Cake\Routing\Router::createRouteBuilder('/'));
$db = ConnectionManager::get('default');
$service = new SchoolNotifications($db);
if (!$service->available()) {
    throw new RuntimeException('Apply migration 012 before running these checks.');
}
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$reject = static function (callable $call, string $type, string $message) use ($check): void {
    try {
        $call();
    } catch (Throwable $error) {
        if (!$error instanceof $type) {
            throw $error;
        }
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};
$session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
$request = static function (int $school, string $method = 'GET', array $query = [], string $action = 'notifications', string $type = 'School') use ($session, $db): ServerRequest {
    $session->write(['user_id' => $school, 'user_type' => $type]);
    $session->delete('school_student_access');
    $session->delete('student_portal_version');
    if ($type === 'Student') {
        $session->write('student_portal_version', (int)$db->execute(
            'SELECT credential_version FROM student_portal_accounts WHERE student_id=?', [$school]
        )->fetchColumn(0));
    }
    $request = new ServerRequest([
        'session' => $session, 'environment' => ['REQUEST_METHOD' => $method],
        'params' => ['controller' => 'Users', 'action' => $action, 'plugin' => null, '_ext' => null],
        'query' => $query,
    ]);
    \Cake\Routing\Router::setRequest($request);
    return $request;
};
$db->enableSavePoints();
$db->begin();
try {
    $schools = $db->execute("SELECT id,email_address FROM users WHERE user_type='School' AND status=1 AND activation_status=1 AND email_address<>'' ORDER BY id LIMIT 2")->fetchAll('assoc');
    if (count($schools) !== 2) {
        throw new RuntimeException('Two active school accounts are required; no account fixtures are created in MyISAM users.');
    }
    $school = (int)$schools[0]['id'];
    $other = (int)$schools[1]['id'];
    $db->execute('DELETE FROM school_notifications WHERE school_id IN (?,?)', [$school, $other]);
    $send = static function (AbstractTransport $transport, array $to, array $cc = [], array $bcc = []): void {
        (new LegacyEmail())->setTransport($transport)->setFrom('portal@example.invalid')
            ->setTo($to)->setCc($cc)->setBcc($bcc)->setSubject('Fixture <script>alert(1)</script>')
            ->setEmailFormat('text')->send('Secret reset code and attachment data must never be copied.');
    };
    $memory = new class extends AbstractTransport {
        public function send(Message $message): array
        {
            return ['headers' => '', 'message' => ''];
        }
    };
    $send(new DebugTransport(), [$schools[0]['email_address']]);
    $check($service->inbox($school, 'inbox', 1)['total'] === 0, 'Debug emails do not claim delivery');
    $send($memory, [$schools[0]['email_address']], [$schools[0]['email_address']], [$schools[1]['email_address']]);
    $inbox = $service->inbox($school, 'inbox', 1);
    $check($inbox['total'] === 1 && (int)$inbox['counts']['unread'] === 1, 'Real send path creates one unread notice per school');
    $check($service->inbox($other, 'inbox', 1)['total'] === 1, 'BCC school receives its own isolated notice');
    $message = $inbox['messages'][0];
    $id = (int)$message['id'];
    $check($message['subject'] === 'Fixture <script>alert(1)</script>' && $message['kind'] === 'email', 'Subject and notification type are preserved');
    $check(!str_contains($message['body'], 'Secret reset code') && !str_contains($message['body'], $schools[1]['email_address']), 'Email notice excludes secrets, bodies and other recipient addresses');
    $failed = new class extends AbstractTransport {
        public function send(Message $message): array
        {
            throw new RuntimeException('Intentional fixture transport failure');
        }
    };
    $reject(fn() => $send($failed, [$schools[0]['email_address']]), RuntimeException::class, 'Delivery failure is surfaced');
    $check($service->inbox($school, 'inbox', 1)['total'] === 1, 'Failed delivery creates no sent notice');
    $send($memory, ['not-a-school@example.invalid']);
    $check($service->inbox($school, 'inbox', 1)['total'] === 1, 'Non-school recipients create no school notice');
    $reject(fn() => $service->message($other, $id), \Cake\Http\Exception\NotFoundException::class, 'Foreign school cannot read a notification');
    foreach (['read', 'unread', 'archive', 'restore'] as $action) {
        $reject(fn() => $service->update($other, $id, $action), \Cake\Http\Exception\NotFoundException::class, 'Foreign mutation is denied');
    }
    $service->update($school, $id, 'read');
    $check($service->inbox($school, 'unread', 1)['total'] === 0, 'Mark read removes unread state');
    $service->update($school, $id, 'unread');
    $check($service->inbox($school, 'unread', 1)['total'] === 1, 'Mark unread restores unread state');
    $service->update($school, $id, 'archive');
    $check($service->inbox($school, 'inbox', 1)['total'] === 0 && $service->inbox($school, 'archive', 1)['total'] === 1, 'Archive moves without deleting');
    $service->update($school, $id, 'restore');
    $check($service->inbox($school, 'inbox', 1)['total'] === 1, 'Archived messages can be restored');
    $reject(fn() => $service->update($school, $id, 'delete'), InvalidArgumentException::class, 'Unsupported actions are rejected');
    $reject(fn() => $service->inbox($school, 'invalid', 1), InvalidArgumentException::class, 'Invalid folders are rejected');

    $controller = new UsersController($request($school));
    $controller->notifications();
    $check($controller->viewBuilder()->getTemplate() === 'school_notifications', 'School uses new inbox, not supervisor preview');
    $check(str_contains($controller->getResponse()->getHeaderLine('Cache-Control'), 'no-store'), 'Inbox is not cached');
    $view = new \App\View\AppView($controller->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $view->disableAutoLayout();
    $view->set($controller->viewBuilder()->getVars());
    $html = $view->render('Users/school_notifications');
    $check(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>alert(1)</script>'), 'Notification subjects are escaped');
    $check(str_contains($html, '_csrfToken') && str_contains($html, 'notificationaction'), 'Opening messages uses CSRF-protected POST');
    $check(str_contains($html, '/users/notifications') && str_contains($html, '/users/crossschoolrequests'), 'Notifications and sponsorship management have separate links');
    $check($service->message($school, $id)['read_at'] === null, 'Inbox GET does not change read state');
    $detail = new UsersController($request($school, 'GET', ['message' => (string)$id]));
    $detail->notifications();
    $check((int)$detail->viewBuilder()->getVar('message')['id'] === $id, 'Own message detail can be opened');
    $check($service->message($school, $id)['read_at'] === null, 'Detail GET does not mutate read state');
    $reject(fn() => (new UsersController($request($other, 'GET', ['message' => (string)$id])))->notifications(),
        \Cake\Http\Exception\NotFoundException::class, 'Detail controller prevents foreign access');
    foreach ([['folder' => 'unknown'], ['page' => ['1']], ['message' => ['1']]] as $query) {
        $reject(fn() => (new UsersController($request($school, 'GET', $query)))->notifications(),
            \Cake\Http\Exception\BadRequestException::class, 'Malformed inbox filters rejected');
    }
    $reject(fn() => (new UsersController($request($school, 'GET', [], 'notificationaction')))->notificationaction((string)$id, 'archive'),
        \Cake\Http\Exception\MethodNotAllowedException::class, 'GET cannot archive');
    $reject(fn() => (new UsersController($request($other, 'POST', [], 'notificationaction')))->notificationaction((string)$id, 'read'),
        \Cake\Http\Exception\NotFoundException::class, 'POST controller prevents foreign mutation');
    $controller = new UsersController($request($school, 'POST', [], 'notificationaction'));
    $controller->notificationaction((string)$id, 'read', 'open');
    $check($service->message($school, $id)['read_at'] !== null && str_contains($controller->getResponse()->getHeaderLine('Location'), 'message=' . $id),
        'Opening a message marks read and redirects to detail');
    foreach (['unread', 'archive', 'restore'] as $action) {
        $controller = new UsersController($request($school, 'POST', [], 'notificationaction'));
        $controller->notificationaction((string)$id, $action);
        $check($controller->getResponse()->getStatusCode() === 302, 'POST inbox action completes');
    }
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return new \Cake\Http\Response();
        }
    };
    $reject(fn() => (new \App\Middleware\ScopedPublicationCsrfMiddleware())->process($request($school, 'POST', [], 'notificationaction'), $handler),
        \Cake\Http\Exception\InvalidCsrfTokenException::class, 'Forged inbox POST rejected by middleware');
    $teacher = $db->execute("SELECT id FROM users WHERE user_type='Teacher_Parent' AND status=1 AND activation_status=1 LIMIT 1")->fetch('assoc');
    if (!$teacher) {
        throw new RuntimeException('An active supervisor is required for compatibility checks.');
    }
    $reject(fn() => (new UsersController($request((int)$teacher['id'], 'POST', [], 'notificationaction', 'Teacher_Parent')))->notificationaction((string)$id, 'archive'),
        \Cake\Http\Exception\NotFoundException::class, 'Supervisor cannot mutate school notifications');
    $supervisor = new UsersController($request((int)$teacher['id'], 'GET', [], 'notifications', 'Teacher_Parent'));
    $supervisor->notifications();
    $check($supervisor->viewBuilder()->getVar('supervisorAgreements') !== null && $supervisor->viewBuilder()->getTemplate() === 'school_notifications',
        'Supervisor shares inbox layout with personal agreements');
    $supervisorId = (int)$teacher['id'];
    $db->execute('DELETE FROM school_notifications WHERE school_id=?', [$supervisorId]);
    $db->execute("INSERT INTO school_notifications (school_id,event_key,kind,subject,sender,body,created)
        VALUES (?,?,'email','Unread dashboard fixture','Portal','Fixture',NOW())",
        [$supervisorId, 'shake-test-' . bin2hex(random_bytes(6))]);
    $unreadId = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    $renderDashboard = static function () use ($request, $supervisorId, $check): string {
        $dashboard = new UsersController($request($supervisorId, 'GET', [], 'dashboard', 'Teacher_Parent'));
        $dashboard->dashboard();
        $check(str_contains($dashboard->getResponse()->getHeaderLine('Cache-Control'), 'no-store'),
            'Unread dashboard state is not cached');
        $view = new \App\View\AppView($dashboard->getRequest());
        $view->disableAutoLayout();
        $view->set($dashboard->viewBuilder()->getVars());
        return $view->render('Users/dashboard');
    };
    $check($service->unreadCount($supervisorId) === 1, 'Unread count is scoped to the supervisor, not other recipients');
    $dashboardHtml = $renderDashboard();
    $check(str_contains($dashboardHtml, 'portal-notification-unread') && str_contains($dashboardHtml, 'Notification (1)')
        && str_contains($dashboardHtml, 'Notifications: 1 unread messages'), 'Unread dashboard button shakes and announces its count');
    (new UsersController($request($supervisorId, 'POST', [], 'notificationaction', 'Teacher_Parent')))
        ->notificationaction((string)$unreadId, 'read', 'open');
    $check($service->unreadCount($supervisorId) === 0 && !str_contains($renderDashboard(), 'portal-notification-unread'),
        'Reading the final unread message stops the dashboard reminder');
    $service->update($supervisorId, $unreadId, 'unread');
    $check(str_contains($renderDashboard(), 'portal-notification-unread'), 'Mark unread restarts the reminder');
    $service->update($supervisorId, $unreadId, 'archive');
    $check($service->unreadCount($supervisorId) === 0 && !str_contains($renderDashboard(), 'portal-notification-unread'),
        'Archived messages match the inbox unread count and do not shake the button');
    $service->update($supervisorId, $unreadId, 'restore');
    $check($service->unreadCount($supervisorId) === 1, 'Restoring an unread message restores the reminder count');
    foreach (['Teacher_Parent', 'Judge', 'Student'] as $role) {
        $account = $db->execute("SELECT id,email_address FROM users WHERE user_type=? AND status=1 AND activation_status=1 LIMIT 1", [$role])->fetch('assoc');
        if (!$account) {
            throw new RuntimeException("An active $role account is required; no MyISAM users are created.");
        }
        $accountId = (int)$account['id'];
        if ($role === 'Student') {
            (new \App\Service\StudentPortalAccounts($db))->ensure($accountId);
            $db->execute('UPDATE student_portal_accounts SET password_hash=?, first_login=NOW(), credential_version=1 WHERE student_id=?',
                [password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), $accountId]);
        }
        $db->execute('DELETE FROM school_notifications WHERE school_id=?', [$accountId]);
        if (trim((string)$account['email_address']) !== '') {
            $send($memory, [$account['email_address']]);
        } else {
            $db->execute("INSERT INTO school_notifications (school_id,event_key,kind,subject,sender,body,created)
                VALUES (?,?,'email','Portal fixture','Portal','Private account notice',NOW())",
                [$accountId, 'role-fixture-' . bin2hex(random_bytes(6))]);
        }
        $personalInbox = $service->inbox($accountId, 'inbox', 1);
        $check($personalInbox['total'] === 1, "$role gets its own notice");
        $personalId = (string)$personalInbox['messages'][0]['id'];
        $personal = new UsersController($request($accountId, 'GET', ['message' => $personalId], 'notifications', $role));
        $personal->notifications();
        $check($personal->viewBuilder()->getTemplate() === 'school_notifications' && !$personal->viewBuilder()->getVar('isSchool'),
            "$role uses shared inbox without school controls");
        $personalView = new \App\View\AppView($personal->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
        $personalView->disableAutoLayout();
        $personalView->set($personal->viewBuilder()->getVars());
        $personalHtml = $personalView->render('Users/school_notifications');
        $check(str_contains($personalHtml, 'notification-toolbar') && str_contains($personalHtml, 'Mark read')
            && !str_contains($personalHtml, 'Manage sponsorship requests') && !str_contains($personalHtml, 'notificationinvoice'),
            "$role gets inbox controls without school invoice or sponsorship links");
        $check(!str_contains($personalHtml, 'data-supervisor-consent') && !str_contains($personalHtml, 'Supervisor agreements'),
            "$role email detail does not display unrelated supervisor agreements");
        $check($service->message($accountId, (int)$personalId)['read_at'] === null, "$role GET remains read-only");
        foreach (['read', 'unread', 'archive', 'restore'] as $operation) {
            (new UsersController($request($accountId, 'POST', [], 'notificationaction', $role)))
                ->notificationaction($personalId, $operation);
            $saved = $service->message($accountId, (int)$personalId);
            $check(match ($operation) {
                'read' => $saved['read_at'] !== null,
                'unread' => $saved['read_at'] === null,
                'archive' => $saved['archived_at'] !== null,
                'restore' => $saved['archived_at'] === null,
            }, "$role $operation persists");
        }
        $reject(fn() => (new UsersController($request($accountId, 'GET', ['message' => (string)$id], 'notifications', $role)))->notifications(),
            \Cake\Http\Exception\NotFoundException::class, "$role cannot read school notices");
        $reject(fn() => (new UsersController($request($school, 'GET', ['message' => $personalId])))->notifications(),
            \Cake\Http\Exception\NotFoundException::class, "School cannot read $role notices");
        $reject(fn() => (new UsersController($request($accountId, 'GET', [], 'notificationinvoice', $role)))->notificationinvoice($personalId),
            $role === 'Judge' ? \Cake\Http\Exception\NotFoundException::class : \Cake\Http\Exception\ForbiddenException::class,
            "$role cannot use a generic email notice to access an invoice");
        $reject(fn() => (new \App\Middleware\ScopedPublicationCsrfMiddleware())->process($request($accountId, 'POST', [], 'notificationaction', $role), $handler),
            \Cake\Http\Exception\InvalidCsrfTokenException::class, "$role inbox actions require CSRF");
        $db->execute('DELETE FROM school_notifications WHERE event_key=?', [$personalInbox['messages'][0]['event_key']]);
    }
    $reject(fn() => (new UsersController($request($school, 'GET', [], 'notifications', 'Judge')))->notifications(),
        \Cake\Http\Exception\ForbiddenException::class, 'Session role must match saved account role');
    $anonymous = new UsersController($request(0));
    $loginResponse = $anonymous->notifications();
    $check($loginResponse->getStatusCode() === 302
        && str_contains($loginResponse->getHeaderLine('Location'), '/users/login'),
        'Anonymous users are redirected to login');

    $source = $db->execute('SELECT * FROM conventionregistrationstudents LIMIT 1')->fetch('assoc');
    if (!$source) {
        throw new RuntimeException('A registration fixture source is required.');
    }
    unset($source['id']);
    $source['slug'] = 'notification-test-' . bin2hex(random_bytes(8));
    $db->execute('INSERT INTO conventionregistrationstudents (`' . implode('`,`', array_keys($source)) . '`) VALUES (' .
        implode(',', array_fill(0, count($source), '?')) . ')', array_values($source));
    $registrationId = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    $db->execute("INSERT INTO cross_school_sponsorship_requests (student_registration_id,origin_school_id,sponsor_school_id,supervisor_id,status,created) VALUES (?,?,?,?,'pending_school',NOW())",
        [$registrationId, $school, $other, (int)$teacher['id']]);
    $requestId = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    $service->sponsorship($requestId);
    $service->sponsorship($requestId);
    $count = (int)$db->execute("SELECT COUNT(*) FROM school_notifications WHERE event_key=?", ['sponsorship:' . $requestId . ':received'])->fetchColumn(0);
    $check($count === 2, 'Sponsorship import is idempotent and notifies both schools');
    $received = $db->execute("SELECT subject FROM school_notifications WHERE school_id=? AND event_key=?", [$other, 'sponsorship:' . $requestId . ':received'])->fetchColumn(0);
    $check($received === 'Sponsorship request received', 'Sponsor receives incoming request wording');
    $controller = new UsersController($request($school, 'POST', [], 'cancelcrossschoolrequest'));
    $controller->loadModel('Conventionregistrationstudents');
    $controller->cancelcrossschoolrequest((string)$requestId);
    $check((int)$db->execute('SELECT COUNT(*) FROM school_notifications WHERE event_key=?', ['sponsorship:' . $requestId . ':cancelled'])->fetchColumn(0) === 2,
        'Actual cancellation creates updates for both schools');
    $controller->cancelcrossschoolrequest((string)$requestId);
    $check((int)$db->execute('SELECT COUNT(*) FROM school_notifications WHERE event_key=?', ['sponsorship:' . $requestId . ':cancelled'])->fetchColumn(0) === 2,
        'Repeated cancellation does not duplicate messages');
    for ($i = 0; $i < 28; $i++) {
        $db->execute("INSERT INTO school_notifications (school_id,event_key,kind,subject,sender,body,created) VALUES (?,?,'email','Page fixture','Portal','Fixture',NOW())",
            [$school, 'fixture-page-' . $i]);
    }
    $first = $service->inbox($school, 'inbox', 1);
    $second = $service->inbox($school, 'inbox', 2);
    $check(count($first['messages']) === 25 && $first['pages'] === 2 && count($second['messages']) === 6, 'Pagination has exact 25-message pages');
    $check(!array_intersect(array_column($first['messages'], 'id'), array_column($second['messages'], 'id')), 'Stable ordering prevents duplicate page entries');
    $check($service->inbox($school, 'inbox', 999)['page'] === 2, 'Out-of-range pages clamp to final page');
    $resetMailer = new \App\Mailer\PortalMailer();
    $resetMailer->viewBuilder()->setTemplate('password_reset')->setLayout('password_reset');
    $resetMailer->setTransport($memory)->setTo($schools[0]['email_address'])->setFrom('portal@example.invalid')
        ->setEmailFormat('both')->setSubject('Password reset fixture')->setViewVars([
            'notification' => false, 'localTest' => true, 'recipientName' => 'Fixture School',
            'baseUrl' => 'https://example.invalid', 'contactEmail' => 'events@example.invalid',
            'subject' => 'Password reset fixture',
            'links' => [['label' => 'School account', 'url' => 'https://example.invalid/users/resetpassword#fixture-secret-token']],
        ])->deliver();
    $check(str_contains($resetMailer->getMessage()->getBodyHtml(), 'fixture-secret-token')
        && str_contains($resetMailer->getMessage()->getBodyText(), 'fixture-secret-token'),
        'Direct password reset mailer still renders separate HTML and text reset links');
    $resetNotice = $db->execute("SELECT * FROM school_notifications WHERE school_id=? AND subject='Password reset fixture'", [$school])->fetch('assoc');
    $check((bool)$resetNotice && !str_contains($resetNotice['body'], 'fixture-secret-token'), 'Direct reset emails create notices without storing reset tokens');
} finally {
    $db->rollback(true);
}
echo "PASS: $checks school notification checks. All fixtures rolled back; no external email sent.\n";
