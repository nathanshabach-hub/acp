<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
foreach (['conventionregistrations', 'conventionregistrationteachers', 'conventionregistrationstudents', 'school_notifications'] as $table) {
    $status = $db->execute('SHOW TABLE STATUS WHERE Name=?', [$table])->fetch('assoc');
    if (!$status || strcasecmp((string)$status['Engine'], 'InnoDB') !== 0) {
        throw new RuntimeException("Apply the earlier migrations first; $table must use InnoDB.");
    }
}
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS supervisor_agreements (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supervisor_id BIGINT NOT NULL,
    convention_id BIGINT NOT NULL,
    season_id BIGINT NOT NULL,
    season_year INT NOT NULL,
    created DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    accepted_by BIGINT NULL,
    signature VARCHAR(255) NULL,
    declaration_version INT NULL,
    snapshot MEDIUMTEXT NULL,
    UNIQUE KEY supervisor_convention (supervisor_id,convention_id,season_id,season_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$service = new \App\Service\SupervisorAgreements($db);
if (!$service->available()) {
    throw new RuntimeException('Supervisor agreement storage could not be created.');
}
$contexts = $db->execute(
    "SELECT DISTINCT s.teacher_parent_id,s.convention_id,s.season_id,s.season_year
     FROM conventionregistrationstudents s JOIN users u ON u.id=s.teacher_parent_id
     JOIN conventions c ON c.id=s.convention_id
     WHERE s.status=1 AND s.teacher_parent_id>0 AND u.user_type='Teacher_Parent'"
)->fetchAll('assoc');
foreach ($contexts as $context) {
    $service->ensure((int)$context['teacher_parent_id'], (int)$context['convention_id'], (int)$context['season_id'], (int)$context['season_year']);
}
echo "Supervisor agreement storage ready. Existing assignments notified; no agreements signed or emails sent.\n";
