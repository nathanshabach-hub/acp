<?php
declare(strict_types=1);

use App\Deployment\Environment;
use App\Service\ResultPackageService;
use Cake\ORM\TableRegistry;

require dirname(__DIR__) . '/config/bootstrap.php';

if (Environment::isHosted()) {
    throw new RuntimeException('Run this read-only regression check against the local database only.');
}

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$registrationId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$check($registrationId !== false, 'Usage: php tests/result_package_performance_test.php <registration-id>');
$tables = TableRegistry::getTableLocator();
$registration = $tables->get('Conventionregistrations')->get($registrationId);
$schoolId = (int)$registration->user_id;
$seasonId = (int)$registration->conventionseason_id;
$eventIds = $tables->get('Conventionseasonevents')->find()
    ->where(['conventionseasons_id' => $seasonId])->all()->extract('event_id')->toList();
$service = new ResultPackageService($tables);
$start = microtime(true);
$actual = $service->events($eventIds, $schoolId, $seasonId, (int)$registrationId);
$elapsed = microtime(true) - $start;
$check($elapsed < 2, 'Bulk result package data exceeded two seconds: ' . $elapsed);

$expected = [];
$groupRows = $individualRows = $cpRows = 0;
$events = $tables->get('Events')->find()->where(['Events.id IN' => $eventIds ?: [0]])
    ->orderBy(['Events.event_id_number' => 'ASC'])->enableHydration(false)->all();
foreach ($events as $event) {
    $eventId = (int)$event['id'];
    $eventCp = $tables->get('Eventsubmissions')->find()->where([
        'command_performance' => 1,
        'event_id' => $eventId,
        'conventionseason_id' => $seasonId,
        'conventionregistration_id' => $registrationId,
        'user_id' => $schoolId,
    ])->first() !== null;
    $positions = $tables->get('Resultpositions')->find()->where([
        'user_id' => $schoolId,
        'conventionseason_id' => $seasonId,
        'event_id' => $eventId,
        'position >' => 0,
        'position <=' => 6,
    ])->orderBy(['position' => 'ASC'])->enableHydration(false)->all()->toArray();
    if (!$positions && !$eventCp) {
        continue;
    }
    foreach ($positions as &$position) {
        $position['showName'] = '';
        $position['showSchoolName'] = '';
        $position['commandPerformance'] = $eventCp;
        if ((int)$position['student_id'] > 0) {
            $individualRows++;
            $student = $tables->get('Users')->find()->contain(['Schools'])
                ->where(['Users.id' => $position['student_id']])->enableHydration(false)->firstOrFail();
            $position['showName'] = $student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name'];
            $position['showSchoolName'] = $student['Schools']['first_name'];
            if (!empty($position['eventsubmission_id'])) {
                $position['commandPerformance'] = $tables->get('Eventsubmissions')->find()->where([
                    'id' => $position['eventsubmission_id'], 'command_performance' => 1,
                ])->first() !== null;
            }
            foreach ([
                ['student_id' => $position['student_id']],
                ['user_id' => $schoolId],
                ['conventionregistration_id' => $registrationId],
            ] as $fallback) {
                if (!$position['commandPerformance']) {
                    $position['commandPerformance'] = $tables->get('Eventsubmissions')->find()->where([
                        'command_performance' => 1, 'event_id' => $eventId, 'conventionseason_id' => $seasonId,
                    ] + $fallback)->first() !== null;
                }
            }
        } elseif (!empty($position['group_name'])) {
            $groupRows++;
            $names = [];
            $members = $tables->get('Crstudentevents')->find()->where([
                'user_id' => $schoolId, 'conventionseason_id' => $seasonId,
                'event_id' => $eventId, 'group_name' => $position['group_name'],
            ])->orderBy(['id' => 'ASC'])->all();
            foreach ($members as $member) {
                $student = $tables->get('Users')->find()->contain(['Schools'])
                    ->where(['Users.id' => $member->student_id])->enableHydration(false)->firstOrFail();
                $names[] = $student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name'];
                $position['showSchoolName'] = $student['Schools']['first_name'];
            }
            $position['showName'] = implode(', ', $names);
        }
        $cpRows += (int)$position['commandPerformance'];
    }
    unset($position);
    $event['positions'] = $positions;
    $expected[] = $event;
}

$check(array_column($actual, 'id') === array_column($expected, 'id'), 'Visible events or their ordering changed.');
foreach ($expected as $index => $event) {
    $rows = $actual[$index]['positions'];
    $check(count($rows) === count($event['positions']), 'Placement count changed for event ' . $event['id']);
    $byId = array_column($rows, null, 'id');
    foreach ($event['positions'] as $position) {
        $row = $byId[$position['id']] ?? null;
        $check($row !== null, 'Missing placement ' . $position['id']);
        foreach (['position', 'slug', 'showName', 'showSchoolName', 'commandPerformance'] as $field) {
            $check($row[$field] === $position[$field], 'Placement ' . $position['id'] . ' changed field ' . $field);
        }
    }
    $check(
        array_column($rows, 'position') === array_column($event['positions'], 'position'),
        'Placement ordering changed for event ' . $event['id']
    );
}
$check($service->events([], $schoolId, $seasonId, (int)$registrationId) === [], 'Empty event selection returned results.');
printf(
    "PASS: %d events, %d individual rows, %d group rows, %d Command Performance rows match; bulk data %.3fs.\n",
    count($actual), $individualRows, $groupRows, $cpRows, $elapsed
);
