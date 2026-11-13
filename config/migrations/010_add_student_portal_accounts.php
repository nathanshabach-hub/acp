<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS student_portal_accounts (
    student_id BIGINT NOT NULL PRIMARY KEY,
    login_id CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    password_hash VARCHAR(255) NULL,
    setup_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    setup_expires DATETIME NULL,
    setup_issued DATETIME NULL,
    first_login DATETIME NULL,
    credential_version INT NOT NULL DEFAULT 0,
    UNIQUE KEY student_login_id (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS student_portal_rate_limits (
    subject CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    window_start DATETIME NOT NULL,
    attempts INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
foreach (['student_portal_accounts', 'student_portal_rate_limits'] as $name) {
    $table = $db->execute('SHOW TABLE STATUS WHERE Name = ?', [$name])->fetch('assoc');
    if (!$table || strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
        throw new RuntimeException($name . ' must use InnoDB.');
    }
}
$service = new \App\Service\StudentPortalAccounts($db);
$ids = $db->execute("SELECT id FROM users WHERE user_type = 'Student' AND school_id > 0")->fetchAll('assoc');
foreach ($ids as $row) {
    $service->ensure((int)$row['id']);
}
echo "Student portal storage ready; IDs assigned. No setup codes or passwords issued.\n";
$unassigned = (int)$db->execute("SELECT COUNT(*) FROM users WHERE user_type = 'Student' AND (school_id IS NULL OR school_id <= 0)")->fetchColumn(0);
if ($unassigned > 0) {
    echo $unassigned . " legacy students have no school and cannot enroll. Assign them to a school Global Students List first.\n";
}
