<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
foreach (['conventionregistrationstudents', 'crstudentevents', 'eventsubmissions'] as $name) {
    $table = $db->execute('SHOW TABLE STATUS WHERE Name = ?', [$name])->fetch('assoc');
    if (!$table || strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
        throw new RuntimeException($name . ' must use InnoDB before enabling student event review.');
    }
}
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS student_event_reviews (
    student_registration_id BIGINT NOT NULL PRIMARY KEY,
    status VARCHAR(16) NOT NULL,
    event_ids TEXT NOT NULL,
    revision INT NOT NULL DEFAULT 1,
    submitted DATETIME NULL,
    approved DATETIME NULL,
    approved_by INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$reviewTable = $db->execute("SHOW TABLE STATUS WHERE Name = 'student_event_reviews'")->fetch('assoc');
if (!$reviewTable || strcasecmp((string)$reviewTable['Engine'], 'InnoDB') !== 0) {
    throw new RuntimeException('student_event_reviews must use InnoDB before enabling student event review.');
}
echo "Student event review storage ready. Existing event registrations remain approved.\n";
