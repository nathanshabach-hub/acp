<?php

require dirname(__DIR__) . '/bootstrap.php';

$connection = \Cake\Datasource\ConnectionManager::get('default');
$columns = [
    'qualifying_time_score' => [
        'seasonCondition' => 'IS NOT NULL',
        'globalValue' => 'global_event.`qualifying_time_score`',
    ],
    'qualifying_distance' => [
        'seasonCondition' => 'IS NOT NULL',
        'globalValue' => 'global_event.`qualifying_distance`',
    ],
    'qualifying_score' => [
        'seasonCondition' => 'IS NOT NULL',
        'globalValue' => 'global_event.`qualifying_score`',
    ],
    'current_record' => [
        'seasonCondition' => "IS NOT NULL AND `current_record` <> ''",
        'globalValue' => "NULLIF(global_event.`current_record`, '')",
    ],
];

$connection->transactional(function () use ($connection, $columns): void {
    foreach ($columns as $column => $columnConfig) {
        $connection->execute(<<<SQL
UPDATE events AS global_event
SET global_event.`$column` = COALESCE(
    {$columnConfig['globalValue']},
    (
        SELECT season_event.`$column`
        FROM conventionseasonevents AS season_event
        WHERE season_event.event_id = global_event.id
          AND season_event.`$column` {$columnConfig['seasonCondition']}
        ORDER BY season_event.season_year DESC, season_event.modified DESC, season_event.id DESC
        LIMIT 1
    )
)
SQL);
    }

    $connection->execute(<<<'SQL'
UPDATE conventionseasonevents AS season_event
INNER JOIN events AS global_event ON global_event.id = season_event.event_id
SET
    season_event.qualifying_time_score = COALESCE(global_event.qualifying_time_score, season_event.qualifying_time_score),
    season_event.qualifying_distance = COALESCE(global_event.qualifying_distance, season_event.qualifying_distance),
    season_event.qualifying_score = COALESCE(global_event.qualifying_score, season_event.qualifying_score),
    season_event.current_record = COALESCE(global_event.current_record, season_event.current_record)
SQL);
});

echo "Migration completed successfully.\n";
