<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Database\Connection;
use DomainException;
use Psr\Http\Message\UploadedFileInterface;

final class AdultScriptureSubmissions
{
    private const AWARDS = [
        'golden apple award open', 'golden lamb award open', 'golden harp award open',
        'christian soldier award open', 'christian worker award open', 'silver apple award open',
    ];
    private const EXTENSIONS = [
        'mp3', 'mp4', 'mpeg', 'mov', 'avi', 'amr', 'ape', 'nmf', '3gp',
        'pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'ppt', 'pptx',
    ];

    public function __construct(private Connection $db)
    {
    }

    public function context(int $userId, string $userType, int $registrationId): array
    {
        $user = $this->db->execute(
            "SELECT id, user_type, school_id, first_name, middle_name, last_name FROM users WHERE id=? AND user_type=? AND status=1 AND activation_status=1",
            [$userId, $userType]
        )->fetch('assoc');
        if (!$user || !in_array($userType, ['Judge', 'Teacher_Parent'], true)) {
            throw new DomainException('An active supervisor or judge account is required.');
        }
        $registration = $this->db->execute(
            'SELECT cr.*, cs.submissions_open, cs.registration_start_date, cs.registration_end_date, c.name AS convention_name
             FROM conventionregistrations cr JOIN conventionseasons cs ON cs.id=cr.conventionseason_id
             JOIN conventions c ON c.id=cr.convention_id AND c.id=cs.convention_id
             WHERE cr.id=? AND cr.season_id=cs.season_id AND cr.season_year=cs.season_year',
            [$registrationId]
        )->fetch('assoc');
        if (!$registration) {
            throw new DomainException('Select a convention before opening Scripture.');
        }
        $judge = (int)$registration['user_id'] === $userId && (int)$registration['status'] === 1;
        $supervisor = $userType === 'Teacher_Parent' && (int)$registration['user_id'] === (int)$user['school_id']
            && (bool)$this->db->execute(
                'SELECT id FROM conventionregistrationteachers WHERE conventionregistration_id=? AND teacher_id=? AND status=1 LIMIT 1',
                [$registrationId, $userId]
            )->fetch('assoc');
        if (!$judge && !$supervisor) {
            throw new DomainException('You are not registered for the selected convention.');
        }

        $registration['participant_name'] = implode(' ', array_filter([
            trim((string)$user['first_name']), trim((string)$user['middle_name']), trim((string)$user['last_name']),
        ], static fn(string $name): bool => $name !== ''));

        return $registration;
    }

    public function awards(int $seasonId): array
    {
        $events = $this->db->execute(
            'SELECT DISTINCT e.id,e.event_name,e.event_id_number,e.book_ids FROM conventionseasonevents ce
             JOIN events e ON e.id=ce.event_id WHERE ce.conventionseasons_id=? AND e.certificate_print=1 ORDER BY e.event_id_number',
            [$seasonId]
        )->fetchAll('assoc');

        return array_values(array_filter($events, fn(array $event): bool =>
            in_array(strtolower(trim((string)$event['event_name'])), self::AWARDS, true)));
    }

