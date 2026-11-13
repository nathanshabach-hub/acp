<?php

require dirname(__DIR__) . '/bootstrap.php';

$connection = \Cake\Datasource\ConnectionManager::get('default');
$columns = [
    'guideline_breach_level' => 'VARCHAR(16) NULL DEFAULT NULL',
    'guideline_breach_team_response' => 'TEXT NULL',
    'guideline_breach_display_removed' => 'TINYINT(1) NOT NULL DEFAULT 0',
];

foreach ($columns as $column => $definition) {
    $existing = $connection->execute("SHOW COLUMNS FROM eventsubmissions LIKE '$column'")->fetchAll('assoc');
    if (empty($existing)) {
        $connection->execute("ALTER TABLE eventsubmissions ADD COLUMN `$column` $definition");
    }
}

\Cake\Cache\Cache::clear('_cake_model_');

echo "Migration completed successfully.\n";
