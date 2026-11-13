<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$connection = \Cake\Datasource\ConnectionManager::get('default');
$connection->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    user_id BIGINT NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    email_address VARCHAR(255) NOT NULL,
    credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires DATETIME NOT NULL,
    created DATETIME NOT NULL,
    consumed DATETIME DEFAULT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY password_reset_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$column = $connection->execute("SHOW COLUMNS FROM password_reset_tokens LIKE 'credential_hash'")->fetch('assoc');
if (!$column) {
    $connection->execute('ALTER TABLE password_reset_tokens ADD COLUMN credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER email_address');
}
$connection->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS password_reset_rate_limits (
    subject CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start DATETIME NOT NULL,
    attempts INT NOT NULL,
    PRIMARY KEY (subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
foreach (['password_reset_tokens', 'password_reset_rate_limits'] as $name) {
    $table = $connection->execute('SHOW TABLE STATUS WHERE Name = ?', [$name])->fetch('assoc');
    if (!$table || strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
        throw new RuntimeException($name . ' must use InnoDB.');
    }
}
echo "Migration completed successfully.\n";
