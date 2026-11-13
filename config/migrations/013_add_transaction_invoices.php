<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
$parent = $db->execute("SHOW TABLE STATUS WHERE Name='transactions'")->fetch('assoc');
if (!$parent || strcasecmp((string)$parent['Engine'], 'InnoDB') !== 0) {
    throw new RuntimeException('Invoice generation requires InnoDB transactions.');
}
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS transaction_invoices (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT NOT NULL,
    snapshot MEDIUMTEXT NOT NULL,
    issued DATE NOT NULL,
    due DATE NOT NULL,
    created_by BIGINT NOT NULL,
    emailed_at DATETIME NULL,
    UNIQUE KEY invoice_transaction (transaction_id),
    CONSTRAINT invoice_transaction_fk FOREIGN KEY (transaction_id)
        REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
if (!(new \App\Service\TransactionInvoices($db))->available()) {
    throw new RuntimeException('Invoice storage could not be created.');
}
echo "Transaction invoice storage ready. No invoices generated or emails sent.\n";
