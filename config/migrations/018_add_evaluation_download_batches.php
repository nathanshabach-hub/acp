<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS evaluation_download_batches (
    token CHAR(64) NOT NULL PRIMARY KEY,
    school_id BIGINT NOT NULL,
    registration_id BIGINT NOT NULL,
    student_ids TEXT NOT NULL,
    created DATETIME NOT NULL,
    expires DATETIME NOT NULL,
    received DATETIME NULL,
    KEY registration_lookup (school_id, registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS evaluation_download_completions (
    registration_id BIGINT NOT NULL,
    registration_student_id BIGINT NOT NULL,
    completed DATETIME NOT NULL,
    batch_token CHAR(64) NOT NULL,
    PRIMARY KEY (registration_id, registration_student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
echo "Shared evaluation download tracking ready.\n";
