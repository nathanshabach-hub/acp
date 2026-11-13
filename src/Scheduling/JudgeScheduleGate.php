<?php
declare(strict_types=1);

namespace App\Scheduling;

use App\Utility\JudgingEventCategories;
use Cake\Database\Connection;
use DomainException;

final class JudgeScheduleGate
{
    public function __construct(private Connection $db)
    {
    }

    public function start(int $seasonId): string
    {
        $token = bin2hex(random_bytes(16));
        $this->db->execute('UPDATE conventionseasons SET judge_schedule_state=? WHERE id=?', [$token . ':0', $seasonId]);

        return $token;
    }

    public function enter(int $seasonId, ?string $token, int $previousStage): void
    {
        $state = $this->db->execute('SELECT judge_schedule_state FROM conventionseasons WHERE id=?', [$seasonId])->fetchColumn(0);
        if (!$token || $state !== $token . ':' . $previousStage) {
            $this->clear($seasonId);
        }
    }

    public function advance(int $seasonId, ?string $token, int $previousStage, int $nextStage): void
    {
        if ($token) {
            $this->db->execute(
                'UPDATE conventionseasons SET judge_schedule_state=? WHERE id=? AND judge_schedule_state=?',
                [$token . ':' . $nextStage, $seasonId, $token . ':' . $previousStage]
            );
        }
    }

    public function clear(int $seasonId): void
    {
        $this->db->execute('UPDATE conventionseasons SET judge_schedule_state=NULL WHERE id=?', [$seasonId]);
    }

    public function isOpen(int $seasonId): bool
    {
        $state = $this->db->execute('SELECT judge_schedule_state FROM conventionseasons WHERE id=?', [$seasonId])->fetchColumn(0);
        if (!is_string($state) || !str_ends_with($state, ':6')) {
            return false;
        }
        $data = (new TimetableLoader($this->db))->load($seasonId);
        if (!array_filter($data['bookings'], fn(array $booking): bool => empty($booking['bye']) && TimetableValidator::isTimed($booking))) {
            return false;
        }

        return (new TimetableValidator($data['context']))->validate($data['bookings'])['total_issues'] === 0;
    }

    public function lockedSelections(int $seasonId, array $submitted, ?string $previous): array
    {
        $events = [];
        foreach ($this->db->execute(
            'SELECT e.id,e.event_name,d.name AS division_name FROM conventionseasonevents ce
             JOIN events e ON e.id=ce.event_id LEFT JOIN divisions d ON d.id=e.division_id
             WHERE ce.conventionseasons_id=?', [$seasonId]
        )->fetchAll('assoc') as $event) {
            $events[(int)$event['id']] = JudgingEventCategories::isPreJudged(
                (string)$event['division_name'], (string)$event['event_name']
            );
        }
        $existing = array_filter(array_map('intval', explode(',', (string)$previous)));
        $ids = [];
        foreach ($submitted as $id) {
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || !isset($events[(int)$id])) {
                throw new DomainException('Select valid judge events for this convention.');
            }
            $id = (int)$id;
            if (!$events[$id] && !in_array($id, $existing, true)) {
                throw new DomainException('In-Person Events are unavailable until scheduling is complete and all issues are resolved.');
            }
            $ids[] = $id;
        }
        foreach ($existing as $id) {
            if (isset($events[$id]) && !$events[$id]) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
