<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Scheduling\ConflictRepairPlanner;
use App\Scheduling\TimetableValidator;
use PHPUnit\Framework\TestCase;

class ConflictRepairPlannerTest extends TestCase
{
    private function context(): array
    {
        return [
            'dates' => ['2026-10-27' => ['day' => 'Tuesday', 'start' => 8 * 60, 'end' => 17 * 60]],
            'blocked' => ['2026-10-27' => []],
            'event_windows' => [],
            'room_windows' => [],
            'pinned' => [],
            'event_rooms' => [100 => [1 => 1, 2 => 1]],
            'events' => [100 => ['number' => '100', 'name' => 'Event A', 'duration' => 30, 'category' => 4]],
            'rooms' => [1 => 'Room 1', 2 => 'Room 2'],
            'buffer' => 0,
            'preferred_gap' => 5,
        ];
    }

    public function testMovesOneOverlapWithoutCreatingNewIssues(): void
    {
        $bookings = [
            1 => ['id' => 1, 'category' => 4, 'event_id' => 100, 'room_id' => 1, 'date' => '2026-10-27', 'start' => 8 * 60, 'finish' => 8 * 60 + 30, 'students' => [10], 'possible' => [], 'bye' => false, 'round' => 0, 'match' => 0, 'feeders' => [], 'label' => 'A', 'modified' => '2026-10-27 08:00:00'],
            2 => ['id' => 2, 'category' => 4, 'event_id' => 100, 'room_id' => 2, 'date' => '2026-10-27', 'start' => 8 * 60, 'finish' => 8 * 60 + 30, 'students' => [10], 'possible' => [], 'bye' => false, 'round' => 0, 'match' => 0, 'feeders' => [], 'label' => 'B', 'modified' => '2026-10-27 08:01:00'],
        ];

        $planner = new ConflictRepairPlanner($this->context());
        $plan = $planner->plan($bookings);
        $this->assertNotEmpty($plan['moves']);
        $this->assertLessThan($plan['before']['total_issues'], $plan['after']['total_issues']);

        $afterTypes = array_column($plan['after']['issues'], 'type');
        $this->assertNotContains(TimetableValidator::STUDENT_OVERLAP, $afterTypes);
    }

    public function testLockedRowsStayUnchanged(): void
    {
        $bookings = [
            1 => ['id' => 1, 'category' => 4, 'event_id' => 100, 'room_id' => 1, 'date' => '2026-10-27', 'start' => 8 * 60, 'finish' => 8 * 60 + 30, 'students' => [10], 'possible' => [], 'bye' => false, 'round' => 0, 'match' => 0, 'feeders' => [], 'label' => 'A', 'modified' => '2026-10-27 08:00:00'],
            2 => ['id' => 2, 'category' => 4, 'event_id' => 100, 'room_id' => 2, 'date' => '2026-10-27', 'start' => 8 * 60, 'finish' => 8 * 60 + 30, 'students' => [10], 'possible' => [], 'bye' => false, 'round' => 0, 'match' => 0, 'feeders' => [], 'label' => 'B', 'modified' => '2026-10-27 08:01:00'],
        ];

        $planner = new ConflictRepairPlanner($this->context());
        $plan = $planner->plan($bookings, [1]);
        $this->assertNotEmpty($plan['moves']);
        foreach ($plan['moves'] as $move) {
            $this->assertNotContains(1, $move['rows']);
        }
    }

    private function row(int $id, int $roomId, int $start, int $finish, array $students, string $modified, array $extra = []): array
    {
        return $extra + ['id' => $id, 'category' => 4, 'event_id' => 100, 'room_id' => $roomId, 'date' => '2026-10-27', 'start' => $start, 'finish' => $finish, 'students' => $students, 'possible' => [], 'bye' => false, 'round' => 0, 'match' => 0, 'feeders' => [], 'label' => 'R'.$id, 'modified' => $modified];
    }

    public function testBatchRowsSharingASessionStayWhenAnIntruderOverlaps(): void
    {
        // Rows 1 and 2 are one speller batch (same slot, one lane). Row 3 was dragged on top of it.
        $bookings = [
            1 => $this->row(1, 1, 480, 510, [10], '2026-10-01 08:00:00'),
            2 => $this->row(2, 1, 480, 510, [11], '2026-10-01 08:00:00'),
            3 => $this->row(3, 1, 490, 520, [12], '2026-10-02 08:00:00'),
        ];

        $plan = (new ConflictRepairPlanner($this->context()))->plan($bookings);
        $this->assertCount(1, $plan['moves']);
        $this->assertSame([3], $plan['moves'][0]['rows']);
        $this->assertSame(0, $plan['after']['total_issues']);
    }

    public function testKnockoutOrderMovesOnlyTheEditedMatch(): void
    {
        // R2 match 11 was dragged to start before its feeder 10 finishes. Only one of them has to move.
        $context = $this->context();
        $context['event_rooms'][100][3] = 1;
        $context['rooms'][3] = 'Room 3';
        $knockout = ['category' => 2];
        $bookings = [
            10 => $this->row(10, 1, 480, 510, [10, 11], '2026-10-01 08:00:00', $knockout + ['round' => 1, 'match' => 1]),
            12 => $this->row(12, 2, 480, 490, [12, 13], '2026-10-01 08:00:00', $knockout + ['round' => 1, 'match' => 2]),
            11 => $this->row(11, 3, 495, 525, [], '2026-10-02 08:00:00', $knockout + ['round' => 2, 'match' => 1, 'feeders' => [10, 12], 'possible' => [10, 11, 12, 13]]),
        ];

        $plan = (new ConflictRepairPlanner($context))->plan($bookings);
        $this->assertSame(1, $plan['before']['total_issues']);
        $this->assertCount(1, $plan['moves']);
        $this->assertSame([11], $plan['moves'][0]['rows']);
        $this->assertGreaterThanOrEqual(510, $plan['bookings'][11]['start']);
        $this->assertSame(480, $plan['bookings'][10]['start']);
        $this->assertSame(0, $plan['after']['total_issues']);
    }
}
