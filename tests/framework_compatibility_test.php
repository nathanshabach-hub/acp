<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run this read-only regression check against the local database only.');
}

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$controller = new \App\Controller\Admin\SchedulingsController(new \Cake\Http\ServerRequest());
$season = $controller->fetchTable('Conventionseasons')->find()->firstOrFail();
foreach (['getCurrentUserConflictIds', 'getCurrentGroupConflictScheduleIds'] as $name) {
    $method = new ReflectionMethod($controller, $name);
    $check(is_array($method->invoke($controller, $season)), 'Conflict query failed: ' . $name);
}
$schema = \Cake\Datasource\ConnectionManager::get('default')->execute('SHOW COLUMNS FROM events')->fetchAll('assoc');
$columns = array_column($schema, 'Field');
foreach (['qualifying_time_score', 'qualifying_distance', 'qualifying_score', 'current_record'] as $column) {
    $check(in_array($column, $columns, true), 'Run qualifying-field migration: missing ' . $column);
}
$tables = \Cake\ORM\TableRegistry::getTableLocator();
$ids = $tables->get('Conventionregistrations')->find()->select(['id'])->limit(2)->all()
    ->extract('id')->map(static fn($id): int => (int)$id)->toList();
$check(is_array($ids), 'Registration ID collection failed.');
$books = $tables->get('Books')->find()->select(['id', 'book_name'])->limit(2)->all()->combine('id', 'book_name')->toArray();
$check(is_array($books), 'Book list collection failed.');
foreach (['element', 'Element'] as $directory) {
    foreach (['php', 'ctp'] as $extension) {
        $template = file_get_contents(ROOT . '/templates/' . $directory . '/Conventionregistrations/packageregistration.' . $extension);
        $check((bool)preg_match('/->all\(\)\s*->extract\(\'id\'\)/', $template), 'Registration template must execute the query before extraction.');
    }
}
$request = (new \Cake\Http\ServerRequest())->withParam('action', 'login');
$timings = new \App\Controller\Admin\SchedulingtimingsController($request);
$summaryMethod = new ReflectionMethod($timings, 'categoryOneSummaryLevel');
foreach ([[0, 0, 'info'], [0, 1, 'warning'], [1, 0, 'error'], [1, 2, 'error']] as [$failures, $warnings, $expected]) {
    $check($summaryMethod->invoke($timings, $failures, $warnings) === $expected, 'Scheduling summary severity incorrect.');
}
foreach (['php', 'ctp'] as $extension) {
    $template = file_get_contents(ROOT . '/templates/Admin/Schedulings/reports.' . $extension);
    $check(str_contains($template, "'action'=>'resolvepreview'"), 'Conflict card must use the preview workflow.');
    $check(!str_contains($template, "'action'=>'removeoverlapping'"), 'Conflict card still links to the old action.');
}
$method = new ReflectionMethod($timings, 'removeoverlapping');
$check($method->isPublic(), 'Legacy conflict URL needs a public preview redirect.');
\Cake\Routing\Router::reload();
$routes = require ROOT . '/config/routes.php';
$routes(\Cake\Routing\Router::createRouteBuilder('/'));
$request = $request->withParam('prefix', 'Admin')->withParam('controller', 'Schedulingtimings');
\Cake\Routing\Router::setRequest($request);
$timings->setRequest($request);
$redirect = $timings->removeoverlapping($season->slug);
$check($redirect !== null && $redirect->getStatusCode() === 302, 'Legacy conflict URL must redirect.');
$check(
    str_contains($redirect->getHeaderLine('Location'), '/admin/schedulings/resolvepreview/' . $season->slug),
    'Legacy conflict URL must redirect to preview, not apply repairs.'
);
foreach (['resolveapply', 'resolverevert'] as $action) {
    try {
        $controller->{$action}($season->slug);
        throw new RuntimeException('Conflict repair accepted a GET request.');
    } catch (\Cake\Http\Exception\MethodNotAllowedException $exception) {
        $check(true, 'Conflict repairs remain POST-only.');
    }
}
$admin = new \App\Controller\Admin\AdminsController(new \Cake\Http\ServerRequest());
$secureRequest = new ReflectionMethod($admin, 'isSecureRequest');
$check(is_bool($secureRequest->invoke($admin)), 'Admin cookie checks must not use the removed ssl detector.');
foreach ([null, '0', '5', 'bad'] as $category) {
    try {
        $timings->viewscheduling($season->slug, $category);
        throw new RuntimeException('Invalid scheduling category accepted.');
    } catch (\Cake\Http\Exception\BadRequestException $exception) {
        $check($exception->getMessage() === 'Select a scheduling category from 1 to 4.', 'Unexpected category error.');
    }
}
echo "Read-only conflict queries, qualifying schema, collection APIs, template variants, and category validation passed.\n";