    public function books(array $award): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$award['book_ids'])))));
        if (!$ids) {
            return [];
        }
        $params = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->execute("SELECT id,book_name FROM books WHERE id IN ($params) ORDER BY book_name", $ids)->fetchAll('assoc');
    }

    public function award(int $seasonId, int $eventId): array
    {
        foreach ($this->awards($seasonId) as $award) {
            if ((int)$award['id'] === $eventId) {
                return $award;
            }
        }
        throw new DomainException('This Scripture award is not available for the selected convention.');
    }

    public function submit(int $seasonId, int $userId, int $eventId, array $bookIds, UploadedFileInterface $file): void
    {
        $award = $this->award($seasonId, $eventId);
        $allowed = array_map('intval', array_column($this->books($award), 'id'));
        $ids = [];
        foreach ($bookIds as $id) {
            if ((!is_string($id) && !is_int($id)) || !ctype_digit((string)$id) || !in_array((int)$id, $allowed, true)) {
                throw new DomainException('Select valid books for this award.');
            }
            $ids[] = (int)$id;
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            throw new DomainException('Select at least one book.');
        }
        if ($file->getError() !== UPLOAD_ERR_OK || !$file->getSize()) {
            throw new DomainException('Upload a non-empty file within the server upload limit.');
        }
        $original = basename(str_replace('\\', '/', (string)$file->getClientFilename()));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($original === '' || strlen($original) > 255 || preg_match('/[\x00-\x1f\x7f]/', $original)
            || !in_array($extension, self::EXTENSIONS, true)) {
            throw new DomainException('The file type is not allowed.');
        }
        $directory = $this->storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create Scripture upload storage.');
        }
        $stored = bin2hex(random_bytes(24)) . '.' . $extension;
        $path = $directory . DIRECTORY_SEPARATOR . $stored;
        $file->moveTo($path);
        try {
            $this->db->execute(
                'INSERT INTO adult_scripture_submissions (conventionseason_id,user_id,event_id,book_ids,original_filename,stored_filename,created)
                 VALUES (?,?,?,?,?,?,NOW())',
                [$seasonId, $userId, $eventId, implode(',', $ids), $original, $stored]
            );
        } catch (\Throwable $error) {
            if (is_file($path) && !unlink($path)) {
                \Cake\Log\Log::error('Could not remove failed Scripture upload: ' . $path);
            }
            throw $error;
        }
    }

    public function forSeason(int $seasonId): array
    {
        return $this->db->execute(
            'SELECT s.*,u.first_name,u.middle_name,u.last_name,u.user_type,e.event_name,e.event_id_number,
                    c.name AS convention_name
             FROM adult_scripture_submissions s JOIN users u ON u.id=s.user_id JOIN events e ON e.id=s.event_id
             JOIN conventionseasons cs ON cs.id=s.conventionseason_id JOIN conventions c ON c.id=cs.convention_id
             WHERE s.conventionseason_id=? ORDER BY s.created DESC,s.id DESC',
            [$seasonId]
        )->fetchAll('assoc');
    }

    public function forUser(int $seasonId, int $userId): array
    {
        return $this->db->execute(
            'SELECT s.*,e.event_name FROM adult_scripture_submissions s
             JOIN events e ON e.id=s.event_id
             WHERE s.conventionseason_id=? AND s.user_id=? ORDER BY s.created DESC,s.id DESC',
            [$seasonId, $userId]
        )->fetchAll('assoc');
    }

    public function get(int $id): ?array
    {
        $row = $this->db->execute(
            'SELECT s.*,u.first_name,u.middle_name,u.last_name,u.user_type,e.event_name,e.event_id_number,
                    c.name AS convention_name
             FROM adult_scripture_submissions s JOIN users u ON u.id=s.user_id JOIN events e ON e.id=s.event_id
             JOIN conventionseasons cs ON cs.id=s.conventionseason_id JOIN conventions c ON c.id=cs.convention_id
             WHERE s.id=?',
            [$id]
        )->fetch('assoc');

        return $row ?: null;
    }

    public function filePath(array $submission): string
    {
        $filename = (string)$submission['stored_filename'];
        if (!preg_match('/^[a-f0-9]{48}\\.[a-z0-9]+$/D', $filename)) {
            throw new \RuntimeException('Invalid stored Scripture file name.');
        }
        $path = $this->storageDirectory() . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new \RuntimeException('The Scripture submission file is missing.');
        }

        return $path;
    }

    private function storageDirectory(): string
    {
        $base = \App\Deployment\Environment::isHosted()
            ? \App\Deployment\Environment::settings()['storageRoot']
            : LOGS;

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'adult_scripture_submissions';
    }
}
