<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use Cake\Datasource\ConnectionManager;

$tables = [
    'schedulingtimings',
    'conventionseasons',
    'conventionregistrations',
    'conventionregistrationstudents',
    'crstudentevents',
    'eventsubmissions',
    'judgeevaluations',
    'results',
    'resultpositions',
];
$database = ConnectionManager::get('default');
$databaseName = (string)$database->execute('SELECT DATABASE() AS database_name')->fetch('assoc')['database_name'];
$fingerprints = [];

foreach ($tables as $table) {
    $columns = $database->execute(
        'SELECT COLUMN_NAME, DATA_TYPE, ORDINAL_POSITION
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION',
        [$table],
    )->fetchAll('assoc');
    if ($columns === []) {
        throw new RuntimeException('A required integrity table is unavailable.');
    }
    if ($table === 'conventionseasons') {
        $columns = array_values(array_filter(
            $columns,
            static fn(array $column): bool => in_array($column['COLUMN_NAME'], ['id', 'results_release'], true),
        ));
    }
    $primaryKey = $database->execute(
        'SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = \'PRIMARY\'
         ORDER BY ORDINAL_POSITION',
        [$table],
    )->fetchAll('assoc');
    if ($primaryKey === []) {
        throw new RuntimeException('A required integrity table has no primary key.');
    }

    $columnNames = array_column($columns, 'COLUMN_NAME');
    $columnTypes = array_column($columns, 'DATA_TYPE', 'COLUMN_NAME');
    $orderNames = array_column($primaryKey, 'COLUMN_NAME');
    $quoteIdentifier = static function (string $identifier): string {
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $identifier) !== 1) {
            throw new RuntimeException('An integrity identifier is invalid.');
        }

        return '`' . $identifier . '`';
    };
    $select = implode(', ', array_map($quoteIdentifier, $columnNames));
    $order = implode(', ', array_map($quoteIdentifier, $orderNames));
    $query = $database->execute(
        'SELECT ' . $select . ' FROM ' . $quoteIdentifier($table) . ' ORDER BY ' . $order,
    );

    $context = hash_init('sha256');
    hash_update($context, "ACP-DB-FINGERPRINT-V1\n");
    hash_update($context, 'T' . strlen($table) . ':' . $table . "\n");
    foreach ($columns as $column) {
        $name = (string)$column['COLUMN_NAME'];
        $type = strtolower((string)$column['DATA_TYPE']);
        hash_update($context, 'C' . strlen($name) . ':' . $name . ':' . strlen($type) . ':' . $type . "\n");
    }

    $count = 0;
    while (($row = $query->fetch('assoc')) !== false) {
        foreach ($columnNames as $columnName) {
            $value = $row[$columnName];
            if ($value === null) {
                hash_update($context, "N;\n");
                continue;
            }
            if (is_resource($value)) {
                $streamValue = stream_get_contents($value);
                fclose($value);
                if ($streamValue === false) {
                    throw new RuntimeException('A binary integrity value could not be read.');
                }
                $value = $streamValue;
            }
            $type = strtolower((string)$columnTypes[$columnName]);
            $binary = in_array($type, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'], true);
            $encoded = $binary ? base64_encode((string)$value) : (string)$value;
            $tag = $binary ? 'B' : 'S';
            hash_update($context, $tag . strlen($encoded) . ':' . $encoded . ";\n");
        }
        hash_update($context, "R\n");
        $count++;
    }

    $fingerprints[$table] = [
        'rows' => $count,
        'columns' => $columnNames,
        'primary_key_order' => $orderNames,
        'sha256' => hash_final($context),
    ];
}

echo json_encode([
    'baseline_type' => 'new deterministic read-only baseline; not comparable to unrecovered Phase C serialization',
    'method' => 'ACP-DB-FINGERPRINT-V1',
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'database' => $databaseName,
    'season_26' => [
        'conventionseason_id' => 26,
        'convention_id' => 7,
        'season_id' => 7,
        'season_year' => 2026,
        'results_release' => 1,
        'scheduling_rows' => 1869,
        'canonical_timing_sha1' => '68f7759f515af2ea88eefb2e0d3d02f60095041b',
    ],
    'tables' => $fingerprints,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
