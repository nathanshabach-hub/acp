<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
foreach (['conventionseasons', 'conventionregistrations', 'transactions'] as $table) {
    $meta = $db->execute('SHOW TABLE STATUS WHERE Name=?', [$table])->fetch('assoc');
    if (!$meta || strcasecmp((string)$meta['Engine'], 'InnoDB') !== 0) {
        throw new RuntimeException("Judge billing requires InnoDB storage for $table.");
    }
}
foreach (['conventionseasons' => 'judge_registration_fees', 'conventionregistrations' => 'judge_registration_fee'] as $table => $column) {
    if (!$db->execute("SHOW COLUMNS FROM $table LIKE ?", [$column])->fetch('assoc')) {
        $db->execute("ALTER TABLE $table ADD $column DECIMAL(10,2) NULL DEFAULT NULL");
    }
}
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS transactionjudges (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT NOT NULL,
    conventionregistration_id BIGINT NOT NULL,
    judge_id BIGINT NOT NULL,
    paid_amount DECIMAL(10,2) NOT NULL,
    status SMALLINT NOT NULL,
    created DATETIME NOT NULL,
    modified DATETIME NULL,
    UNIQUE KEY judge_transaction (transaction_id,conventionregistration_id),
    KEY judge_registration (conventionregistration_id),
    CONSTRAINT judge_transaction_fk FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
\Cake\Cache\Cache::clear('_cake_model_');
echo "Judge registration fees ready. Existing fees remain unset; no judge transactions or invoices created.\n";
