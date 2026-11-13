<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\ConventionregistrationsController;
use App\Controller\EventsubmissionsController;
use App\Service\StudentEventSelections;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run these transactional fixture tests locally only.');
}
\Cake\Routing\Router::reload();
(require dirname(__DIR__, 2) . '/config/routes.php')(\Cake\Routing\Router::createRouteBuilder('/'));
$db = ConnectionManager::get('default');
$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $count++;
};
$reject = static function (callable $call, string $message, string $class = DomainException::class) use ($check): void {
    try {
        $call();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            throw $error;
        }
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};
$clone = static function (string $table, int $id, array $overrides) use ($db): int {
    $row = $db->execute("SELECT * FROM $table WHERE id = ?", [$id])->fetch('assoc');
    if (!$row) {
        throw new RuntimeException("Missing fixture source in $table");
    }
    unset($row['id']);
    $row = array_replace($row, $overrides);
    if (isset($row['slug'])) {
        $row['slug'] = 'event-review-test-' . bin2hex(random_bytes(12));
    }
    $db->execute("INSERT INTO $table (`" . implode('`,`', array_keys($row)) . '`) VALUES (' .
        implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
    return (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
};
foreach (['conventionregistrations', 'conventionregistrationstudents', 'conventionseasons', 'conventionseasonevents',
    'events', 'crstudentevents', 'eventsubmissions', 'student_event_reviews'] as $table) {
    $meta = $db->execute('SHOW TABLE STATUS WHERE Name = ?', [$table])->fetch('assoc');
    $check($meta && strcasecmp((string)$meta['Engine'], 'InnoDB') === 0, "InnoDB required for $table");
}
$db->enableSavePoints();
$db->begin();
try {
    $source = $db->execute(
        "SELECT r.*,s.id AS student_reg_id,s.student_id,t.id AS teacher_id,u.gender AS student_gender
         FROM conventionregistrations r
         JOIN conventionregistrationstudents s ON s.conventionregistration_id=r.id AND s.status=1
         JOIN users u ON u.id=s.student_id AND u.school_id=r.user_id AND u.status=1 AND u.activation_status=1
         JOIN users school ON school.id=r.user_id AND school.status=1 AND school.activation_status=1
         JOIN users t ON t.school_id=r.user_id AND t.user_type='Teacher_Parent' AND t.status=1 AND t.activation_status=1
         WHERE r.status=1 AND u.birth_year BETWEEN YEAR(NOW())-15 AND YEAR(NOW())-8 LIMIT 1"
    )->fetch('assoc');
    if (!$source) {
        throw new RuntimeException('An active school with an eligible student and supervisor is required.');
    }
    $school = (int)$source['user_id'];
    $student = (int)$source['student_id'];
    $teacher = (int)$source['teacher_id'];
    $outsider = (int)$db->execute("SELECT id FROM users WHERE user_type='School' AND status=1 AND activation_status=1 AND id <> ? LIMIT 1", [$school])->fetchColumn(0);
    $season = $clone('conventionseasons', (int)$source['conventionseason_id'], [
        'registration_start_date' => date('Y-m-d H:i:s', time() - 86400),
        'registration_end_date' => date('Y-m-d H:i:s', time() + 86400),
        'submissions_open' => 1, 'min_events_student' => 1, 'max_events_student' => 10,
    ]);
    $reg = $clone('conventionregistrations', (int)$source['id'], ['conventionseason_id' => $season, 'season_year' => (int)date('Y')]);
    $crs = $clone('conventionregistrationstudents', (int)$source['student_reg_id'], [
        'conventionregistration_id' => $reg, 'season_year' => (int)date('Y'),
        'teacher_parent_id' => $teacher, 'event_ids' => null,
    ]);
    $slug = $db->execute('SELECT slug FROM conventionregistrationstudents WHERE id=?', [$crs])->fetchColumn(0);
    $eventSource = (int)$db->execute('SELECT e.id FROM events e JOIN divisions d ON d.id=e.division_id LIMIT 1')->fetchColumn(0);
    $event = $clone('events', $eventSource, [
        'event_name' => 'Review Fixture Event', 'event_id_number' => 'REVIEW-TEST', 'event_grp_name' => 2,
        'event_gender' => '', 'event_type' => 2, 'group_event_yes_no' => 0, 'auto_submission' => 0,
        'upload_type' => 'nil', 'context_box' => 1, 'book_ids' => null, 'status' => 1,
    ]);
    $auto = $clone('events', $event, ['auto_submission' => 1]);
    $group = $clone('events', $event, ['group_event_yes_no' => 1]);
    foreach ([$event, $auto, $group] as $id) {
        $db->execute('INSERT INTO conventionseasonevents (conventionseasons_id,event_id) VALUES (?,?)', [$season, $id]);
    }
    $eventSlug = $db->execute('SELECT slug FROM events WHERE id=?', [$event])->fetchColumn(0);
    $service = new StudentEventSelections($db);
    $allowed = [$event, $auto, $group];
    $limits = ['min_events_student' => 1, 'max_events_student' => 10];
    $autoCalls = 0;
    $callback = static function (array $data) use (&$autoCalls): bool {
        $autoCalls++;
        return true;
    };
    $save = static fn(int $actor, string $role, array $ids, ?int $revision = null): string =>
        $service->save($crs, $actor, $role, $ids, $allowed, $limits, $callback, $revision);
    $live = static fn(): int => (int)$db->execute('SELECT COUNT(*) FROM crstudentevents WHERE conventionregistration_id=?', [$reg])->fetchColumn(0);
    $check($save($student, 'Student', [$event, $auto, $event]) === 'pending', 'Student saves pending choices');
    $check($live() === 0 && $autoCalls === 0, 'Pending choices create no event entries or automatic submissions');
    $check(!$db->execute('SELECT event_ids FROM conventionregistrationstudents WHERE id=?', [$crs])->fetchColumn(0), 'Pending choices leave live registration unchanged');
    $check(!$service->studentLocked($service->context($crs, $student, 'Student')), 'Pending choices remain editable');
    $reject(fn() => $service->assertUploadAllowed($crs, $event, $student), 'Pending individual upload denied');
    $reject(fn() => $service->assertUploadAllowed($crs, $group, $student), 'Pending group upload denied');
    $revision = (int)$service->review($crs)['revision'];
    $reject(fn() => $save($student, 'Student', [], $revision), 'Student cannot approve');
    $reject(fn() => $save($teacher, 'Teacher_Parent', [], $revision), 'Supervisor cannot approve');
    $reject(fn() => $save($outsider, 'School', [], $revision), 'Foreign school cannot approve');
    $reject(fn() => $save($student, 'Student', ['invalid']), 'Malformed choices rejected');
    $reject(fn() => $save($student, 'Student', [$eventSource]), 'Unassigned event rejected');
    $reject(fn() => $save($student, 'Student', []), 'Minimum event count enforced');
    $reject(fn() => $service->save($crs, $student, 'Student', [$event, $auto], $allowed,
        ['min_events_student' => 1, 'max_events_student' => 1], $callback), 'Maximum event count enforced');

    $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
    $request = static function (int $actor, string $role, string $action, array $data = [], string $method = 'GET', string $controller = 'Conventionregistrations') use ($session, $school, $student, $reg): ServerRequest {
        $session->delete('school_student_access');
        $session->write(['user_id' => $actor, 'user_type' => $role, 'sess_selected_convention_registration_id' => $reg]);
        if ($role === 'Student') {
            $session->write('school_student_access', ['school_id' => $school, 'student_id' => $student]);
        }
        $request = new ServerRequest([
            'url' => '/' . strtolower($controller) . '/' . $action,
            'session' => $session, 'environment' => ['REQUEST_METHOD' => $method],
            'params' => ['controller' => $controller, 'action' => $action, 'pass' => [], 'plugin' => null, 'prefix' => null, '_ext' => null],
            'post' => $data,
        ]);
        \Cake\Routing\Router::setRequest($request);
        return $request;
    };
    $normalDivision = (int)$db->execute(
        "SELECT d.id FROM divisions d JOIN eventcategories c ON c.id=d.eventcategory_id WHERE c.name='Academics' LIMIT 1"
    )->fetchColumn(0);
    $peDivision = (int)$db->execute(
        "SELECT d.id FROM divisions d JOIN eventcategories c ON c.id=d.eventcategory_id WHERE c.name='Physical Education' LIMIT 1"
    )->fetchColumn(0);
    $open = $clone('events', $event, ['event_grp_name' => 4, 'division_id' => $normalDivision]);
    $peOpen = $clone('events', $event, ['event_grp_name' => 4, 'division_id' => $peDivision]);
    $peU17 = $clone('events', $event, ['event_grp_name' => 3, 'division_id' => $peDivision]);
    $peU14 = $clone('events', $event, ['event_grp_name' => 1, 'division_id' => $peDivision]);
    $openTeam = $clone('events', $open, ['group_event_yes_no' => 1]);
    foreach ([$open, $peOpen, $peU17, $peU14, $openTeam] as $id) {
        $db->execute('INSERT INTO conventionseasonevents (conventionseasons_id,event_id) VALUES (?,?)', [$season, $id]);
    }
    $birthYear = (int)$db->execute('SELECT birth_year FROM users WHERE id=?', [$student])->fetchColumn(0);
    foreach ([13, 14, 15, 16, 17] as $age) {
        $ageReg = $clone('conventionregistrations', $reg, ['season_year' => $birthYear + $age]);
        foreach ([[$open, 16, PHP_INT_MAX], [$peOpen, 17, PHP_INT_MAX], [$peU17, 14, 16]] as [$openEvent, $minimum, $maximum]) {
            $ageCrs = $clone('conventionregistrationstudents', $crs, [
                'conventionregistration_id' => $ageReg, 'season_year' => $birthYear + $age, 'event_ids' => null,
            ]);
            $ageSlug = $db->execute('SELECT slug FROM conventionregistrationstudents WHERE id=?', [$ageCrs])->fetchColumn(0);
            $ageRequest = $request($student, 'Student', 'managestudentevents');
            $session->write('sess_selected_convention_registration_id', $ageReg);
            $ageController = new ConventionregistrationsController($ageRequest);
            $ageController->managestudentevents($ageSlug);
            $visible = array_map('intval', $ageController->viewBuilder()->getVar('arrConvSeasonEventsList'));
            $eligible = $age >= $minimum && $age <= $maximum;
            $check(in_array($openEvent, $visible, true) === $eligible, "Individual event $openEvent visibility respects age range at age $age");
            $check(in_array($peU14, $visible, true) === ($age < 14), "PE U14 visibility respects cutoff at age $age");
            $check(in_array($openTeam, $visible, true), "Open team visibility unchanged at age $age");
            $ageSave = static fn(?int $revision = null): string => $service->save(
                $ageCrs, $revision === null ? $student : $school, $revision === null ? 'Student' : 'School',
                [$openEvent], [$openEvent], $limits, $callback, $revision
            );
            if (!$eligible) {
                $reject(fn() => $ageSave(), "Forged individual save denied at age $age for event $openEvent");
                $db->execute(
                    "INSERT INTO student_event_reviews (student_registration_id,status,event_ids,revision) VALUES (?,'pending',?,1)",
                    [$ageCrs, (string)$openEvent]
                );
                $reject(fn() => $ageSave(1), "Old pending individual choice cannot be approved at age $age for event $openEvent");
                $check($service->review($ageCrs)['status'] === 'pending', 'Rejected approval preserves pending selection');
            } else {
                $check($ageSave() === 'pending' && $ageSave(1) === 'approved', "Open individual save and approval allowed at age $age for minimum $minimum");
            }
        }
    }
    $controller = new ConventionregistrationsController($request($school, 'School', 'studentevents'));
    $controller->studentevents();
    $view = new \App\View\AppView($controller->getRequest()->withAttribute('csrfToken', str_repeat('a', 32)));
    $view->set($controller->viewBuilder()->getVars());
    $html = $view->element('Conventionregistrations/studentevents');
    $check(str_contains($html, 'Pending review') && str_contains($html, 'Review choices') && str_contains($html, '_csrfToken'), 'School list renders pending review and CSRF controls');
    $controller = new ConventionregistrationsController($request($student, 'Student', 'packageregistration'));
    $controller->packageregistration();
    $view = new \App\View\AppView($controller->getRequest());
    $view->set($controller->viewBuilder()->getVars());
    $html = $view->element('Conventionregistrations/packageregistration');
    $check(str_contains($html, 'pending school review') && !preg_match('/href="[^"]*submitstudentevent/', $html), 'Pending checklist explains review and has no upload link');
    $conventionType = (int)$db->execute('SELECT convention_type FROM conventions WHERE id=?', [$source['convention_id']])->fetchColumn(0);
    foreach ([
        ['event_gender', strtoupper(substr((string)$source['student_gender'], 0, 1)) === 'M' ? 'F' : 'M', ''],
        ['event_grp_name', 999, 2],
        ['event_type', in_array($conventionType, [0, 3], true) ? 1 : 0, 2],
    ] as [$column, $invalid, $original]) {
        $db->execute("UPDATE events SET $column=? WHERE id=?", [$invalid, $event]);
        $snapshot = $service->review($crs);
        $controller = new ConventionregistrationsController($request($student, 'Student', 'managestudentevents', ['eventIDS' => [$event]], 'POST'));
        $controller->managestudentevents($slug);
        $check($service->review($crs) === $snapshot, "Forged choice with invalid $column rejected server-side");
        $db->execute("UPDATE events SET $column=? WHERE id=?", [$original, $event]);
    }
    $controller = new ConventionregistrationsController($request($school, 'School', 'reviewstudentevents'));
    $controller->reviewstudentevents($slug);
    $check(count($controller->viewBuilder()->getVar('events')) === 2, 'Review displays the exact stored selection');
    $view = new \App\View\AppView($controller->getRequest()->withAttribute('csrfToken', str_repeat('a', 32)));
    $view->disableAutoLayout();
    $view->set($controller->viewBuilder()->getVars());
    $html = $view->render('Conventionregistrations/reviewstudentevents');
    $check(str_contains($html, 'Review Fixture Event') && str_contains($html, 'name="revision"')
        && str_contains($html, 'name="confirm_approve"') && str_contains($html, '_csrfToken'), 'Review page renders saved events and confirmed revision-bound CSRF form');
    $reject(fn() => (new ConventionregistrationsController($request($student, 'Student', 'reviewstudentevents')))->reviewstudentevents($slug),
        'Student-view session cannot approve', \Cake\Http\Exception\ForbiddenException::class);
    $reject(fn() => (new ConventionregistrationsController($request($teacher, 'Teacher_Parent', 'reviewstudentevents')))->reviewstudentevents($slug),
        'Supervisor cannot access school review', \Cake\Http\Exception\ForbiddenException::class);
    $reject(fn() => (new ConventionregistrationsController($request($outsider, 'School', 'reviewstudentevents')))->reviewstudentevents($slug),
        'Foreign school cannot access school review', \Cake\Http\Exception\ForbiddenException::class);
    $controller = new EventsubmissionsController($request($student, 'Student', 'submitstudentevent', [], 'GET', 'Eventsubmissions'));
    $reject(fn() => $controller->submitstudentevent($slug, $eventSlug), 'Direct pending upload URL denied', \Cake\Http\Exception\ForbiddenException::class);

    $check($save($student, 'Student', [$event, $group]) === 'pending', 'Student can revise a pending selection');
    $reject(fn() => $save($school, 'School', [], $revision), 'Stale approval revision rejected');
    $revision = (int)$service->review($crs)['revision'];
    $check($save($student, 'Student', [$event, $group]) === 'pending', 'Student revisions still require school approval');
    $revision = (int)$service->review($crs)['revision'];
    $controller = new ConventionregistrationsController($request($school, 'School', 'reviewstudentevents',
        ['revision' => $revision, 'confirm_approve' => '1', 'eventIDS' => [$auto]], 'POST'));
    $controller->reviewstudentevents($slug);
    $review = $service->review($crs);
    $check($review['status'] === 'approved' && $live() === 2, 'School review action registers approved choices');
    $check($review['event_ids'] === implode(',', [$event, $group]), 'Approval ignores forged event IDs and uses reviewed revision');
    $check((int)$review['approved_by'] === $school, 'Approval records school actor');
    $controller = new ConventionregistrationsController($request($school, 'School', 'studentevents'));
    $controller->studentevents();
    $view = new \App\View\AppView($controller->getRequest());
    $view->set($controller->viewBuilder()->getVars());
    $html = $view->element('Conventionregistrations/studentevents');
    $check(str_contains($html, 'cr-school-review-approved') && str_contains($html, 'Approved') && !str_contains($html, 'Review choices'), 'Approved list shows green tick and removes review action');
    $service->assertUploadAllowed($crs, $event, $student);
    $check(true, 'Approved upload allowed');
    $uploadController = new EventsubmissionsController($request($school, 'School', 'submitstudentevent', [], 'GET', 'Eventsubmissions'));
    $uploadController->submitstudentevent($slug, $eventSlug);
    $uploadVars = $uploadController->viewBuilder()->getVars();
    $renderBookChoices = static function (string $number, string $name, array $books, string $method = 'GET', array $data = []) use ($request, $school, $uploadVars): string {
        $eventForView = clone $uploadVars['eventD'];
        $eventForView->event_id_number = $number;
        $eventForView->event_name = $name;
        $eventForView->book_ids = implode(',', array_keys($books));
        $view = new \App\View\AppView($request($school, 'School', 'submitstudentevent', $data, $method, 'Eventsubmissions'));
        $view->disableAutoLayout();
        $view->set(array_replace($uploadVars, ['eventD' => $eventForView, 'booksDD' => $books]));
        return $view->render('Eventsubmissions/submitstudentevent');
    };
    $books = [64 => 'Proverbs', 65 => 'James'];
    $golden = $renderBookChoices('1000', 'Golden Apple Award U16', $books);
    $check((bool)preg_match('/<option value="64"[^>]*selected[^>]*>Proverbs<\/option>/', $golden),
        'Golden Apple U16 defaults to Proverbs');
    $other = $renderBookChoices('1005', 'Silver Apple Award U16', $books);
    $check(!preg_match('/<option value="64"[^>]*selected/', $other), 'Other events do not default to Proverbs');
    $missing = $renderBookChoices('1000', 'Golden Apple Award U16', [65 => 'James']);
    $check(!preg_match('/<option value="65"[^>]*selected/', $missing), 'A missing Proverbs option does not select a different book');
    $submitted = $renderBookChoices('1000', 'Golden Apple Award U16', $books, 'POST', ['Eventsubmissions' => ['book_ids' => ['65']]]);
    $check(!preg_match('/<option value="64"[^>]*selected/', $submitted)
        && (bool)preg_match('/<option value="65"[^>]*selected[^>]*>James<\/option>/', $submitted),
        'A POST preserves submitted choices instead of restoring the default');
    $reject(fn() => $save($school, 'School', [], $revision), 'Repeated approval rejected');
    $reject(fn() => $save($student, 'Student', [$auto]), 'Approved student choices locked');
    $controller = new ConventionregistrationsController($request($teacher, 'Teacher_Parent', 'managestudentevents'));
    $controller->managestudentevents($slug);
    $check($controller->viewBuilder()->getVar('studentChoicesLocked') === false, 'Assigned supervisor can manage approved choices');
    $controller = new ConventionregistrationsController($request($teacher, 'Teacher_Parent', 'studentevents'));
    $controller->studentevents();
    $view = new \App\View\AppView($controller->getRequest()->withAttribute('csrfToken', str_repeat('a', 32)));
    $view->set($controller->viewBuilder()->getVars());
    foreach (['element', 'Element'] as $directory) {
        $html = $view->render($directory . '/Conventionregistrations/studentevents', false);
        $check(str_contains($html, 'Manage Events') && str_contains($html, 'Remove All')
            && !str_contains($html, 'View approved events') && str_contains($html, '_csrfToken'),
            "Supervisor action column in $directory includes manage and CSRF-protected removal");
    }
    $reject(fn() => $service->assertUploadAllowed($crs, $auto, $student), 'Nonregistered upload denied after approval');
    $controller = new ConventionregistrationsController($request($student, 'Student', 'managestudentevents', ['eventIDS' => [$auto]], 'POST'));
    $controller->managestudentevents($slug);
    $check($service->review($crs)['event_ids'] === $review['event_ids'], 'Forged approved-student POST cannot change choices');
    $check($controller->viewBuilder()->getVar('studentChoicesLocked') === true, 'Student UI marks choices locked');

    $groupEntry = $db->execute('SELECT * FROM crstudentevents WHERE conventionregistration_id=? AND event_id=?', [$reg, $group])->fetch('assoc');
    $db->execute('UPDATE crstudentevents SET group_name=? WHERE id=?', ['Group A', $groupEntry['id']]);
    $otherGroup = $clone('crstudentevents', (int)$groupEntry['id'], ['group_name' => 'Group B']);
    $groupSlug = $db->execute('SELECT slug FROM events WHERE id=?', [$group])->fetchColumn(0);
    $otherSlug = $db->execute('SELECT slug FROM crstudentevents WHERE id=?', [$otherGroup])->fetchColumn(0);
    $db->execute('DELETE FROM crstudentevents WHERE id=?', [$otherGroup]);
    $clone('crstudentevents', (int)$groupEntry['id'], ['slug' => $otherSlug, 'student_id' => 0, 'group_name' => 'Group B']);
    $otherSlug = $db->execute("SELECT slug FROM crstudentevents WHERE conventionregistration_id=? AND group_name='Group B'", [$reg])->fetchColumn(0);
    $controller = new EventsubmissionsController($request($student, 'Student', 'submitgroupevent', [], 'GET', 'Eventsubmissions'));
    $reject(fn() => $controller->submitgroupevent($slug, $groupSlug, $otherSlug), 'Student cannot upload to another group', \Cake\Http\Exception\ForbiddenException::class);
    foreach (['submitnewevent', 'removesubmission', 'timeseventsentries', 'viewlist'] as $action) {
        $controller = new EventsubmissionsController($request($student, 'Student', $action, [], 'POST', 'Eventsubmissions'));
        $reject(fn() => $controller->beforeFilter(new \Cake\Event\Event('Controller.beforeFilter', $controller)),
            "Alternative student submission route $action denied", \Cake\Http\Exception\ForbiddenException::class);
    }
    foreach (['editstudentevent', 'addstudentevent'] as $action) {
        $controller = new ConventionregistrationsController($request($student, 'Student', $action, ['eventIDS' => [$auto]], 'POST'));
        $eventFilter = new \Cake\Event\Event('Controller.beforeFilter', $controller);
        $controller->beforeFilter($eventFilter);
        $check($eventFilter->isStopped() && $controller->getResponse()->getStatusCode() === 302, "$action cannot bypass new workflow");
    }
    $reject(fn() => (new ConventionregistrationsController($request($student, 'Student', 'removestudentevent', [], 'POST')))->removestudentevent($slug),
        'Student cannot clear approved choices', \Cake\Http\Exception\ForbiddenException::class);
    $reject(fn() => (new ConventionregistrationsController($request($school, 'School', 'removestudentevent')))->removestudentevent($slug),
        'GET cannot clear choices', \Cake\Http\Exception\MethodNotAllowedException::class);

    $before = $service->review($crs);
    try {
        $service->save($crs, $school, 'School', [$auto], $allowed, $limits, static fn(array $data): bool => false);
        throw new LogicException('Failed automatic submission should roll back');
    } catch (RuntimeException $error) {
        $check($service->review($crs) === $before && $live() === 3, 'Automatic-submission failure rolls back deletions, inserts and review');
    }
    $check($save($school, 'School', [$auto]) === 'approved' && $autoCalls === 1, 'School can change approved choices and trigger automatic submission');
    $controller = new ConventionregistrationsController($request($school, 'School', 'managestudentevents', ['eventIDS' => [$event]], 'POST'));
    $controller->managestudentevents($slug);
    $controller = new ConventionregistrationsController($request($school, 'School', 'managestudentevents', ['eventIDS' => [$auto]], 'POST'));
    $controller->managestudentevents($slug);
    $check((int)$db->execute('SELECT COUNT(*) FROM eventsubmissions WHERE conventionregistration_id=? AND student_id=? AND event_id=?',
        [$reg, $student, $auto])->fetchColumn(0) === 1, 'Approved school save persists real automatic submission');
    $controller = new ConventionregistrationsController($request($teacher, 'Teacher_Parent', 'managestudentevents', ['eventIDS' => [$event]], 'POST'));
    $controller->managestudentevents($slug);
    $check($service->review($crs)['status'] === 'approved' && (int)$service->review($crs)['approved_by'] === $teacher
        && $service->review($crs)['event_ids'] === (string)$event, 'Supervisor directly saves changes to approved choices');
    $service->assertUploadAllowed($crs, $event, $student);
    $check(true, 'Supervisor save immediately enables student uploads');
    $unassigned = $clone('conventionregistrationstudents', $crs, ['teacher_parent_id' => 0]);
    $unassignedSlug = $db->execute('SELECT slug FROM conventionregistrationstudents WHERE id=?', [$unassigned])->fetchColumn(0);
    $reject(fn() => $service->save($unassigned, $teacher, 'Teacher_Parent', [$auto], $allowed, $limits, $callback),
        'Supervisor cannot edit an unassigned student');
    $reject(fn() => (new ConventionregistrationsController($request($teacher, 'Teacher_Parent', 'managestudentevents')))->managestudentevents($unassignedSlug),
        'Supervisor direct manage URL rejects unassigned student', \Cake\Http\Exception\ForbiddenException::class);
    $unassignedBefore = $db->execute('SELECT event_ids FROM conventionregistrationstudents WHERE id=?', [$unassigned])->fetchColumn(0);
    (new ConventionregistrationsController($request($teacher, 'Teacher_Parent', 'removestudentevent', [], 'POST')))->removestudentevent($unassignedSlug);
    $check($db->execute('SELECT event_ids FROM conventionregistrationstudents WHERE id=?', [$unassigned])->fetchColumn(0) === $unassignedBefore,
        'Supervisor removal does not change unassigned student');
    $reject(fn() => (new ConventionregistrationsController($request($teacher, 'Teacher_Parent', 'removestudentevent')))->removestudentevent($slug),
        'Supervisor GET cannot remove events', \Cake\Http\Exception\MethodNotAllowedException::class);
    (new ConventionregistrationsController($request($teacher, 'Teacher_Parent', 'removestudentevent', [], 'POST')))->removestudentevent($slug);
    $check($service->review($crs)['status'] === 'approved' && $service->review($crs)['event_ids'] === ''
        && (int)$service->review($crs)['approved_by'] === $teacher
        && !$db->execute('SELECT event_ids FROM conventionregistrationstudents WHERE id=?', [$crs])->fetchColumn(0)
        && (int)$db->execute('SELECT COUNT(*) FROM crstudentevents WHERE conventionregistration_id=? AND student_id=?',
            [$reg, $student])->fetchColumn(0) === 0, 'Supervisor Remove All clears approved choices and live event entries immediately');
    $save($school, 'School', [$event]);
    $db->execute("UPDATE student_event_reviews SET status='pending' WHERE student_registration_id=?", [$crs]);
    $check($save($teacher, 'Teacher_Parent', [$event]) === 'approved', 'Supervisor can save pending choices directly without school approval');
    $legacy = $clone('conventionregistrationstudents', $crs, ['event_ids' => (string)$event]);
    $check($service->studentLocked($service->context($legacy, $student, 'Student')), 'Legacy choices remain approved and locked without backfill');
    $reject(fn() => $service->save($legacy, $student, 'Student', [$group], $allowed, $limits, $callback), 'Legacy choices cannot be replaced by student');
    $db->execute('UPDATE conventionseasons SET registration_end_date=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=?', [$season]);
    $reject(fn() => $save($school, 'School', [$event]), 'Ordinary edits respect registration closure');
    $reject(fn() => $save($teacher, 'Teacher_Parent', [$event]), 'Supervisor edits respect registration closure');
    $db->execute('UPDATE student_event_reviews SET status=?,event_ids=?,revision=revision+1 WHERE student_registration_id=?', ['pending', (string)$event, $crs]);
    $check($save($school, 'School', [], (int)$service->review($crs)['revision']) === 'approved', 'School can review already-submitted choices after registration closes');

    $middleware = new \App\Middleware\ScopedPublicationCsrfMiddleware();
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
            return new Response();
        }
    };
    foreach (['managestudentevents', 'reviewstudentevents', 'removestudentevent'] as $action) {
        $reject(fn() => $middleware->process($request($school, 'School', $action, [], 'POST'), $handler),
            "$action requires CSRF", \Cake\Http\Exception\InvalidCsrfTokenException::class);
    }
    echo "PASS: $count student event review checks. All fixture data rolled back.\n";
} finally {
    $db->rollback(true);
}
