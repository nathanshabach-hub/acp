<?php

require dirname(__DIR__) . '/bootstrap.php';

$connection = \Cake\Datasource\ConnectionManager::get('default');
$columns = [
    'qualifying_time_score' => 'TIME(3) NULL',
    'qualifying_distance' => 'DOUBLE(16,2) NULL',
    'qualifying_score' => 'INT NULL',
    'current_record' => 'VARCHAR(255) NULL',
];

foreach ($columns as $column => $definition) {
    $existing = $connection->execute("SHOW COLUMNS FROM events LIKE '$column'")->fetchAll('assoc');
    if (empty($existing)) {
        $connection->execute("ALTER TABLE events ADD COLUMN `$column` $definition");
    }
}

\Cake\Cache\Cache::clear('_cake_model_');

echo "Migration completed successfully.\n";
