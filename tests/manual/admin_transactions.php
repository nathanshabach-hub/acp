<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\Admin\TransactionsController;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Http\Session;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run this read-only regression test locally only.');
}
$db = ConnectionManager::get('default');
$source = $db->execute(
    'SELECT conventionseason_id,convention_id,season_year FROM transactions ORDER BY id DESC LIMIT 1'
)->fetch('assoc');
if (!$source) {
    throw new RuntimeException('At least one transaction is required.');
}
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
foreach (['all', 'header', 'search', 'empty'] as $mode) {
    $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
    $session->write('admin_id', 1);
    $conditions = [];
    $body = [];
    if ($mode === 'header') {
        $session->write('sess_admin_header_season_id', $source['conventionseason_id']);
        $conditions[] = 'conventionseason_id = ' . (int)$source['conventionseason_id'];
    } elseif ($mode === 'search') {
        $body = ['Transactions' => ['convention_id' => $source['convention_id'], 'season_year' => $source['season_year']]];
        $conditions[] = 'convention_id = ' . (int)$source['convention_id'];
        $conditions[] = 'season_year = ' . (int)$source['season_year'];
    } elseif ($mode === 'empty') {
        $session->write('sess_admin_header_season_id', PHP_INT_MAX);
        $conditions[] = 'conventionseason_id = ' . PHP_INT_MAX;
    }
    $request = new ServerRequest([
        'url' => '/admin/transactions', 'session' => $session,
        'params' => ['prefix' => 'Admin', 'controller' => 'Transactions', 'action' => 'index', 'pass' => []],
        'environment' => ['REQUEST_METHOD' => $body ? 'POST' : 'GET'], 'post' => $body,
    ]);
    $controller = new TransactionsController($request);
    $controller->index();
    $vars = $controller->viewBuilder()->getVars();
    $check(count($vars['conventionsDD']) === (int)$db->execute('SELECT COUNT(*) FROM conventions')->fetchColumn(0),
        "$mode convention dropdown contains all conventions");
    $check(count($vars['seasonsDD']) === (int)$db->execute('SELECT COUNT(DISTINCT season_year) FROM seasons')->fetchColumn(0),
        "$mode season dropdown contains all years");
    $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    $expected = $db->execute('SELECT id FROM transactions' . $where . ' ORDER BY id DESC LIMIT 50')->fetchAll('assoc');
    $actual = array_map('intval', $vars['transactions']->items()->extract('id')->toList());
    $check($actual === array_map(static fn(array $row): int => (int)$row['id'], $expected),
        "$mode pagination returns correctly filtered transactions");
    foreach ($vars['transactions'] as $transaction) {
        $check($transaction->Conventions !== null && $transaction->Users !== null,
            "$mode transaction includes convention and school");
    }
}
echo "PASS: $checks admin transaction checks. No data changed.\n";
