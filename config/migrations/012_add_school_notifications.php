<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = \Cake\Datasource\ConnectionManager::get('default');
$db->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS school_notifications (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    school_id BIGINT NOT NULL,
    event_key VARCHAR(191) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    sender VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    created DATETIME NOT NULL,
    read_at DATETIME NULL,
    archived_at DATETIME NULL,
    UNIQUE KEY school_event (school_id, event_key),
    KEY school_inbox (school_id, archived_at, created, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
$notifications = new \App\Service\SchoolNotifications($db);
if (!$notifications->available()) {
    throw new RuntimeException('School notification storage could not be created.');
}
if ((new \App\Service\CrossSchoolSponsorship($db))->available()) {
    $requests = $db->execute("SELECT id FROM cross_school_sponsorship_requests WHERE status = 'pending_school'")->fetchAll('assoc');
    foreach ($requests as $request) {
        $notifications->sponsorship((int)$request['id']);
    }
}
echo "School notification inbox ready. Existing pending sponsorship requests imported; old emails are not imported.\n";
