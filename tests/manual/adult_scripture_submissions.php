<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Service\AdultScriptureSubmissions;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Laminas\Diactoros\UploadedFile;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run Scripture fixtures locally only.');
}
\Cake\Routing\Router::reload();
(require dirname(__DIR__, 2) . '/config/routes.php')(\Cake\Routing\Router::createRouteBuilder('/'));
$route = \Cake\Routing\Router::parseRequest(new ServerRequest(['url' => '/scripture']));
if (($route['controller'] ?? null) !== 'Scripture' || ($route['action'] ?? null) !== 'index') {
    throw new RuntimeException('The Scripture URL must route to the Scripture index.');
}
$db = ConnectionManager::get('default');
$service = new AdultScriptureSubmissions($db);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try {
        $call();
    } catch (DomainException $error) {
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};
foreach (['adult_scripture_submissions', 'conventions', 'conventionseasons', 'conventionseasonevents', 'conventionregistrations', 'conventionregistrationteachers'] as $table) {
    $check($db->execute('SHOW TABLE STATUS WHERE Name=?', [$table])->fetch('assoc')['Engine'] === 'InnoDB', "$table must support rollback");
}
$clone = static function (string $table, array $row, array $changes) use ($db): int {
    unset($row['id']);
    $row = array_replace($row, $changes);
    $db->execute("INSERT INTO $table (`" . implode('`,`', array_keys($row)) . '`) VALUES (' .
        implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
    return (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
};
$upload = static function (string $name = 'fixture.pdf'): UploadedFile {
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, '%PDF-1.4 fixture');
    rewind($stream);
    return new UploadedFile($stream, 16, UPLOAD_ERR_OK, $name, 'application/pdf');
};
$db->begin();
$files = [];
try {
    $judge = $db->execute("SELECT id FROM users WHERE user_type='Judge' AND status=1 AND activation_status=1 LIMIT 1")->fetch('assoc');
    $supervisor = $db->execute("SELECT id,school_id FROM users WHERE user_type='Teacher_Parent' AND school_id>0 AND status=1 AND activation_status=1 LIMIT 1")->fetch('assoc');
    $source = $db->execute('SELECT cs.* FROM conventionseasons cs JOIN conventionseasonevents ce ON ce.conventionseasons_id=cs.id JOIN events e ON e.id=ce.event_id WHERE e.certificate_print=1 LIMIT 1')->fetch('assoc');
    if (!$judge || !$supervisor || !$source) {
        throw new RuntimeException('Existing judge, supervisor and Scripture award data are required.');
    }
    $convention = $db->execute('SELECT * FROM conventions WHERE id=?', [$source['convention_id']])->fetch('assoc');
    $slug = 'adult-scripture-fixture-' . bin2hex(random_bytes(6));
    $conventionId = $clone('conventions', $convention, ['slug' => $slug]);
    $seasonId = $clone('conventionseasons', $source, [
        'slug' => $slug, 'convention_id' => $conventionId, 'submissions_open' => 1,
        'registration_start_date' => '2020-01-01 00:00:00', 'registration_end_date' => '2099-12-31 23:59:59',
    ]);
    $event = $db->execute("SELECT ce.* FROM conventionseasonevents ce JOIN events e ON e.id=ce.event_id
        WHERE LOWER(TRIM(e.event_name))='silver apple award open' LIMIT 1")->fetch('assoc');
    $clone('conventionseasonevents', $event, ['conventionseasons_id' => $seasonId, 'convention_id' => $conventionId]);
    $registration = $db->execute('SELECT * FROM conventionregistrations LIMIT 1')->fetch('assoc');
    $judgeReg = $clone('conventionregistrations', $registration, [
        'slug' => $slug . '-judge', 'user_id' => $judge['id'], 'convention_id' => $conventionId,
        'conventionseason_id' => $seasonId, 'season_id' => $source['season_id'], 'season_year' => $source['season_year'], 'status' => 1,
    ]);
    $schoolReg = $clone('conventionregistrations', $registration, [
        'slug' => $slug . '-school', 'user_id' => $supervisor['school_id'], 'convention_id' => $conventionId,
        'conventionseason_id' => $seasonId, 'season_id' => $source['season_id'], 'season_year' => $source['season_year'], 'status' => 1,
    ]);
    $teacher = $db->execute('SELECT * FROM conventionregistrationteachers LIMIT 1')->fetch('assoc');
    $clone('conventionregistrationteachers', $teacher, [
        'slug' => $slug, 'conventionregistration_id' => $schoolReg, 'teacher_id' => $supervisor['id'],
        'user_id' => $supervisor['school_id'], 'convention_id' => $conventionId,
        'season_id' => $source['season_id'], 'season_year' => $source['season_year'], 'status' => 1,
    ]);
    $check((int)$service->context((int)$judge['id'], 'Judge', $judgeReg)['conventionseason_id'] === $seasonId, 'Registered judge can access their convention');
    $check((int)$service->context((int)$supervisor['id'], 'Teacher_Parent', $schoolReg)['conventionseason_id'] === $seasonId, 'Registered supervisor can access their convention');
    $reject(fn() => $service->context((int)$judge['id'], 'Judge', $schoolReg), 'A judge cannot use another registration');
    $reject(fn() => $service->context((int)$supervisor['id'], 'Teacher_Parent', $judgeReg), 'A supervisor cannot use another registration');
    $awards = $service->awards($seasonId);
    $check(count($awards) === 1 && (int)$awards[0]['id'] === (int)$event['event_id'], 'Only enabled Open Scripture awards are listed');
    $books = $service->books($awards[0]);
    $check(count($books) >= 2, 'The Silver Apple fixture needs at least two books');
    $bookId = (int)$books[0]['id'];
    $secondBookId = (int)$books[1]['id'];
    $reject(fn() => $service->submit($seasonId, (int)$judge['id'], (int)$event['event_id'], [], $upload()), 'An empty book selection is rejected');
    $reject(fn() => $service->submit($seasonId, (int)$judge['id'], (int)$event['event_id'], [99999999], $upload()), 'Invalid books are rejected');
    $reject(fn() => $service->submit($seasonId, (int)$judge['id'], (int)$event['event_id'], [$bookId], $upload('fixture.php')), 'Unsafe extensions are rejected');
    foreach ([$judge['id'], $supervisor['id']] as $userId) {
        $service->submit($seasonId, (int)$userId, (int)$event['event_id'], [$bookId], $upload());
        $files[] = $service->filePath($service->forUser($seasonId, (int)$userId)[0]);
    }
    $submissions = $service->forSeason($seasonId);
    $check(count($submissions) === 2, 'Judge and supervisor uploads both appear in the admin season list');
    foreach ($submissions as $submission) {
        $path = $service->filePath($submission);
        $check(is_file($path) && (int)$service->books($submission)[0]['id'] === $bookId, 'File and selected books are retained for the certificate');
    }
    $check(!in_array((int)$submissions[0]['id'], array_map('intval', array_column($service->forSeason((int)$source['id']), 'id')), true),
        'Fixture submissions do not leak into another convention');
    $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
    $session->write(['user_id' => (int)$judge['id'], 'user_type' => 'Judge', 'sess_selected_convention_registration_id' => $judgeReg, 'admin_id' => 1]);
    $request = static function (string $controller, string $action, ?string $prefix = null, string $method = 'GET', array $data = []) use ($session): ServerRequest {
        $request = new ServerRequest(['session' => $session, 'environment' => ['REQUEST_METHOD' => $method], 'post' => $data,
            'params' => ['controller' => $controller, 'action' => $action, 'prefix' => $prefix, 'plugin' => null, '_ext' => null]]);
        \Cake\Routing\Router::setRequest($request);
        return $request;
    };
    $admin = new \App\Controller\Admin\ConventionsController($request('Conventions', 'adultscripturecertificate', 'Admin'));
    $admin->adultscripturecertificate((string)$submissions[0]['id']);
    $view = new \App\View\AppView($admin->getRequest());
    $view->disableAutoLayout();
    $view->set($admin->viewBuilder()->getVars());
    $html = $view->render('Admin/Conventions/adultscripturecertificate');
    $check(str_contains($html, $books[0]['book_name']) && str_contains($html, 'window.print()'), 'Adult certificate displays selected books and supports printing');
    $download = (new \App\Controller\Admin\ConventionsController($request('Conventions', 'adultscripturefile', 'Admin')))->adultscripturefile((string)$submissions[0]['id']);
    $check(str_contains($download->getHeaderLine('Content-Disposition'), 'attachment')
        && str_contains($download->getHeaderLine('Cache-Control'), 'no-store'), 'Admin file download is private and served as an attachment');
    $own = array_values(array_filter($submissions, fn(array $item): bool => (int)$item['user_id'] === (int)$judge['id']))[0];
    $other = array_values(array_filter($submissions, fn(array $item): bool => (int)$item['user_id'] === (int)$supervisor['id']))[0];
    $secondEvent = $db->execute("SELECT ce.* FROM conventionseasonevents ce JOIN events e ON e.id=ce.event_id
        WHERE LOWER(TRIM(e.event_name))='golden apple award open' LIMIT 1")->fetch('assoc');
    if (!$secondEvent) {
        throw new RuntimeException('An existing Golden Apple Open award is required.');
    }
    $clone('conventionseasonevents', $secondEvent, ['conventionseasons_id' => $seasonId, 'convention_id' => $conventionId]);
    $judgePage = new \App\Controller\ScriptureController($request('Scripture', 'index'));
    $judgePage->index();
    $judgeView = new \App\View\AppView($judgePage->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $judgeView->disableAutoLayout();
    $judgeView->set($judgePage->viewBuilder()->getVars());
    $judgeHtml = $judgeView->render('Scripture/index');
    $check(str_contains($judgeHtml, 'Silver Apple Award') && !str_contains($judgeHtml, 'Silver Apple Award OPEN'),
        'The judge page displays the award name without OPEN');
    $check(substr_count($judgeHtml, 'data-bs-toggle="modal"') === 1 && str_contains($judgeHtml, 'id="scripture-award"')
        && str_contains($judgeHtml, 'name="_csrfToken"') && str_contains($judgeHtml, 'name="book_ids[]"'),
        'Judge Scripture page renders one upload popup with an award selector and protected forms');
    $check(str_contains($judgeHtml, 'Golden Apple Award') && !str_contains($judgeHtml, 'Golden Apple Award OPEN')
        && str_contains($judgeHtml, 'data-award-id="' . (int)$event['event_id'] . '" hidden')
        && str_contains($judgeHtml, 'data-award-id="' . (int)$secondEvent['event_id'] . '" hidden'),
        'Both enabled awards have initially hidden, separate forms');
    foreach ($judgePage->viewBuilder()->getVar('awards') as $availableAward) {
        $check(!str_contains($judgeHtml, h($availableAward['display_name']) . ' (' . h($availableAward['event_id_number']) . ')'),
            'Award choices and forms do not show event numbers');
    }
    $check(str_contains($judgeHtml, 'type="search"') && substr_count($judgeHtml, 'type="checkbox" name="book_ids[]"') >= count($books),
        'The popup renders searchable book checkboxes');
    $check(str_contains($judgeHtml, '<noscript>') && str_contains($judgeHtml, '/scripture/submit/' . (int)$event['event_id'])
        && str_contains($judgeHtml, '/scripture/submit/' . (int)$secondEvent['event_id']),
        'Both awards remain accessible without JavaScript');
    $check(count($judgePage->viewBuilder()->getVar('submissions')) === 1,
        'The judge page lists only their own submissions');
    $submitPage = new \App\Controller\ScriptureController($request('Scripture', 'submit'));
    $submitPage->submit((string)$event['event_id']);
    $submitView = new \App\View\AppView($submitPage->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $submitView->disableAutoLayout();
    $submitView->set($submitPage->viewBuilder()->getVars());
    $submitHtml = $submitView->render('Scripture/submit');
    $check(str_contains($submitHtml, 'book_ids[]') && str_contains($submitHtml, 'name="document"') && str_contains($submitHtml, '_csrfToken'),
        'The Scripture form offers book selection, file upload and CSRF protection');
    $check(str_contains($submitHtml, 'Silver Apple Award') && !str_contains($submitHtml, 'Silver Apple Award OPEN'),
        'The standalone form displays the award name without OPEN');
    $check(!str_contains($submitHtml, ' checked'), 'Silver Apple books remain unselected for manual choice');
    foreach (['Golden Apple', 'Golden Lamb', 'Golden Harp', 'Christian Soldier', 'Christian Worker'] as $awardName) {
        $defaultAward = array_replace($submitPage->viewBuilder()->getVar('award'), [
            'event_name' => $awardName . ' Award OPEN', 'display_name' => $awardName . ' Award',
        ]);
        $defaultHtml = $submitView->element('scripture_submission_form', [
            'award' => $defaultAward, 'books' => $books,
            'context' => $submitPage->viewBuilder()->getVar('context'), 'inModal' => false,
        ]);
        $check(substr_count($defaultHtml, ' checked') === count($books)
            && str_contains($defaultHtml, count($books) . ' selected.'),
            $awardName . ' books are preselected with an accurate selected count');
    }
    $ownDownload = (new \App\Controller\ScriptureController($request('Scripture', 'download')))->download((string)$own['id']);
    $check(str_contains($ownDownload->getHeaderLine('Content-Disposition'), 'attachment'), 'Judge can download their own submission');
    try {
        (new \App\Controller\ScriptureController($request('Scripture', 'download')))->download((string)$other['id']);
        throw new RuntimeException('Another person’s submission was exposed');
    } catch (\Cake\Http\Exception\NotFoundException $error) {
        $check(true, 'Judge cannot download another person’s submission');
    }
    $post = new \App\Controller\ScriptureController($request('Scripture', 'submit', null, 'POST', ['conventionseason_id' => (string)$seasonId, 'book_ids' => [$bookId, $secondBookId], 'document' => $upload()]));
    $response = $post->submit((string)$event['event_id']);
    $created = $service->forUser($seasonId, (int)$judge['id'])[0];
    $files[] = $service->filePath($created);
    $check($response->getStatusCode() === 303 && count($service->forUser($seasonId, (int)$judge['id'])) === 2,
        'Judge submission controller stores an upload and redirects');
    $check(array_map('intval', array_column($service->books($created), 'id')) === [$bookId, $secondBookId],
        'A submission retains multiple selected books');
    $wrongSeason = new \App\Controller\ScriptureController($request('Scripture', 'submit', null, 'POST', ['conventionseason_id' => '99999999', 'book_ids' => [$bookId], 'document' => $upload()]));
    $wrongSeason->submit((string)$event['event_id']);
    $check(count($service->forUser($seasonId, (int)$judge['id'])) === 2,
        'A stale form cannot submit to a different selected convention');
    $session->write(['user_id' => (int)$supervisor['id'], 'user_type' => 'Teacher_Parent', 'sess_selected_convention_registration_id' => $schoolReg]);
    $supervisorPage = new \App\Controller\ScriptureController($request('Scripture', 'index'));
    $supervisorPage->index();
    $supervisorView = new \App\View\AppView($supervisorPage->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $supervisorView->disableAutoLayout();
    $supervisorView->set($supervisorPage->viewBuilder()->getVars());
    $supervisorHtml = $supervisorView->render('Scripture/index');
    $check(str_contains($supervisorHtml, 'data-bs-toggle="modal"') && str_contains($supervisorHtml, 'name="_csrfToken"'),
        'Supervisor Scripture page renders the same protected popup');
    $check(str_contains($supervisorHtml, 'Silver Apple Award') && !str_contains($supervisorHtml, 'Silver Apple Award OPEN'),
        'The supervisor page displays award names without OPEN');
    $supervisorPost = new \App\Controller\ScriptureController($request('Scripture', 'submit', null, 'POST', ['conventionseason_id' => (string)$seasonId, 'book_ids' => [$bookId], 'document' => $upload()]));
    $supervisorResponse = $supervisorPost->submit((string)$event['event_id']);
    $files[] = $service->filePath($service->forUser($seasonId, (int)$supervisor['id'])[0]);
    $check($supervisorResponse->getStatusCode() === 303 && count($service->forUser($seasonId, (int)$supervisor['id'])) === 2,
        'Supervisor submission controller stores their own upload');
    $session->write(['user_id' => (int)$judge['id'], 'user_type' => 'Judge', 'sess_selected_convention_registration_id' => $judgeReg]);
    $db->execute('UPDATE conventionseasons SET submissions_open=0 WHERE id=?', [$seasonId]);
    $lockedPage = new \App\Controller\ScriptureController($request('Scripture', 'index'));
    $lockedPage->index();
    $lockedView = new \App\View\AppView($lockedPage->getRequest());
    $lockedView->disableAutoLayout();
    $lockedView->set($lockedPage->viewBuilder()->getVars());
    $lockedHtml = $lockedView->render('Scripture/index');
    $check(str_contains($lockedHtml, 'Submissions are currently locked.') && !str_contains($lockedHtml, 'data-bs-toggle="modal"'),
        'Locked submissions show a message instead of upload popups');
    $locked = new \App\Controller\ScriptureController($request('Scripture', 'submit', null, 'POST', ['conventionseason_id' => (string)$seasonId, 'book_ids' => [$bookId], 'document' => $upload()]));
    $locked->submit((string)$event['event_id']);
    $check(count($service->forUser($seasonId, (int)$judge['id'])) === 2, 'Locked submissions reject uploads');
    $db->execute("UPDATE conventionseasons SET submissions_open=1, registration_end_date='2000-01-01 00:00:00' WHERE id=?", [$seasonId]);
    $closedPage = new \App\Controller\ScriptureController($request('Scripture', 'index'));
    $closedPage->index();
    $check($closedPage->viewBuilder()->getVar('unavailableReason') === 'Registration is closed for this convention.',
        'Closed registrations do not offer upload popups');
    $list = new \App\Controller\Admin\ConventionsController($request('Conventions', 'scriptureawardslist', 'Admin'));
    $list->scriptureawardslist($slug, $slug);
    $adminView = new \App\View\AppView($list->getRequest());
    $adminView->disableAutoLayout();
    $adminView->set($list->viewBuilder()->getVars());
    $listHtml = $adminView->render('Admin/Conventions/scriptureawardslist');
    $check(str_contains($listHtml, 'Supervisor and Judge Scripture Submissions') && str_contains($listHtml, 'Print certificate'),
        'Admin list shows adult submissions even without student submissions');
    $middleware = new \App\Middleware\ScopedPublicationCsrfMiddleware();
    $csrfIndexHandler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return (new \Cake\Http\Response())->withHeader('X-Test-Has-Csrf', $request->getAttribute('csrfToken') ? 'yes' : 'no');
        }
    };
    $csrfIndex = $middleware->process($request('Scripture', 'index'), $csrfIndexHandler);
    $check($csrfIndex->getHeaderLine('X-Test-Has-Csrf') === 'yes', 'Scripture index receives a CSRF token for popup forms');
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return new \Cake\Http\Response();
        }
    };
    try {
        $middleware->process($request('Scripture', 'submit', null, 'POST'), $handler);
        throw new RuntimeException('Missing Scripture CSRF token was accepted');
    } catch (\Cake\Http\Exception\InvalidCsrfTokenException $error) {
        $check(true, 'Scripture upload requires a CSRF token');
    }
    echo "PASS: $checks adult Scripture checks. Fixtures rolled back.\n";
} finally {
    $db->rollback();
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
