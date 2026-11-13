<?php
require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
foreach (['combinerequests', 'crstudentevents', 'eventsubmissions'] as $name) {
    $table = $db->execute('SHOW TABLE STATUS WHERE Name = ?', [$name])->fetch('assoc');
    if (!$table || strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
        throw new RuntimeException($name . ' must use InnoDB before enabling combined teams.');
    }
}
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS combined_teams (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    conventionseason_id INT NOT NULL,
    event_id INT NOT NULL,
    school_low_id INT NOT NULL,
    school_high_id INT NOT NULL,
    source_registration_id INT NOT NULL,
    partner_registration_id INT NOT NULL,
    requested_by_user_id INT NOT NULL,
    status SMALLINT NOT NULL DEFAULT 2,
    approved_by_admin_id INT NULL,
    group_name VARCHAR(255) NOT NULL,
    created DATETIME NOT NULL,
    modified DATETIME NOT NULL,
    UNIQUE KEY school_pair_event (conventionseason_id, event_id, school_low_id, school_high_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS combined_team_members (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id BIGINT NOT NULL,
    conventionseason_id INT NOT NULL,
    event_id INT NOT NULL,
    registration_id INT NOT NULL,
    student_id INT NOT NULL,
    reserved TINYINT NULL DEFAULT 1,
    UNIQUE KEY team_student (team_id, student_id),
    UNIQUE KEY student_event_team (conventionseason_id, event_id, student_id, reserved),
    CONSTRAINT combined_team_member_fk FOREIGN KEY (team_id) REFERENCES combined_teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
if (!$db->execute("SHOW COLUMNS FROM combined_team_members LIKE 'reserved'")->fetch('assoc')) {
    $db->execute('ALTER TABLE combined_team_members ADD reserved TINYINT NULL DEFAULT 1');
    $db->execute('UPDATE combined_team_members m JOIN combined_teams t ON t.id = m.team_id SET m.reserved = NULL WHERE t.status = 0');
    $db->execute('ALTER TABLE combined_team_members DROP INDEX student_event_team, ADD UNIQUE KEY student_event_team (conventionseason_id,event_id,student_id,reserved)');
}
foreach (['combinerequests', 'eventsubmissions'] as $name) {
    if (!$db->execute('SHOW COLUMNS FROM ' . $name . " LIKE 'combined_team_id'")->fetch('assoc')) {
        $db->execute('ALTER TABLE ' . $name . ' ADD combined_team_id BIGINT NULL');
    }
}
if (!$db->execute("SHOW INDEX FROM eventsubmissions WHERE Key_name = 'unique_combined_team_submission'")->fetch('assoc')) {
    $db->execute('ALTER TABLE eventsubmissions ADD UNIQUE KEY unique_combined_team_submission (combined_team_id)');
}
foreach (['combined_teams', 'combined_team_members'] as $name) {
    $table = $db->execute('SHOW TABLE STATUS WHERE Name = ?', [$name])->fetch('assoc');
    if (!$table || strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
        throw new RuntimeException($name . ' must use InnoDB.');
    }
}
\Cake\Cache\Cache::clear('_cake_model_');
echo "Migration completed successfully.\n";
