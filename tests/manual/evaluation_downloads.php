<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\ConventionregistrationsController;
use App\Service\EvaluationBatchExport;
use App\Service\EvaluationDownloads;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Http\Session;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run evaluation download checks locally only.');
}
$db = ConnectionManager::get('default');
$service = new EvaluationDownloads($db);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $action, string $message) use ($check): void {
    try {
        $action();
    } catch (DomainException $error) {
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};
$registration = $db->execute(
    'SELECT r.id, r.user_id FROM conventionregistrations r
     JOIN conventionseasons s ON s.id=r.conventionseason_id AND s.results_release=1
     JOIN users u ON u.id=r.user_id AND u.user_type=? AND u.status=1 AND u.activation_status=1
     ORDER BY r.id DESC',
    ['School']
)->fetchAll('assoc');
$chosen = null;
foreach ($registration as $candidate) {
    $eligible = array_values(array_filter($service->roster((int)$candidate['id']), static fn(array $s): bool => (bool)$s['has_evaluations']));
    if (count($eligible) >= 11) {
        $chosen = $candidate;
        break;
    }
}
$check($chosen !== null, 'Need a locally published school registration with at least 11 eligible students.');
$registrationId = (int)$chosen['id'];
$schoolId = (int)$chosen['user_id'];
$ids = array_map('intval', array_column(array_slice($eligible, 0, 10), 'id'));
$request = static function (int $userId, string $method = 'POST', array $data = [], int $selectedRegistration = 0) use ($registrationId): ServerRequest {
    $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
    $session->write(['user_id' => $userId, 'user_type' => 'School',
        'sess_selected_convention_registration_id' => $selectedRegistration ?: $registrationId]);
    return new ServerRequest(['session' => $session, 'environment' => ['REQUEST_METHOD' => $method], 'post' => $data]);
};
$reject(fn() => $service->select($registrationId, []), 'Empty selection must fail.');
$reject(fn() => $service->select($registrationId, array_column(array_slice($eligible, 0, 11), 'id')), 'An eleven-student request must fail.');
$reject(fn() => $service->select($registrationId, [$ids[0], $ids[0]]), 'Duplicate IDs must fail.');
$reject(fn() => $service->select($registrationId, ['invalid']), 'Malformed IDs must fail.');
$reject(fn() => $service->select($registrationId, [PHP_INT_MAX]), 'Foreign or missing students must fail.');
$check(count($service->select($registrationId, $ids)) === 10, 'Exactly ten eligible students are accepted.');
$controller = new ConventionregistrationsController($request($schoolId, 'POST', ['student_ids' => array_fill(0, 11, $ids[0])]));
$check($controller->prepareevaluationdownload()->getStatusCode() === 422, 'The endpoint enforces the batch limit before rendering.');
foreach ([0, PHP_INT_MAX] as $wrongSchool) {
    try {
        (new ConventionregistrationsController($request($wrongSchool, 'GET')))->evaluationdownloads();
        throw new RuntimeException('Missing or foreign school access must fail.');
    } catch (\Cake\Http\Exception\NotFoundException $error) {
        $check(true, 'School isolation is enforced.');
    }
}
try {
    (new ConventionregistrationsController($request($schoolId, 'GET')))->prepareevaluationdownload();
    throw new RuntimeException('Export must reject GET.');
} catch (\Cake\Http\Exception\MethodNotAllowedException $error) {
    $check(true, 'Export requires POST.');
}
$before = $service->roster($registrationId);
$db->begin();
try {
    $token = $service->prepared($schoolId, $registrationId, $ids);
    $check($service->roster($registrationId) === $before, 'Preparing a ZIP must not mark students complete.');
    $reject(fn() => $service->complete($schoolId + 1, $registrationId, $token), 'Other schools cannot confirm the batch.');
    $reject(fn() => $service->complete($schoolId, $registrationId + 1, $token), 'Other registrations cannot confirm the batch.');
    $check($service->complete($schoolId, $registrationId, $token) === 10, 'Receiving a ten-student batch records all ten.');
    $shared = (new EvaluationDownloads($db))->roster($registrationId);
    $completed = array_filter($shared, static fn(array $s): bool => in_array((int)$s['id'], $ids, true) && $s['completed'] !== null);
    $check(count($completed) === 10, 'New page/service instances see shared completion for all ten students.');
    $check($service->complete($schoolId, $registrationId, $token) === 10, 'Confirmation retries are idempotent.');
    $remaining = array_filter($shared, static fn(array $s): bool => (bool)$s['has_evaluations'] && $s['completed'] === null);
    $check(array_intersect($ids, array_column($remaining, 'id')) === [], 'Next-batch candidates exclude completed students.');
    $expired = $service->prepared($schoolId, $registrationId, [$ids[0]]);
    $db->execute('UPDATE evaluation_download_batches SET expires=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE token=?', [$expired]);
    $reject(fn() => $service->complete($schoolId, $registrationId, $expired), 'Expired unreceived batches cannot be marked complete.');
} finally {
    $db->rollback();
}
$check($service->roster($registrationId) === $before, 'All completion fixtures are rolled back.');
if (($argv[1] ?? '') === '--export') {
    $directory = sys_get_temp_dir() . '/acp-evaluation-check-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Could not create test export directory.');
    try {
        $events = [];
        $zipPath = (new EvaluationBatchExport())->generate(
            $service->select($registrationId, $ids), $request($schoolId), $directory,
            static function (array $event) use (&$events, $check, $directory): void {
                $events[] = $event;
                if ($event['type'] === 'file' && $event['status'] === 'ready') {
                    $check(is_file($directory . '/' . $event['student_id'] . '.pdf'),
                        'A live PDF-ready update is emitted only after the PDF exists.');
                }
            }
        );
        $check(count($events) === 21, 'Each of ten PDFs reports preparing and ready, followed by ZIP creation.');
        foreach ($ids as $i => $id) {
            $check($events[$i * 2]['student_id'] === $id && $events[$i * 2]['status'] === 'preparing'
                && $events[$i * 2]['completed'] === $i
                && $events[$i * 2 + 1]['student_id'] === $id && $events[$i * 2 + 1]['status'] === 'ready'
                && $events[$i * 2 + 1]['completed'] === $i + 1,
                'Live progress reports each selected student in order with accurate completed counts.');
        }
        $check($events[20]['type'] === 'packing', 'ZIP creation is announced after every PDF is ready.');
        $zip = new ZipArchive();
        $check($zip->open($zipPath) === true && $zip->numFiles === 10, 'ZIP contains exactly ten PDFs.');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $check((bool)preg_match('~\A[a-z0-9-]+/evaluations\.pdf\z~i', $entry), 'Each student has a named ZIP folder with one evaluation PDF.');
            $check(str_starts_with($zip->getFromIndex($i), '%PDF-'), 'Each ZIP entry contains an actual PDF.');
            $pdfPath = $directory . '/' . $ids[$i] . '.pdf';
            $text = [];
            $exitCode = 0;
            exec('pdftotext -layout ' . escapeshellarg($pdfPath) . ' - 2>&1', $text, $exitCode);
            $check($exitCode === 0, 'PDF text extraction succeeds for content verification.');
            $pdfText = implode("\n", $text);
            $html = file_get_contents($directory . '/' . $ids[$i] . '.html');
            $check(substr_count($pdfText, 'Judge:') === substr_count($html, '<div class="judge-eval-record">'),
                'Every rendered judge evaluation is present in the PDF.');
            $check(!str_contains($pdfText, 'takes pleasure in presenting this Award')
                && !str_contains($pdfText, 'Individual Result Package'),
                'PDF contains evaluations only, without certificates or a results-package cover.');
        }
        $zip->close();
        $check($service->roster($registrationId) === $before, 'Generating a real ZIP does not record completion.');
        $invalid = $service->select($registrationId, [$ids[0]]);
        $invalid[0]['slug'] = 'missing-evaluation-download-fixture';
        $failedEvents = [];
        $reject(function () use ($invalid, $request, $schoolId, $directory, &$failedEvents): void {
            (new EvaluationBatchExport())->generate($invalid, $request($schoolId), $directory,
                static function (array $event) use (&$failedEvents): void { $failedEvents[] = $event; });
        }, 'A failed student export reports an error instead of returning a ZIP.');
        $check(count($failedEvents) === 1 && $failedEvents[0]['status'] === 'preparing',
            'Failed generation never announces that the PDF or ZIP is ready.');
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $child = $path . '/' . $entry;
                if (is_dir($child) && !is_link($child)) $remove($child);
                else unlink($child);
            }
            rmdir($path);
        };
        $remove($directory);
    }
}
echo "PASS: $checks evaluation batch checks; completion fixtures rolled back.\n";
