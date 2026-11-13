<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\ConnectionInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Mailer\Message;

final class SchoolNotifications
{
    // The legacy school_id column stores the recipient users.id for every portal role.
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function available(): bool
    {
        $table = $this->db->execute("SHOW TABLE STATUS WHERE Name = 'school_notifications'")->fetch('assoc');
        if ($table && strcasecmp((string)$table['Engine'], 'InnoDB') !== 0) {
            throw new \RuntimeException('Portal notifications require InnoDB storage.');
        }
        return (bool)$table;
    }

    private function record(int $schoolId, string $key, string $kind, string $subject, string $sender, string $body, ?string $created = null): void
    {
        $this->db->execute(
            'INSERT INTO school_notifications (school_id,event_key,kind,subject,sender,body,created)
             VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE event_key=VALUES(event_key)',
            [$schoolId, $key, $kind, mb_substr($subject, 0, 255), mb_substr($sender, 0, 255), $body, $created ?? date('Y-m-d H:i:s')]
        );
    }

    public function supervisorAgreement(array $agreement): void
    {
        $this->record((int)$agreement['supervisor_id'], 'supervisor-agreement:' . $agreement['id'], 'supervisor_agreement',
            'Supervisor agreement: ' . $agreement['convention_name'] . ' ' . $agreement['season_year'],
            'Accelerate Portal',
            "Students have been assigned to you for this convention. Open this message to review the named students and personally accept the supervisor agreement before using student event registration, grouping, Events of the Heart, submissions or the registration checklist.",
            $agreement['created']);
    }

    public function sponsorship(int $requestId, bool $cancelled = false): void
    {
        $request = $this->db->execute(
            'SELECT r.*, origin.first_name AS origin_name, sponsor.first_name AS sponsor_name
             FROM cross_school_sponsorship_requests r
             JOIN users origin ON origin.id=r.origin_school_id
             JOIN users sponsor ON sponsor.id=r.sponsor_school_id WHERE r.id=?', [$requestId]
        )->fetch('assoc');
        if (!$request) {
            throw new \DomainException('The sponsorship request could not be found.');
        }
        $key = 'sponsorship:' . $requestId . ($cancelled ? ':cancelled' : ':received');
        foreach (['origin_school_id', 'sponsor_school_id'] as $side) {
            $incoming = $side === 'sponsor_school_id';
            $subject = $cancelled ? 'Sponsorship request cancelled' : ($incoming ? 'Sponsorship request received' : 'Sponsorship request submitted');
            $body = $cancelled
                ? 'The originating school cancelled this sponsorship request.'
                : ($incoming ? $request['origin_name'] . ' has requested sponsorship from your school.'
                    : 'Your sponsorship request has been sent to ' . $request['sponsor_name'] . '.');
            $body .= "\n\nOpen Sponsorship Requests to view the current details. School approval and supervisor acceptance are not yet available; this message is not an approval.";
            $this->record((int)$request[$side], $key, 'sponsorship', $subject,
                (string)$request['origin_name'], $body, $cancelled ? null : $request['created']);
        }
    }

    public function emailSent(Message $message): void
    {
        $addresses = array_unique(array_map('strtolower', array_keys($message->getTo() + $message->getCc() + $message->getBcc())));
        if (!$addresses) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($addresses), '?'));
        $schools = $this->db->execute(
            "SELECT id FROM users WHERE user_type IN ('School','Teacher_Parent','Judge','Student') AND status=1 AND activation_status=1
             AND LOWER(email_address) IN ($placeholders)", array_values($addresses)
        )->fetchAll('assoc');
        if (!$schools) {
            return;
        }
        if (!$this->available()) {
            throw new \RuntimeException('Apply migration 012 to record portal email notifications.');
        }
        $key = 'email:' . bin2hex(random_bytes(16));
        $this->db->transactional(function () use ($schools, $message, $key): void {
            foreach ($schools as $school) {
                $this->record((int)$school['id'], $key, 'email', $message->getSubject(), 'Accelerate Portal',
                    "A portal email with this subject was sent to your registered email address.\n\nPlease check your email inbox (and junk folder) for the full message and any attachments. Acceptance by the mail server does not guarantee delivery.\n\nFor your security, email bodies, passwords and reset links are not copied into this inbox.");
            }
        });
    }

    public function unreadCount(int $recipientId): int
    {
        return (int)$this->db->execute(
            'SELECT COUNT(*) FROM school_notifications WHERE school_id=? AND read_at IS NULL AND archived_at IS NULL',
            [$recipientId]
        )->fetchColumn(0);
    }

    public function inbox(int $schoolId, string $folder, int $page): array
    {
        if (!in_array($folder, ['inbox', 'unread', 'archive'], true)) {
            throw new \InvalidArgumentException('Invalid notification folder.');
        }
        $where = 'school_id=? AND archived_at IS ' . ($folder === 'archive' ? 'NOT NULL' : 'NULL');
        if ($folder === 'unread') {
            $where .= ' AND read_at IS NULL';
        }
        $total = (int)$this->db->execute("SELECT COUNT(*) FROM school_notifications WHERE $where", [$schoolId])->fetchColumn(0);
        $pages = max(1, (int)ceil($total / 25));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * 25;
        $messages = $this->db->execute(
            "SELECT * FROM school_notifications WHERE $where ORDER BY created DESC,id DESC LIMIT 25 OFFSET $offset", [$schoolId]
        )->fetchAll('assoc');
        $counts = $this->db->execute(
            'SELECT COUNT(CASE WHEN archived_at IS NULL AND read_at IS NULL THEN 1 END) AS unread,
                    COUNT(CASE WHEN archived_at IS NOT NULL THEN 1 END) AS archived
             FROM school_notifications WHERE school_id=?', [$schoolId]
        )->fetch('assoc');
        return compact('messages', 'counts', 'page', 'pages', 'total');
    }

    public function message(int $schoolId, int $id): array
    {
        $message = $this->db->execute('SELECT * FROM school_notifications WHERE id=? AND school_id=?', [$id, $schoolId])->fetch('assoc');
        if (!$message) {
            throw new NotFoundException('Notification not found.');
        }
        return $message;
    }

    public function update(int $schoolId, int $id, string $action): void
    {
        $this->message($schoolId, $id);
        $updates = [
            'read' => 'read_at=COALESCE(read_at,NOW())',
            'unread' => 'read_at=NULL',
            'archive' => 'archived_at=COALESCE(archived_at,NOW())',
            'restore' => 'archived_at=NULL',
        ];
        if (!isset($updates[$action])) {
            throw new \InvalidArgumentException('Invalid notification action.');
        }
        $this->db->execute('UPDATE school_notifications SET ' . $updates[$action] . ' WHERE id=? AND school_id=?', [$id, $schoolId]);
    }
}
