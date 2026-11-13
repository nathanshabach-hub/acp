<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Utility/PortalErrorLogs.php';

$directory = sys_get_temp_dir() . '/acp-log-test-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create test directory.');
}
define('LOGS', $directory . DIRECTORY_SEPARATOR);
$path = LOGS . 'error.log';
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $reader = new \App\Utility\PortalErrorLogs();
    $cases = [
        ['Warning', 'A payment request was blocked by environment policy.', 'Safety block'],
        ['Warning', 'An external integration was blocked by environment policy.', 'Safety block'],
        ['Warning', 'A CLI command was blocked by environment policy.', 'Safety block'],
        ['Warning', 'A request was blocked because its deployment URL did not match configuration.', 'Safety block'],
        ['Error', 'C1 parsed window season 26: start=08:00', 'Diagnostic'],
        ['Error', 'C1 summary season 26: save_failures=1', 'Manual investigation required'],
        ['Error', 'C1 summary season 26: save_failures=0, no_rooms=1', 'Scheduling warning'],
        ['Error', 'C1 summary season 26: save_failures=0, skipped_without_submission=8', 'Scheduling warning'],
        ['Info', 'C1 summary season 26: save_failures=0, no_rooms=0', 'Diagnostic'],
        ['Debug', 'Conventions index filter: season_id=26', 'Diagnostic'],
        ['Error', 'Call to undefined method Example::missing()', 'Manual investigation required'],
        ['Error', '[MissingTemplateException] Template not found', 'Manual investigation required'],
        ['Warning', 'Unrecognized message', 'Manual investigation required'],
        ['Error', 'Exception mentions A payment request was blocked by environment policy.', 'Manual investigation required'],
        ['Error', '[Cake\Http\Exception\BadRequestException] Select a scheduling category from 1 to 4. in /app/controller.php on line 77', 'Request rejected'],
        ['Error', '[Cake\Http\Exception\BadRequestException] Select an available portal error log. in /app/controller.php on line 1', 'Request rejected'],
        ['Error', '[Cake\Http\Exception\BadRequestException] Unrecognized validation error', 'Manual investigation required'],
    ];
    foreach ($cases as [$level, $message, $expected]) {
        $actual = $reader->classify("2026-11-03 15:00:00 $level: $message\n");
        $check($actual['label'] === $expected, 'Incorrect classification: ' . $message);
    }
    $check($reader->classify('unstructured error')['label'] === 'Manual investigation required', 'Unknown text must require review.');
    foreach (['Emergency' => 'extreme', 'Alert' => 'extreme', 'Critical' => 'extreme', 'Error' => 'major', 'Warning' => 'minor', 'Notice' => 'minor', 'Info' => 'minor', 'Debug' => 'minor'] as $level => $expected) {
        $check($reader->priority("2026-11-03 15:00:00 $level: Unrecognized message") === $expected, 'Incorrect priority for ' . $level);
    }
    $check($reader->priority('unstructured error') === 'major', 'Unknown entries must stay visible for review.');
    $check($reader->priority('2026-11-03 15:00:00 error: C1 parsed window season 26: start=08:00') === 'minor', 'Known diagnostics should be minor.');
    $check($reader->priority('2026-11-03 15:00:00 critical: C1 parsed window season 26: start=08:00') === 'extreme', 'Critical level must not be downgraded.');
    $fixture = "2026-11-03 15:00:00 Warning: A payment request was blocked by environment policy.\n";
    $fixture .= str_repeat("trace needle\n", 120);
    $fixture .= "2026-11-03 15:01:00 Error: Unrecognized error\n";
    $fixture .= "unknown trace needle <script>alert(1)</script>\n";
    if (file_put_contents($path, $fixture) === false) {
        throw new RuntimeException('Cannot create test log.');
    }
    $first = $reader->read('error.log', 'needle', 1);
    $second = $reader->read('error.log', 'needle', 2);
    $check($first['total'] === 121 && count($first['rows']) === 100, 'Search or page size incorrect.');
    $check(count($second['rows']) === 21, 'Second page size incorrect.');
    $check(count($first['groups']) === 2 && count($second['groups']) === 1, 'Stack traces must be grouped by event.');
    $check($first['groups'][0]['priority'] === 'major', 'Error event priority incorrect.');
    $check($first['groups'][1]['priority'] === 'minor' && $second['groups'][0]['priority'] === 'minor', 'Priority must survive search and pagination.');
    $check(count($first['groups'][1]['rows']) === 99, 'Continuation lines must share one event label.');
    $check(str_contains($second['groups'][0]['header'], 'A payment request was blocked'), 'Header context lost on later pages.');
    $numbers = array_column($first['groups'][1]['rows'], 'number');
    $sorted = $numbers;
    sort($sorted);
    $check($numbers === $sorted, 'Stack traces must retain original order inside an event.');
    $check($first['rows'][0]['classification']['label'] === 'Manual investigation required', 'Unknown event inherited previous status.');
    foreach (array_merge(array_slice($first['rows'], 1), $second['rows']) as $row) {
        $check($row['classification']['label'] === 'Safety block', 'Continuation lost status across search/pagination.');
    }
    $check($first['rows'][0]['number'] > $second['rows'][0]['number'], 'Newest-first pagination changed.');
    $check($reader->read('error.log', 'no-match', 1)['rows'] === [], 'Empty search results incorrect.');
    try {
        $reader->read('../config/app.php', '', 1);
        throw new RuntimeException('Traversal was allowed.');
    } catch (InvalidArgumentException $exception) {
        $check(true, 'Traversal rejected.');
    }
    $check(file_get_contents($path) === $fixture, 'Viewer modified the log.');
    foreach (['error.log.1', 'cli-error.log', 'cli-error.log.2', 'debug.log', 'error.log.backup'] as $name) {
        file_put_contents(LOGS . $name, 'preserve or clear');
    }
    $outside = LOGS . 'outside.txt';
    file_put_contents($outside, 'must remain');
    symlink($outside, LOGS . 'error.log.9');
    $check($reader->clearAll() === 4, 'Must clear current and rotated portal error logs only.');
    foreach (['error.log', 'error.log.1', 'cli-error.log', 'cli-error.log.2'] as $name) {
        $check(is_file(LOGS . $name) && file_get_contents(LOGS . $name) === '', 'Log was not cleared: ' . $name);
    }
    foreach (['debug.log', 'error.log.backup'] as $name) {
        $check(file_get_contents(LOGS . $name) === 'preserve or clear', 'Unrelated log changed.');
    }
    $check(file_get_contents($outside) === 'must remain', 'Symlink target changed.');
    file_put_contents($path, "new error\n", FILE_APPEND);
    $check(file_get_contents($path) === "new error\n", 'Cleared log cannot accept new entries.');
    echo "Portal error log classification, continuation, search, pagination, and read-only tests passed.\n";
    echo "Portal error log deletion scope, symlink exclusion and continued logging tests passed.\n";
} finally {
    foreach (['error.log', 'error.log.1', 'cli-error.log', 'cli-error.log.2', 'debug.log', 'error.log.backup', 'error.log.9', 'outside.txt'] as $name) {
        if (is_file(LOGS . $name) || is_link(LOGS . $name)) {
            unlink(LOGS . $name);
        }
    }
    rmdir($directory);
}
