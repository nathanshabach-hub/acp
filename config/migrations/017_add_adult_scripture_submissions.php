<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS adult_scripture_submissions (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conventionseason_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    event_id BIGINT NOT NULL,
    book_ids TEXT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(128) NOT NULL,
    created DATETIME NOT NULL,
    KEY season_lookup (conventionseason_id),
    KEY owner_lookup (user_id, conventionseason_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
echo "Adult scripture submission storage ready.\n";
