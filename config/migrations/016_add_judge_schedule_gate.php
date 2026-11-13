<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
if (!$db->execute("SHOW COLUMNS FROM conventionseasons LIKE 'judge_schedule_state'")->fetch('assoc')) {
    $db->execute('ALTER TABLE conventionseasons ADD judge_schedule_state VARCHAR(64) NULL DEFAULT NULL');
}
\Cake\Cache\Cache::clear('_cake_model_');
echo "Judge schedule gate ready. Existing schedules must be run again to unlock in-person judging.\n";
