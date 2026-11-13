<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';
\Cake\Routing\Router::reload();
(require dirname(__DIR__, 2) . '/config/routes.php')(\Cake\Routing\Router::createRouteBuilder('/'));

use App\Controller\EventsubmissionsController;
use App\Service\CombinedTeams;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;

final class CombinedTeamSubmissionCheckController extends EventsubmissionsController
{
    public function redirect($url, int $status = 302): ?Response
    {
        return new Response(['status' => $status]);
    }
}

$db = ConnectionManager::get('default');
$checkCount = 0;
$check = static function (bool $condition, string $message) use (&$checkCount): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checkCount++;
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
$clone = static function (string $table, int $id, array $overrides) use ($db): int {
    $row = $db->execute('SELECT * FROM ' . $table . ' WHERE id = ?', [$id])->fetch('assoc');
    if (!$row) {
        throw new RuntimeException('Missing fixture source in ' . $table);
    }
    unset($row['id']);
    $row = array_replace($row, $overrides);
    if (isset($row['slug'])) {
        $row['slug'] = 'combined-test-' . bin2hex(random_bytes(12));
    }
    $columns = implode(',', array_map(static fn(string $column): string => '`' . $column . '`', array_keys($row)));
    $db->execute('INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
    return (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
};

// All fixture writes are InnoDB-only and rolled back, including failed service calls.
foreach (['conventionregistrations', 'conventionregistrationstudents', 'conventionseasons', 'conventionseasonevents', 'events', 'crstudentevents', 'eventsubmissions', 'combinerequests', 'combined_teams', 'combined_team_members'] as $table) {
    $meta = $db->execute('SHOW TABLE STATUS WHERE Name = ?', [$table])->fetch('assoc');
    if (!$meta || strcasecmp((string)$meta['Engine'], 'InnoDB') !== 0) {
        throw new RuntimeException('Transactional test requires InnoDB: ' . $table);
    }
}
$db->enableSavePoints();
$db->begin();
$testFiles = [];
try {
    $schools = $db->execute(
        "SELECT r.id, r.user_id, r.conventionseason_id FROM conventionregistrations r
         JOIN users u ON u.id = r.user_id AND u.user_type = 'School' AND u.status = 1 AND u.activation_status = 1
         JOIN conventionregistrationstudents s ON s.conventionregistration_id = r.id AND s.status = 1
         JOIN users student ON student.id = s.student_id AND student.school_id = u.id AND student.status = 1 AND student.user_type = 'Student'
         GROUP BY r.id,r.user_id,r.conventionseason_id HAVING COUNT(DISTINCT s.student_id) >= 2 ORDER BY r.id"
    )->fetchAll('assoc');
    $sources = [];
    foreach ($schools as $school) {
        $sources[$school['user_id']] ??= $school;
        if (count($sources) === 3) {
            break;
        }
    }
    if (count($sources) < 3) {
        throw new RuntimeException('Three active schools with two registered students are required.');
    }
    [$schoolA, $schoolB, $outsider] = array_values($sources);
    $seasonId = $clone('conventionseasons', (int)$schoolA['conventionseason_id'], ['submissions_open' => 1]);
    $eventSource = $db->execute('SELECT id FROM events WHERE group_event_yes_no = 1 LIMIT 1')->fetchColumn(0);
    $eventId = $clone('events', (int)$eventSource, ['status' => 1, 'min_no' => 4, 'max_no' => 4, 'upload_type' => 'nil', 'context_box' => 1, 'book_ids' => null, 'auto_submission' => 1]);
    $db->execute('INSERT INTO conventionseasonevents (conventionseasons_id,event_id) VALUES (?,?)', [$seasonId, $eventId]);
    $registrations = [];
    $studentIds = [];
    $studentSlugs = [];
    foreach ([$schoolA, $schoolB] as $source) {
        $regId = $clone('conventionregistrations', (int)$source['id'], ['conventionseason_id' => $seasonId]);
        $registrations[] = $regId;
        $rows = $db->execute(
            "SELECT s.id,s.student_id FROM conventionregistrationstudents s JOIN users u ON u.id = s.student_id
             WHERE s.conventionregistration_id = ? AND s.status = 1 AND u.status = 1 AND u.school_id = ? AND u.user_type = 'Student'
             GROUP BY s.student_id,s.id LIMIT 2", [$source['id'], $source['user_id']]
        )->fetchAll('assoc');
        $ids = [];
        foreach ($rows as $row) {
            $studentRegId = $clone('conventionregistrationstudents', (int)$row['id'], ['conventionregistration_id' => $regId, 'event_ids' => (string)$eventId]);
            $studentSlugs[$regId] = $db->execute('SELECT slug FROM conventionregistrationstudents WHERE id = ?', [$studentRegId])->fetchColumn(0);
            $ids[] = (int)$row['student_id'];
            $registration = $db->execute('SELECT * FROM conventionregistrations WHERE id = ?', [$regId])->fetch('assoc');
            $db->execute(
                'INSERT INTO crstudentevents (slug,conventionregistration_id,conventionseason_id,convention_id,user_id,season_id,season_year,student_id,event_id,event_id_number,group_name,created,modified) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
                ['combined-check-' . $regId . '-' . $row['student_id'], $regId, $seasonId, $registration['convention_id'], $source['user_id'], $registration['season_id'], $registration['season_year'], $row['student_id'], $eventId, 'TEST', '']
            );
        }
        $studentIds[] = $ids;
    }
    [$regA, $regB] = $registrations;
    [$idsA, $idsB] = $studentIds;
    $service = new CombinedTeams($db);
    $request = static fn(array $a, array $b, int $event = 0, int $partner = 0): int => $service->request($regA, (int)$schoolA['user_id'], (int)$schoolA['user_id'], $event ?: $eventId, $partner ?: (int)$schoolB['user_id'], $a, $b);
    $reject(fn() => $request($idsB, $idsA), 'Reject foreign-school roster');
    $reject(fn() => $request([(string)$idsA[0] . 'invalid', $idsA[1]], $idsB), 'Reject malformed student ID');
    $reject(fn() => $request([$idsA[0]], [$idsB[0]]), 'Enforce total event minimum');
    $reject(fn() => $request($idsA, []), 'Require both schools');
    $reject(fn() => $request($idsA, $idsB, 0, (int)$outsider['user_id']), 'Reject unregistered partner');
    $reject(fn() => $request($idsA, $idsB, 0, (int)$schoolA['user_id']), 'Reject self combination');
    $reject(fn() => $request($idsA, $idsB, (int)$eventSource), 'Reject event not offered in season');
    $db->execute('UPDATE events SET max_no = 3 WHERE id = ?', [$eventId]);
    $reject(fn() => $request($idsA, $idsB), 'Enforce total event maximum');
    $db->execute('UPDATE events SET max_no = 4 WHERE id = ?', [$eventId]);
    $teamId = $request($idsA, $idsB);
    $check(count($service->listing($regA)) === 1 && count($service->listing($regB)) === 1, 'Both schools see the one request');
    $check(count($service->listing((int)$outsider['id'])) === 0, 'Outsider cannot see request');
    $check(count($service->listing($regA)[0]['members']) === 4, 'Entire roster stored by student ID');
    $reject(fn() => $service->request($regB, (int)$schoolB['user_id'], (int)$schoolB['user_id'], $eventId, (int)$schoolA['user_id'], $idsB, $idsA), 'Reject reversed duplicate school pair');
    $db->execute('UPDATE conventionseasons SET submissions_open = 0 WHERE id = ?', [$seasonId]);
    $reject(fn() => $service->decide($teamId, 1, true), 'Approval respects submission lock');
    $db->execute('UPDATE conventionseasons SET submissions_open = 1 WHERE id = ?', [$seasonId]);
    $db->execute('UPDATE crstudentevents SET group_name = ? WHERE conventionregistration_id = ? AND student_id = ? AND event_id = ?', ['Conflict', $regB, $idsB[0], $eventId]);
    $reject(fn() => $service->decide($teamId, 1, true), 'Conflicting group rejects entire approval');
    $check((int)$db->execute('SELECT COUNT(*) FROM eventsubmissions WHERE combined_team_id = ?', [$teamId])->fetchColumn(0) === 0, 'Failed approval creates no partial submission');
    $check((int)$db->execute("SELECT COUNT(*) FROM crstudentevents WHERE conventionregistration_id = ? AND event_id = ? AND group_name <> ''", [$regA, $eventId])->fetchColumn(0) === 0, 'Failed approval rolls back first school grouping');
    $db->execute("UPDATE crstudentevents SET group_name = '' WHERE conventionregistration_id = ? AND event_id = ?", [$regB, $eventId]);
    $service->decide($teamId, 1, true);
    $reject(fn() => $service->decide($teamId, 1, true), 'Approval is not repeatable');
    $sharedIds = $service->sharedSubmissionIds($regA);
    $check(count($sharedIds) === 1 && $sharedIds === $service->sharedSubmissionIds($regB), 'Both schools resolve the same single submission ID');
    $check(count($service->approvedMembers($regA, $eventId)) === 4 && count($service->approvedMembers($regB, $eventId)) === 4, 'Complete team exists at both schools');
    $check((int)$db->execute('SELECT COUNT(*) FROM crstudentevents WHERE conventionseason_id = ? AND event_id = ?', [$seasonId, $eventId])->fetchColumn(0) === 8, 'Four exact members mirrored to both registrations');
    $eventSlug = $db->execute('SELECT slug FROM events WHERE id = ?', [$eventId])->fetchColumn(0);
    $canonicalSlug = $db->execute('SELECT slug FROM eventsubmissions WHERE id = ?', [$sharedIds[0]])->fetchColumn(0);
    $independentId = $clone('eventsubmissions', $sharedIds[0], ['conventionregistration_id' => $regB, 'user_id' => $schoolB['user_id'], 'group_name' => 'Independent test group', 'combined_team_id' => null]);
    $fileSuffix = 'combined-team-test-' . bin2hex(random_bytes(6)) . '.pdf';
    $storedReport = null;
    foreach ([[$schoolA, $regA, 'School A context'], [$schoolB, $regB, 'School B updated context'], [$schoolA, $regA, 'Context update retaining School B file']] as $step => [$school, $regId, $context]) {
        $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
        $session->write(['user_id' => (int)$school['user_id'], 'user_type' => 'School', 'sess_selected_convention_registration_id' => $regId]);
        $post = ['Eventsubmissions' => ['context_box' => $context, 'id' => 999999, 'combined_team_id' => 999999]];
        if ($step < 2) {
            $tempFile = tempnam(sys_get_temp_dir(), 'combined-team-test-');
            $testFiles[] = $tempFile;
            file_put_contents($tempFile, "%PDF-1.4\n% Combined team test document\n%%EOF\n");
            $post['Eventsubmissions']['report'] = ['name' => $fileSuffix, 'type' => 'application/pdf', 'tmp_name' => $tempFile, 'size' => filesize($tempFile), 'error' => UPLOAD_ERR_OK];
        }
        $request = new ServerRequest([
            'url' => '/eventsubmissions/submitgroupevent',
            'environment' => ['REQUEST_METHOD' => 'POST'],
            'session' => $session,
            'params' => ['controller' => 'Eventsubmissions', 'action' => 'submitgroupevent'],
            'post' => $post,
        ]);
        $entrySlug = $db->execute('SELECT slug FROM crstudentevents WHERE conventionregistration_id = ? AND event_id = ? LIMIT 1', [$regId, $eventId])->fetchColumn(0);
        $controller = new CombinedTeamSubmissionCheckController($request);
        $controller->submitgroupevent($studentSlugs[$regId], $eventSlug, $entrySlug);
        $submission = $db->execute('SELECT * FROM eventsubmissions WHERE id = ?', [$sharedIds[0]])->fetch('assoc');
        if ($step < 2) {
            $previousReport = $storedReport;
            $storedReport = $submission['report'];
            $testFiles[] = UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH . $storedReport;
            $check(!empty($storedReport) && is_file(UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH . $storedReport) && $previousReport !== $storedReport, 'Either school uploads/replaces the real shared report file');
        } else {
            $check($submission['report'] === $storedReport, 'Partner context update preserves the shared uploaded file');
        }
        $check($submission['context_box'] === $context, 'Either school can save shared submission content');
        $check((int)$submission['conventionregistration_id'] === $regA && (int)$submission['combined_team_id'] === $teamId, 'Canonical ownership and identity cannot be changed by input');
        $check($submission['slug'] === $canonicalSlug, 'Shared submission URL stays stable');
        $controller = new CombinedTeamSubmissionCheckController($request->withMethod('GET')->withParsedBody([]));
        $controller->viewlist();
        $listed = $controller->viewBuilder()->getVar('eventsubmissions');
        $check(in_array($sharedIds[0], array_map('intval', $listed->extract('id')->toList()), true), 'Both schools see shared submission in listing');
        $check(in_array($independentId, array_map('intval', $listed->extract('id')->toList()), true) === ($regId === $regB), 'Sharing does not expose a partner school independent group');
        $check(isset($controller->viewBuilder()->getVar('combinedUploadLinks')[$sharedIds[0]]), 'Both schools have an exact shared upload link');
    }
    $session->write(['user_id' => (int)$outsider['user_id'], 'user_type' => 'School', 'sess_selected_convention_registration_id' => (int)$outsider['id']]);
    $controller = new CombinedTeamSubmissionCheckController($request->withMethod('GET')->withParsedBody([]));
    try {
        $controller->submitgroupevent($studentSlugs[$regB], $eventSlug, $entrySlug);
        throw new RuntimeException('An unrelated school could access the team upload');
    } catch (\Cake\Http\Exception\ForbiddenException $error) {
        $check(true, 'Outsider denied shared upload');
    }
    $groups = new \App\Controller\GroupsController($request->withMethod('GET')->withParsedBody([]));
    $registration = $groups->fetchTable('Conventionregistrations')->get($regB);
    $event = $groups->fetchTable('Events')->get($eventId);
    (new ReflectionMethod($groups, 'syncApprovedCombinedRequestsToCurrentSchool'))->invoke($groups, $registration, $event);
    (new ReflectionMethod($groups, 'ensureAutomaticGroupSubmissions'))->invoke($groups, $event, $registration, [$service->listing($regB)[0]['group_name']]);
    $check((int)$db->execute('SELECT COUNT(*) FROM crstudentevents WHERE conventionseason_id = ? AND event_id = ?', [$seasonId, $eventId])->fetchColumn(0) === 8, 'Legacy group sync does not remove or duplicate team members');
    $labels = $service->groupLabels($seasonId, $eventId);
    $check(count($labels) === 1 && str_starts_with((string)array_key_first($labels), 'group:'), 'Result labels are exact-team scoped, not school-wide');
    $adminSession = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
    $adminSession->write(['admin_id' => 1, 'sess_admin_header_season_id' => $seasonId]);
    $admin = new \App\Controller\Admin\CombinerequestsController(new ServerRequest([
        'url' => '/admin/combinerequests', 'session' => $adminSession,
        'params' => ['prefix' => 'Admin', 'controller' => 'Combinerequests', 'action' => 'index'],
    ]));
    $admin->index();
    $check(count($admin->viewBuilder()->getVar('combinedTeams')) === 1, 'Admin lists the whole request once');
    $check($admin->viewBuilder()->getVar('combinerequests')->count() === 0, 'Compatibility projections are hidden from legacy approval controls');
    $results = new \App\Controller\Admin\ResultsController($admin->getRequest());
    $resultLabels = (new ReflectionMethod($results, 'getApprovedCombinedSchoolLabels'))->invoke($results, $seasonId, $eventId);
    $check($resultLabels === $labels, 'Results use exact team labels without school-wide partner union');
    $reportService = new \App\Service\StudentPerformanceReportService(\Cake\ORM\TableRegistry::getTableLocator());
    foreach ([[$schoolA, $idsA[0]], [$schoolB, $idsB[0]]] as [$school, $studentId]) {
        $student = $db->execute('SELECT * FROM users WHERE id = ?', [$studentId])->fetch('assoc');
        $targets = (new ReflectionMethod($reportService, 'combinedTargets'))->invoke($reportService, (int)$school['user_id'], $student, [$seasonId], []);
        $check(count($targets) === 1 && (int)$targets[0]['Resultpositions.conventionregistration_id'] === $regA, 'Both schools student performance reports target the same canonical team results');
    }
    $view = new \App\View\AppView($admin->getRequest()->withAttribute('csrfToken', str_repeat('a', 32)));
    $view->set(['combinedTeams' => $service->listing($regA), 'adminCombinedTeams' => true]);
    $html = $view->element('combined_teams');
    $check(substr_count($html, '<tr>') === 2, 'Team table renders one whole roster row');
    $declineEventId = $clone('events', $eventId, ['min_no' => 2, 'max_no' => 2]);
    $db->execute('INSERT INTO conventionseasonevents (conventionseasons_id,event_id) VALUES (?,?)', [$seasonId, $declineEventId]);
    foreach ([[$regA, $idsA[0]], [$regB, $idsB[0]]] as [$regId, $studentId]) {
        $entryId = $db->execute('SELECT id FROM crstudentevents WHERE conventionregistration_id = ? AND student_id = ? AND event_id = ?', [$regId, $studentId, $eventId])->fetchColumn(0);
        $clone('crstudentevents', (int)$entryId, ['event_id' => $declineEventId, 'group_name' => '']);
    }
    $declineId = $service->request($regA, (int)$schoolA['user_id'], (int)$schoolA['user_id'], $declineEventId, (int)$schoolB['user_id'], [$idsA[0]], [$idsB[0]]);
    $view->set('combinedTeams', array_values(array_filter($service->listing($regA), static fn(array $team): bool => (int)$team['id'] === $declineId)));
    $pendingHtml = $view->element('combined_teams');
    $check(str_contains($pendingHtml, 'Approve entire team') && str_contains($pendingHtml, 'Decline entire team') && str_contains($pendingHtml, '_csrfToken'), 'Pending admin row renders whole-team POST/CSRF controls');
    $service->decide($declineId, 1, false);
    $check((int)$db->execute('SELECT COUNT(*) FROM combined_team_members WHERE team_id = ? AND reserved IS NULL', [$declineId])->fetchColumn(0) === 2, 'Decline retains roster history and releases both student reservations');
    $check(!in_array($declineEventId, $service->requiredEvents($regA, $idsA[0]), true), 'Declined event is no longer locked into the student roster');
    $check((int)$db->execute('SELECT COUNT(*) FROM eventsubmissions WHERE combined_team_id = ?', [$teamId])->fetchColumn(0) === 1, 'Repeated school saves never create another submission');
    echo "PASS: $checkCount combined-team checks. All fixture data rolled back.\n";
} finally {
    $db->rollback(true);
    foreach ($testFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
