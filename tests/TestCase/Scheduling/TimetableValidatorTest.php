<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Scheduling\TimetableValidator;
use PHPUnit\Framework\TestCase;

class TimetableValidatorTest extends TestCase
{
    private function context(): array
    {
        return [
            'dates' => ['2026-10-27' => ['day' => 'Tuesday', 'start' => 8 * 60, 'end' => 17 * 60]],
            'blocked' => ['2026-10-27' => [[12 * 60, 13 * 60, 'lunch']]],
            'event_windows' => [],
            'room_windows' => [],
            'pinned' => [],
            'event_rooms' => [100 => [1 => 1], 200 => [1 => 1]],
            'events' => [
                100 => ['number' => '100', 'name' => 'Event A', 'duration' => 30, 'category' => 4],
                200 => ['number' => '200', 'name' => 'Event B', 'duration' => 30, 'category' => 4],
            ],
            'rooms' => [1 => 'Room 1'],
            'buffer' => 0,
            'preferred_gap' => 5,
        ];
    }

    public function testDetectsStudentAndRoomConflicts(): void
    {
        $bookings = [
            1 => ['id' => 1, 'category' => 4, 'event_id' => 100, 'room_id' => 1, 'date' => '2026-10-27', 'start' => 8 * 60, 'finish' => 8 * 60 + 30, 'students' => [7], 'possible' => [], 'bye' => false, 'round' => 0, 'match' => 0, 'feeders' => []],
            2 => ['id' => 2, 'category' => 4, 'event_id' => 200, 'room_id' => 1, 'date' => '2026-10-27', 'start' => 8 * 60 + 15, 'finish' => 8 * 60 + 45, 'students' => [7], 'possible' => [], 'bye' => false, 'round' => 0, 'match' => 0, 'feeders' => []],
        ];

        $report = (new TimetableValidator($this->context()))->validate($bookings);
        $types = array_column($report['issues'], 'type');
        $this->assertContains(TimetableValidator::STUDENT_OVERLAP, $types);
        $this->assertContains(TimetableValidator::ROOM_CLASH, $types);
    }

    public function testDetectsBlockedPeriodAndUnscheduled(): void
    {
        $bookings = [
            1 => ['id' => 1, 'category' => 4, 'event_id' => 100, 'room_id' => 1, 'date' => '2026-10-27', 'start' => 12 * 60 + 10, 'finish' => 12 * 60 + 40, 'students' => [1], 'possible' => [], 'bye' => false, 'round' => 0, 'match' => 0, 'feeders' => []],
            2 => ['id' => 2, 'category' => 4, 'event_id' => 100, 'room_id' => 1, 'date' => null, 'start' => null, 'finish' => null, 'students' => [2], 'possible' => [], 'bye' => false, 'round' => 0, 'match' => 0, 'feeders' => []],
        ];

        $report = (new TimetableValidator($this->context()))->validate($bookings);
        $types = array_column($report['issues'], 'type');
        $this->assertContains(TimetableValidator::BLOCKED_PERIOD, $types);
        $this->assertContains(TimetableValidator::UNSCHEDULED, $types);
    }
}
