<?php
require dirname(__DIR__) . '/bootstrap.php';

$connection = \Cake\Datasource\ConnectionManager::get('default');
$parent = $connection->execute("SHOW TABLE STATUS WHERE Name = 'conventionregistrationstudents'")->fetch('assoc');
if (!$parent || strcasecmp((string)$parent['Engine'], 'InnoDB') !== 0) {
    throw new \RuntimeException('conventionregistrationstudents must use InnoDB before this migration can run.');
}
$connection->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS cross_school_sponsorship_requests (
    id BIGINT NOT NULL AUTO_INCREMENT,
    student_registration_id BIGINT NOT NULL,
    origin_school_id INT NOT NULL,
    sponsor_school_id INT NOT NULL,
    supervisor_id INT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending_school',
    created DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_student_registration (student_registration_id),
    KEY sponsor_school_status (sponsor_school_id, status),
    KEY origin_school_status (origin_school_id, status),
    CONSTRAINT cross_school_student_fk FOREIGN KEY (student_registration_id)
        REFERENCES conventionregistrationstudents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$table = $connection->execute("SHOW TABLE STATUS WHERE Name = 'cross_school_sponsorship_requests'")->fetch('assoc');
if (!$table || strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
    throw new \RuntimeException('cross_school_sponsorship_requests must use InnoDB.');
}
echo "Migration completed successfully.\n";
