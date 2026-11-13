<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Scheduling\SlotPlanner;
use App\Scheduling\TimetableLedger;
use PHPUnit\Framework\TestCase;

class SlotPlannerTest extends TestCase
{
    private const TUE = '2026-10-27';
    private const WED = '2026-10-28';

    private static function band(string $date, string $start, string $end, string $day = ''): array
    {
        return ['date' => $date, 'day' => $day ?: date('l', strtotime($date)), 'start' => $start, 'end' => $end];
    }

    private static function identityResolver(): callable
    {
        return fn (array $band, int $roomId, string $start, string $finish) => ['start' => $start, 'finish' => $finish];
    }

    private static function planner(TimetableLedger $ledger, array $knockoutRows = [], array $blocked = [], int $gap = 5): SlotPlanner
    {
        return new SlotPlanner($ledger, self::identityResolver(), $knockoutRows, $blocked, $gap);
    }

    private static function units(array $studentsByUnit): array
    {
        $units = [];
        foreach ($studentsByUnit as $id => $students) {
            $units[] = ['id' => $id, 'students' => $students];
        }

        return $units;
    }

    /**
     * Asserts that a list of bookings has no student overlaps and no room overlaps
     * between different events or beyond the lane count.
     *
     * @param list<array{room:int,date:string,start:string,finish:string,event:int,students:list<int>,lanes?:int}> $bookings
     */
    private function assertNoConflicts(array $bookings): void
    {
        foreach ($bookings as $i => $a) {
            foreach ($bookings as $j => $b) {
                if ($j <= $i || $a['date'] !== $b['date']) {
                    continue;
                }
                $overlap = TimetableLedger::toMinutes($a['start']) < TimetableLedger::toMinutes($b['finish'])
                    && TimetableLedger::toMinutes($b['start']) < TimetableLedger::toMinutes($a['finish']);
                if (!$overlap) {
                    continue;
                }
                $this->assertSame([], array_values(array_intersect($a['students'], $b['students'])), "student double-booked: booking $i and $j");
                if ($a['room'] === $b['room']) {
                    $this->assertSame($a['event'], $b['event'], "different events share room {$a['room']}: booking $i and $j");
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    private static function bookingsFrom(array $placed, array $units, int $event): array
    {
        $studentsById = [];
        foreach ($units as $unit) {
            $studentsById[(string)$unit['id']] = $unit['students'] ?? [];
        }
        $bookings = [];
        foreach ($placed as $id => $slot) {
            $bookings[] = ['room' => $slot['room_id'], 'date' => $slot['date'], 'start' => $slot['start'], 'finish' => $slot['finish'], 'event' => $event, 'students' => $studentsById[(string)$id]];
        }

        return $bookings;
    }

    public function testStudentEnteredInMultipleIndividualEvents(): void
    {
        $ledger = new TimetableLedger();
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00')];
        $eventA = self::units(['a1' => [1], 'a2' => [2]]);
        $eventB = self::units(['b1' => [1], 'b2' => [3]]);

        $resultA = self::planner($ledger)->place($eventA, $bands, [10 => 1], 30, 100);
        $resultB = self::planner($ledger)->place($eventB, $bands, [11 => 1], 30, 200);

        $this->assertCount(2, $resultA['placed']);
        $this->assertCount(2, $resultB['placed']);
        $this->assertNoConflicts(array_merge(self::bookingsFrom($resultA['placed'], $eventA, 100), self::bookingsFrom($resultB['placed'], $eventB, 200)));
        $this->assertSame('08:00:00', $resultB['placed']['b2']['start'], 'the free student uses the first slot instead of waiting');
    }

    public function testStudentInGroupAndIndividualEvent(): void
    {
        $ledger = new TimetableLedger();
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00')];
        $group = self::units(['team' => [1, 2, 3, 4]]);
        $individual = self::units(['solo3' => [3], 'solo9' => [9]]);

        $groupResult = self::planner($ledger)->place($group, $bands, [10 => 1], 60, 100);
        $soloResult = self::planner($ledger)->place($individual, $bands, [11 => 1], 30, 200);

        $this->assertSame('08:00:00', $groupResult['placed']['team']['start']);
        $this->assertGreaterThanOrEqual(TimetableLedger::toMinutes('09:00'), TimetableLedger::toMinutes($soloResult['placed']['solo3']['start']));
        $this->assertNoConflicts(array_merge(self::bookingsFrom($groupResult['placed'], $group, 100), self::bookingsFrom($soloResult['placed'], $individual, 200)));
    }

    public function testMultipleGroupsSharingAStudentAreNotConcurrent(): void
    {
        $ledger = new TimetableLedger();
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00')];
        $groups = self::units(['g1' => [1, 2], 'g2' => [2, 3], 'g3' => [4, 5]]);

        $result = self::planner($ledger)->place($groups, $bands, [10 => 1, 11 => 1], 30, 100);

        $this->assertCount(3, $result['placed']);
        $this->assertNoConflicts(self::bookingsFrom($result['placed'], $groups, 100));
        $this->assertNotSame($result['placed']['g1']['start'], $result['placed']['g2']['start']);
    }

    public function testTwoDifferentEventsSharingARoomRunOneAfterTheOther(): void
    {
        $ledger = new TimetableLedger();
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00')];
        $eventA = self::units(['a1' => [1], 'a2' => [2]]);
        $eventB = self::units(['b1' => [3], 'b2' => [4]]);

        $resultA = self::planner($ledger)->place($eventA, $bands, [10 => 1], 30, 100);
        $resultB = self::planner($ledger)->place($eventB, $bands, [10 => 1], 30, 200);

        $this->assertSame(['08:00:00', '08:30:00'], array_column($resultA['placed'], 'start'));
        $this->assertSame(['09:00:00', '09:30:00'], array_column($resultB['placed'], 'start'));
        $this->assertNoConflicts(array_merge(self::bookingsFrom($resultA['placed'], $eventA, 100), self::bookingsFrom($resultB['placed'], $eventB, 200)));
    }

    public function testRoomWithMultipleGamesRunsLanesInParallel(): void
    {
        $ledger = new TimetableLedger();
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00')];
        $matches = self::units(['m1' => [1, 2], 'm2' => [3, 4], 'm3' => [5, 6], 'm4' => [7, 8], 'm5' => [9, 10]]);

        $result = self::planner($ledger)->place($matches, $bands, [10 => 2], 30, 100);

        $starts = array_count_values(array_column($result['placed'], 'start'));
        $this->assertSame(['08:00:00' => 2, '08:30:00' => 2, '09:00:00' => 1], $starts);
    }

    public function testCapacityOverrideLimitsParallelGames(): void
    {
        $ledger = new TimetableLedger();
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00')];
        $matches = [
            ['id' => 'm1', 'students' => [1, 2], 'capacity' => 1],
            ['id' => 'm2', 'students' => [3, 4], 'capacity' => 1],
        ];

        $result = self::planner($ledger)->place($matches, $bands, [10 => 5], 30, 100);

        $this->assertSame('08:00:00', $result['placed']['m1']['start']);
        $this->assertSame('08:30:00', $result['placed']['m2']['start']);
    }

    public function testSingleBoardRoundIsNotSharedWithEarlierOrLaterRounds(): void
    {
        $ledger = new TimetableLedger();
        $bands = [self::band(self::TUE, '09:00:00', '12:00:00')];
        // A single-board match is already in the room (placed first, as happened with Checkers OPEN).
        $ledger->book(10, self::TUE, '09:20:00', '09:30:00', 100, [1, 2], [], null, 1);

        $result = self::planner($ledger)->place([['id' => 'r2', 'students' => [3, 4]]], [self::band(self::TUE, '09:20:00', '12:00:00')], [10 => 5], 10, 100);
        $this->assertSame('09:30:00', $result['placed']['r2']['start'], 'normal game waits for the single-board match');

        $ledger = new TimetableLedger();
        $first = self::planner($ledger)->place([['id' => 'r2', 'students' => [3, 4]]], $bands, [10 => 5], 10, 100);
        $this->assertSame('09:00:00', $first['placed']['r2']['start']);
        $second = self::planner($ledger)->place([['id' => 'final', 'students' => [1, 2], 'capacity' => 1]], $bands, [10 => 5], 10, 100);
        $this->assertSame('09:10:00', $second['placed']['final']['start'], 'single-board match waits for the room to clear');
    }

    public function testBatchSessionsSeatSeveralStudentsTogether(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(99, self::TUE, '08:00', '08:30', 500, [2]);
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00')];
        $spellers = self::units(['s1' => [1], 's2' => [2], 's3' => [3], 's4' => [4]]);

        $result = self::planner($ledger)->place($spellers, $bands, [10 => 1], 30, 100, 3);

        $this->assertSame('08:00:00', $result['placed']['s1']['start']);
        $this->assertSame('08:00:00', $result['placed']['s3']['start']);
        $this->assertSame('08:00:00', $result['placed']['s4']['start']);
        $this->assertSame('08:35:00', $result['placed']['s2']['start'], 'busy student joins the next session after a 5 minute gap');
    }

    public function testKnockoutMatchesWaitForTheirFeeders(): void
    {
        $ledger = new TimetableLedger();
        $rows = [
            1 => ['bye' => false, 'feeders' => [], 'date' => null, 'finish' => null],
            2 => ['bye' => false, 'feeders' => [], 'date' => null, 'finish' => null],
            3 => ['bye' => false, 'feeders' => [1, 2], 'date' => null, 'finish' => null],
            4 => ['bye' => false, 'feeders' => [1, 2], 'date' => null, 'finish' => null],
        ];
        $units = [
            ['id' => 1, 'row_id' => 1, 'students' => [11, 12]],
            ['id' => 2, 'row_id' => 2, 'students' => [13, 14]],
            ['id' => 3, 'row_id' => 3, 'possible' => [11, 12, 13, 14]],
            ['id' => 4, 'row_id' => 4, 'possible' => [11, 12, 13, 14]],
        ];
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00')];

        $result = self::planner($ledger, $rows)->place($units, $bands, [10 => 4], 30, 100);

        $this->assertSame('08:00:00', $result['placed'][1]['start']);
        $this->assertSame('08:00:00', $result['placed'][2]['start']);
        $this->assertSame('08:30:00', $result['placed'][3]['start']);
        $this->assertSame('08:30:00', $result['placed'][4]['start']);
    }

    public function testKnockoutFeederOnLaterDayBlocksEarlierPlacement(): void
    {
        $ledger = new TimetableLedger();
        $rows = [
            1 => ['bye' => false, 'feeders' => [], 'date' => self::WED, 'finish' => '09:00:00'],
            2 => ['bye' => false, 'feeders' => [1], 'date' => null, 'finish' => null],
        ];
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00'), self::band(self::WED, '08:00:00', '12:00:00')];

        $result = self::planner($ledger, $rows)->place([['id' => 2, 'row_id' => 2]], $bands, [10 => 1], 30, 100);

        $this->assertSame(self::WED, $result['placed'][2]['date']);
        $this->assertSame('09:00:00', $result['placed'][2]['start']);
    }

    public function testUnscheduledFeederLeavesDependentMatchUnscheduled(): void
    {
        $ledger = new TimetableLedger();
        $rows = [
            1 => ['bye' => false, 'feeders' => [], 'date' => null, 'finish' => null],
            2 => ['bye' => false, 'feeders' => [1], 'date' => null, 'finish' => null],
        ];
        $bands = [self::band(self::TUE, '08:00:00', '08:20:00')];

        $result = self::planner($ledger, $rows)->place([['id' => 1, 'row_id' => 1], ['id' => 2, 'row_id' => 2]], $bands, [10 => 1], 30, 100);

        $this->assertSame([], $result['placed']);
        $this->assertArrayHasKey(2, $result['unscheduled']);
    }

    public function testByeFeederIsFollowedToTheRealMatch(): void
    {
        $ledger = new TimetableLedger();
        $rows = [
            1 => ['bye' => true, 'feeders' => [], 'date' => self::TUE, 'finish' => '08:00:00'],
            2 => ['bye' => false, 'feeders' => [], 'date' => self::TUE, 'finish' => '10:00:00'],
            3 => ['bye' => false, 'feeders' => [1, 2], 'date' => null, 'finish' => null],
        ];
        $planner = self::planner($ledger, $rows);

        $this->assertSame(['date' => self::TUE, 'minute' => 600], $planner->readyAt(3));
        $this->assertNull($planner->readyAt(2));
    }

    public function testByePlayerIsConfirmedAndUnknownWinnersArePossible(): void
    {
        // 3 entrants: player 5 has a bye, players 6 and 7 meet; the final has one known player.
        $rows = [
            1 => ['bye' => true, 'feeders' => [], 'players' => [5]],
            2 => ['bye' => false, 'feeders' => [], 'players' => [6, 7]],
            3 => ['bye' => false, 'feeders' => [1, 2], 'players' => []],
        ];

        $participants = SlotPlanner::knockoutParticipants($rows);

        $this->assertSame(['confirmed' => [6, 7], 'possible' => []], $participants[2]);
        $this->assertSame(['confirmed' => [5], 'possible' => [6, 7]], $participants[3]);
    }

    public function testDeepBracketMarksEveryUnknownWinnerAsPossible(): void
    {
        $rows = [
            1 => ['bye' => false, 'feeders' => [], 'players' => [1, 2]],
            2 => ['bye' => false, 'feeders' => [], 'players' => [3, 4]],
            3 => ['bye' => true, 'feeders' => [], 'players' => [5]],
            4 => ['bye' => true, 'feeders' => [], 'players' => [6]],
            5 => ['bye' => false, 'feeders' => [1, 2], 'players' => []],
            6 => ['bye' => false, 'feeders' => [3, 4], 'players' => []],
            7 => ['bye' => false, 'feeders' => [5, 6], 'players' => []],
        ];

        $participants = SlotPlanner::knockoutParticipants($rows);

        $this->assertSame(['confirmed' => [], 'possible' => [1, 2, 3, 4]], $participants[5]);
        $this->assertSame(['confirmed' => [5, 6], 'possible' => []], $participants[6]);
        $this->assertSame(['confirmed' => [], 'possible' => [1, 2, 3, 4, 5, 6]], $participants[7]);
    }

    public function testConfirmedKnockoutPlayerIsHardCheckedButPossibleOnesAreNot(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(50, self::TUE, '08:00', '09:00', 900, [5, 6]);
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00')];
        $units = [['id' => 'final', 'students' => [5], 'possible' => [6]]];

        $result = self::planner($ledger)->place($units, $bands, [10 => 1], 30, 100);

        $this->assertGreaterThanOrEqual(540, TimetableLedger::toMinutes($result['placed']['final']['start']));
    }

    public function testPinnedDayBandsAreRespected(): void
    {
        $ledger = new TimetableLedger();
        $pinnedBands = [self::band(self::WED, '14:00:00', '17:00:00')];

        $result = self::planner($ledger)->place(self::units(['a' => [1], 'b' => [2]]), $pinnedBands, [10 => 1], 30, 100);

        $this->assertSame([self::WED, self::WED], array_column($result['placed'], 'date'));
        $this->assertSame('14:00:00', $result['placed']['a']['start']);
    }

    public function testNoBandsMeansUnscheduledWithReason(): void
    {
        $result = self::planner(new TimetableLedger())->place(self::units(['a' => [1]]), [], [10 => 1], 30, 100);

        $this->assertSame([], $result['placed']);
        $this->assertSame(['no_window' => 1], $result['unscheduled']['a']['reasons']);
    }

    public function testNoRoomsMeansUnscheduledWithReason(): void
    {
        $result = self::planner(new TimetableLedger())->place(self::units(['a' => [1]]), [self::band(self::TUE, '08:00:00', '12:00:00')], [], 30, 100);

        $this->assertSame(['no_rooms' => 1], $result['unscheduled']['a']['reasons']);
    }

    public function testZeroDurationIsReportedInsteadOfBookedAsAnEmptySlot(): void
    {
        $result = self::planner(new TimetableLedger())->place(self::units(['a' => [1]]), [self::band(self::TUE, '08:00:00', '12:00:00')], [10 => 1], 0, 100);

        $this->assertSame([], $result['placed']);
        $this->assertSame(['no_duration' => 1], $result['unscheduled']['a']['reasons']);
    }

    public function testLunchAndSportsDayBlocksAreNeverOverlapped(): void
    {
        $settings = [
            'lunch_time_start' => '12:00:00', 'lunch_time_end' => '13:00:00',
            'judging_breaks_yes_no' => 1,
            'judging_breaks_morning_break_starting_time' => '10:00:00', 'judging_breaks_morning_break_finish_time' => '10:15:00',
            'judging_breaks_afternoon_break_start_time' => '15:00:00', 'judging_breaks_afternoon_break_finish_time' => '15:15:00',
            'sports_day_yes_no' => 1, 'sports_day' => 'Wednesday',
            'sports_day_starting_time' => '08:00:00', 'sports_day_finish_time' => '11:00:00',
        ];
        $blocked = SlotPlanner::blockedPeriodsFromSettings($settings, [['date' => self::TUE, 'day' => 'Tuesday'], ['date' => self::WED, 'day' => 'Wednesday']]);
        $this->assertSame([[720, 780], [600, 615], [900, 915]], $blocked[self::TUE]);
        $this->assertSame([[720, 780], [600, 615], [900, 915], [480, 660]], $blocked[self::WED]);

        $ledger = new TimetableLedger();
        $bands = [self::band(self::WED, '08:00:00', '17:00:00')];
        $units = self::units(['a' => [1], 'b' => [2], 'c' => [3], 'd' => [4]]);
        $result = self::planner($ledger, [], $blocked)->place($units, $bands, [10 => 1], 45, 100);

        foreach ($result['placed'] as $slot) {
            $s = TimetableLedger::toMinutes($slot['start']);
            $f = TimetableLedger::toMinutes($slot['finish']);
            foreach ($blocked[self::WED] as [$blockStart, $blockEnd]) {
                $this->assertFalse($s < $blockEnd && $f > $blockStart, "{$slot['start']}-{$slot['finish']} overlaps a blocked period");
            }
        }
        $this->assertSame('11:00:00', $result['placed']['a']['start']);
        $this->assertSame('11:45:00', $result['placed']['a']['finish']);
        $this->assertSame('13:00:00', $result['placed']['b']['start'], 'a 45 minute slot cannot span lunch');
    }

    public function testAfterSportWindowLimitsSportsDay(): void
    {
        $settings = [
            'sports_day_yes_no' => 1, 'sports_day' => 'Wednesday',
            'sports_day_starting_time' => '08:00:00', 'sports_day_finish_time' => '12:00:00',
            'sports_day_having_events_after_sport_yes_no' => 1,
            'sports_day_other_starting_time' => '13:00:00', 'sports_day_other_finish_time' => '15:00:00',
        ];
        $blocked = SlotPlanner::blockedPeriodsFromSettings($settings, [['date' => self::WED, 'day' => 'Wednesday']]);
        $result = self::planner(new TimetableLedger(), [], $blocked)->place(self::units(['a' => [1], 'b' => [2], 'c' => [3]]), [self::band(self::WED, '08:00:00', '17:00:00')], [10 => 1], 60, 100);

        $this->assertSame(['13:00:00', '14:00:00'], array_column($result['placed'], 'start'));
        $this->assertArrayHasKey('c', $result['unscheduled']);
    }

    public function testInsufficientCapacityIsReportedNotForced(): void
    {
        $ledger = new TimetableLedger();
        $bands = [self::band(self::TUE, '08:00:00', '09:00:00')];
        $units = self::units(['a' => [1], 'b' => [2], 'c' => [3]]);

        $result = self::planner($ledger, [], [], 0)->place($units, $bands, [10 => 1], 30, 100);

        $this->assertCount(2, $result['placed']);
        $this->assertSame(['c'], array_map('strval', array_keys($result['unscheduled'])));
        $this->assertNoConflicts(self::bookingsFrom($result['placed'], $units, 100));
    }

    public function testUnscheduledReasonNamesBusyStudents(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(50, self::TUE, '08:00', '12:00', 900, [1]);

        $result = self::planner($ledger)->place(self::units(['a' => [1, 2]]), [self::band(self::TUE, '08:00:00', '12:00:00')], [10 => 1], 30, 100);

        $this->assertArrayHasKey('student_busy', $result['unscheduled']['a']['reasons']);
        $this->assertSame([1], $result['unscheduled']['a']['students']);
    }

    public function testAvoidingOneConflictDoesNotCreateAnother(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(50, self::TUE, '08:00', '08:30', 900, [1]);   // student 1 busy first
        $ledger->book(51, self::TUE, '08:30', '09:00', 901, [2]);   // then student 2
        $ledger->book(10, self::TUE, '09:00', '09:30', 902, [7]);   // then the room is used by another event
        $units = self::units(['team' => [1, 2]]);

        $result = self::planner($ledger, [], [], 0)->place($units, [self::band(self::TUE, '08:00:00', '12:00:00')], [10 => 1], 30, 100);

        $this->assertSame('09:30:00', $result['placed']['team']['start']);
    }

    public function testFreeStudentsFillTheGapLeftByABusyOne(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(50, self::TUE, '08:00', '09:00', 900, [1]);
        $units = self::units(['busy' => [1], 'free' => [2]]);

        $result = self::planner($ledger)->place($units, [self::band(self::TUE, '08:00:00', '12:00:00')], [10 => 1], 30, 100);

        $this->assertSame('08:00:00', $result['placed']['free']['start']);
        $this->assertSame('09:05:00', $result['placed']['busy']['start'], 'busy student keeps a 5 minute gap when possible');
    }

    public function testPreferredGapIsDroppedWhenItWouldNotFit(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(50, self::TUE, '08:00', '08:30', 900, [1]);

        $result = self::planner($ledger)->place(self::units(['a' => [1]]), [self::band(self::TUE, '08:30:00', '09:00:00')], [10 => 1], 30, 100);

        $this->assertSame('08:30:00', $result['placed']['a']['start']);
    }

    public function testSlotResolverWindowsAreRespected(): void
    {
        $resolver = function (array $band, int $roomId, string $start, string $finish) {
            $s = max(TimetableLedger::toMinutes($start), TimetableLedger::toMinutes('10:00'));
            $f = $s + 30;

            return $f > TimetableLedger::toMinutes('11:00') ? null : ['start' => TimetableLedger::toTime($s), 'finish' => TimetableLedger::toTime($f)];
        };
        $planner = new SlotPlanner(new TimetableLedger(), $resolver);

        $result = $planner->place(self::units(['a' => [1], 'b' => [2], 'c' => [3]]), [self::band(self::TUE, '08:00:00', '12:00:00')], [10 => 1], 30, 100);

        $this->assertSame(['10:00:00', '10:30:00'], array_column($result['placed'], 'start'));
        $this->assertArrayHasKey('c', $result['unscheduled']);
    }
}
