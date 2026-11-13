<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Scheduling\DayStrategy;
use App\Scheduling\SlotPlanner;
use App\Scheduling\TimetableLedger;
use PHPUnit\Framework\TestCase;

class DayStrategyTest extends TestCase
{
    private const TUE = '2026-10-27';
    private const WED = '2026-10-28';
    private const THU = '2026-10-29';

    private static function band(string $date, string $start, string $end): array
    {
        return ['date' => $date, 'day' => date('l', strtotime($date)), 'start' => $start, 'end' => $end];
    }

    /** AM/PM bands (lunch 12:00-13:00) for Tue-Thu in date order. */
    private static function conventionBands(): array
    {
        $bands = [];
        foreach ([self::TUE, self::WED, self::THU] as $date) {
            $bands[] = self::band($date, '08:00:00', '12:00:00');
            $bands[] = self::band($date, '13:00:00', '17:00:00');
        }

        return $bands;
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
     * Runs a strategy the same way the controller does (SlotPlanner on a ledger copy).
     */
    private static function runStrategy(string $strategy, TimetableLedger $ledger, array $units, array $bands, array $roomLanes, int $duration, int $eventId, array $knockoutRows = [], array $blocked = []): array
    {
        $capacity = DayStrategy::capacityByDate(self::conventionBands(), $blocked, 1);
        $dayEnds = DayStrategy::dayEndByDate(self::conventionBands());
        $studentIds = array_merge(...array_map(fn ($unit) => $unit['students'] ?? [], $units));

        return DayStrategy::run(
            $strategy,
            $bands,
            $ledger,
            !$knockoutRows,
            fn (TimetableLedger $trial, array $trialBands) => (new SlotPlanner($trial, fn ($band, $room, $s, $f) => ['start' => $s, 'finish' => $f], $knockoutRows, $blocked, 5))
                ->place($units, $trialBands, $roomLanes, $duration, $eventId),
            fn (TimetableLedger $trial, array $result) => DayStrategy::cost($result['placed'], count($result['unscheduled']), $trial->busyMinutesByDate(), $capacity, $dayEnds, $trial->tightGaps($studentIds, 5), true)
        );
    }

    private static function datesUsed(array $placed): array
    {
        $dates = array_values(array_unique(array_column($placed, 'date')));
        sort($dates);

        return $dates;
    }

    public function testNormaliseFallsBackToTheOldRoundRobinCheckbox(): void
    {
        $this->assertSame(DayStrategy::BALANCED, DayStrategy::normalise('balanced', false));
        $this->assertSame(DayStrategy::EARLIEST, DayStrategy::normalise(' Earliest ', true));
        $this->assertSame(DayStrategy::ROUND_ROBIN, DayStrategy::normalise(null, true));
        $this->assertSame(DayStrategy::EARLIEST, DayStrategy::normalise(null, false));
        $this->assertSame(DayStrategy::ROUND_ROBIN, DayStrategy::normalise('two_pass', true), 'old unused values fall back to the checkbox');
        $this->assertSame(DayStrategy::EARLIEST, DayStrategy::normalise('single_pass', false));
    }

    public function testBandsFromDateWrapsOnlyWhenAllowed(): void
    {
        $bands = self::conventionBands();

        $wrapped = DayStrategy::bandsFromDate($bands, self::WED, true);
        $this->assertSame([self::WED, self::WED, self::THU, self::THU, self::TUE, self::TUE], array_column($wrapped, 'date'));
        $this->assertSame('08:00:00', $wrapped[0]['start'], 'bands stay in time order inside a day');

        $noWrap = DayStrategy::bandsFromDate($bands, self::WED, false);
        $this->assertSame([self::WED, self::WED, self::THU, self::THU], array_column($noWrap, 'date'));
    }

    public function testCandidateStartDatesPerStrategy(): void
    {
        $dates = [self::TUE, self::WED, self::THU];

        $this->assertSame([self::TUE], DayStrategy::candidateStartDates(DayStrategy::EARLIEST, $dates, 5));
        $this->assertSame([self::TUE, self::WED, self::THU], DayStrategy::candidateStartDates(DayStrategy::ROUND_ROBIN, $dates, 0));
        $this->assertSame([self::WED, self::THU, self::TUE], DayStrategy::candidateStartDates(DayStrategy::ROUND_ROBIN, $dates, 1));
        $this->assertSame([self::THU, self::TUE, self::WED], DayStrategy::candidateStartDates(DayStrategy::ROUND_ROBIN, $dates, 5));
        $this->assertSame($dates, DayStrategy::candidateStartDates(DayStrategy::BALANCED, $dates, 1));
        $this->assertSame([], DayStrategy::candidateStartDates(DayStrategy::BALANCED, [], 0));
    }

    public function testCapacitySubtractsBlockedPeriods(): void
    {
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00'), self::band(self::TUE, '13:00:00', '17:00:00'), self::band(self::WED, '08:00:00', '12:00:00')];
        $blocked = [self::TUE => [[600, 630]]];

        $this->assertSame([self::TUE => (480 - 30) * 3, self::WED => 240 * 3], DayStrategy::capacityByDate($bands, $blocked, 3));
    }

    public function testBusyMinutesCountParallelLanesOnceAndEventCount(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(1, self::TUE, '09:00', '10:00', 10, [1]);
        $ledger->book(1, self::TUE, '09:30', '10:30', 10, [2]);
        $ledger->book(2, self::TUE, '09:00', '09:15', 11, [3]);
        $ledger->book(1, self::WED, '13:00', '13:20', 12, [4]);

        $this->assertSame([self::TUE => 90 + 15, self::WED => 20], $ledger->busyMinutesByDate());
        $this->assertSame(3, $ledger->eventCount());
    }

    public function testTightGapsCountsBookingsUnderTheGap(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(1, self::TUE, '09:00', '09:30', 10, [7]);
        $ledger->book(2, self::TUE, '09:30', '10:00', 11, [7]);
        $ledger->book(3, self::TUE, '10:03', '10:30', 12, [7]);
        $ledger->book(3, self::TUE, '11:00', '11:30', 13, [7, 8]);

        $this->assertSame(2, $ledger->tightGaps([7], 5));
        $this->assertSame(0, $ledger->tightGaps([8], 5));
    }

    public function testCostPrefersFewerUnscheduledOverBalance(): void
    {
        $capacity = [self::TUE => 480, self::WED => 480];
        $ends = [self::TUE => 1020, self::WED => 1020];
        $balancedButMissing = DayStrategy::cost([], 1, [self::TUE => 100, self::WED => 100], $capacity, $ends, 0, false);
        $unevenButComplete = DayStrategy::cost([], 0, [self::TUE => 400, self::WED => 0], $capacity, $ends, 0, false);

        $this->assertSame(0.0, $balancedButMissing['imbalance']);
        $this->assertSame(400.0, $unevenButComplete['imbalance']);
        $this->assertTrue(DayStrategy::better($unevenButComplete, $balancedButMissing));
        $this->assertFalse(DayStrategy::better($balancedButMissing, $unevenButComplete));
    }

    public function testCostPenalisesSpanLateFinishesAndTightGaps(): void
    {
        $capacity = [self::TUE => 480, self::WED => 480];
        $ends = [self::TUE => 1020, self::WED => 1020];
        $twoDays = ['a' => ['date' => self::TUE, 'start' => '09:00:00', 'finish' => '09:30:00'], 'b' => ['date' => self::WED, 'start' => '09:00:00', 'finish' => '09:30:00']];
        $late = ['a' => ['date' => self::TUE, 'start' => '16:30:00', 'finish' => '17:00:00'], 'b' => ['date' => self::TUE, 'start' => '16:30:00', 'finish' => '17:00:00']];

        $spread = DayStrategy::cost($twoDays, 0, [], $capacity, $ends, 0, true);
        $this->assertSame(1, $spread['span']);
        $this->assertSame((float)DayStrategy::SPAN_COST_COMPACT, $spread['cost']);
        $this->assertSame((float)DayStrategy::SPAN_COST, DayStrategy::cost($twoDays, 0, [], $capacity, $ends, 0, false)['cost']);

        $lateCost = DayStrategy::cost($late, 0, [], $capacity, $ends, 0, true);
        $this->assertSame(30, $lateCost['late'], 'one shared session counts once');
        $this->assertSame(30.0 * DayStrategy::LATE_COST_PER_MINUTE, $lateCost['cost']);

        $this->assertSame(2.0 * DayStrategy::TIGHT_GAP_COST, DayStrategy::cost([], 0, [], $capacity, $ends, 2, true)['cost']);
    }

    public function testEarliestFillsTheFirstDay(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(1, self::TUE, '08:00', '12:00', 99, [500]);

        $run = self::runStrategy(DayStrategy::EARLIEST, $ledger, self::units(['a' => [1], 'b' => [2]]), self::conventionBands(), [2 => 1], 30, 10);

        $this->assertSame([self::TUE], self::datesUsed($run['result']['placed']));
        $this->assertSame(1, $run['trials']);
    }

    public function testBalancedMovesAnEventToTheLighterDay(): void
    {
        $ledger = new TimetableLedger();
        // Tuesday already has a full day in two rooms.
        $ledger->book(1, self::TUE, '08:00', '12:00', 98, [500]);
        $ledger->book(1, self::TUE, '13:00', '17:00', 98, [501]);
        $ledger->book(2, self::TUE, '08:00', '12:00', 99, [502]);
        $ledger->book(2, self::TUE, '13:00', '17:00', 99, [503]);
        $before = $ledger->busyMinutesByDate();

        $run = self::runStrategy(DayStrategy::BALANCED, $ledger, self::units(['a' => [1], 'b' => [2], 'c' => [3]]), self::conventionBands(), [3 => 1], 40, 10);

        $this->assertSame([], $run['result']['unscheduled']);
        $this->assertCount(1, self::datesUsed($run['result']['placed']), 'the event stays on one day');
        $this->assertNotSame(self::TUE, self::datesUsed($run['result']['placed'])[0]);
        $this->assertSame($before, $ledger->busyMinutesByDate(), 'the original ledger is not modified');
        $this->assertSame(120, array_sum($run['ledger']->busyMinutesByDate()) - array_sum($before));
    }

    public function testBalancedNeverLeavesMoreUnscheduledThanEarliest(): void
    {
        // Only Tuesday has room for all three 4-hour sessions.
        $bands = [self::band(self::TUE, '08:00:00', '12:00:00'), self::band(self::TUE, '13:00:00', '17:00:00'), self::band(self::WED, '08:00:00', '12:00:00')];
        $ledger = new TimetableLedger();
        $ledger->book(9, self::TUE, '08:00', '17:00', 98, [500]);

        $earliest = self::runStrategy(DayStrategy::EARLIEST, $ledger, self::units(['a' => [1], 'b' => [2], 'c' => [3]]), $bands, [1 => 1], 240, 10);
        $balanced = self::runStrategy(DayStrategy::BALANCED, $ledger, self::units(['a' => [1], 'b' => [2], 'c' => [3]]), $bands, [1 => 1], 240, 10);

        $this->assertCount(0, $earliest['result']['unscheduled']);
        $this->assertCount(0, $balanced['result']['unscheduled']);
    }

    public function testRoundRobinRotatesTheStartDayAndFallsBackWhenFull(): void
    {
        $ledger = new TimetableLedger();
        $ledger->book(1, self::TUE, '08:00', '08:30', 1, [900]);

        // One event is already placed, so the next one prefers Wednesday.
        $run = self::runStrategy(DayStrategy::ROUND_ROBIN, $ledger, self::units(['a' => [1]]), self::conventionBands(), [2 => 1], 30, 10);
        $this->assertSame([self::WED], self::datesUsed($run['result']['placed']));
        $this->assertSame(1, $run['trials']);

        // Wednesday and Thursday are full for room 2, so it falls back to Tuesday.
        $full = clone $ledger;
        foreach ([self::WED, self::THU] as $date) {
            $full->book(2, $date, '08:00', '12:00', 50, [800]);
            $full->book(2, $date, '13:00', '17:00', 50, [801]);
        }
        // Two events placed: the preferred day is Thursday (full), then Tuesday.
        $this->assertSame([self::THU, self::TUE, self::WED], DayStrategy::candidateStartDates(DayStrategy::ROUND_ROBIN, [self::TUE, self::WED, self::THU], $full->eventCount()));
        $fallback = self::runStrategy(DayStrategy::ROUND_ROBIN, $full, self::units(['a' => [1]]), self::conventionBands(), [2 => 1], 30, 10);
        $this->assertSame([self::TUE], self::datesUsed($fallback['result']['placed']));
        $this->assertSame([], $fallback['result']['unscheduled']);
    }

    public function testKnockoutsNeverWrapToAnEarlierDay(): void
    {
        $ledger = new TimetableLedger();
        $rows = [
            1 => ['bye' => false, 'feeders' => [], 'date' => null, 'finish' => null],
            2 => ['bye' => false, 'feeders' => [], 'date' => null, 'finish' => null],
            3 => ['bye' => false, 'feeders' => [1, 2], 'date' => null, 'finish' => null],
        ];
        $units = [
            ['id' => 'r1', 'row_id' => 1, 'students' => [1, 2]],
            ['id' => 'r2', 'row_id' => 2, 'students' => [3, 4]],
            ['id' => 'r3', 'row_id' => 3, 'students' => [], 'possible' => [1, 2, 3, 4]],
        ];
        // Tuesday is heavily loaded, so balanced prefers a later start for the whole bracket.
        foreach ([2, 3, 4] as $room) {
            $ledger->book($room, self::TUE, '08:00', '12:00', 90 + $room, [700 + $room]);
            $ledger->book($room, self::TUE, '13:00', '17:00', 90 + $room, [710 + $room]);
        }

        foreach ([DayStrategy::EARLIEST, DayStrategy::ROUND_ROBIN, DayStrategy::BALANCED] as $strategy) {
            $run = self::runStrategy($strategy, $ledger, $units, self::conventionBands(), [1 => 2], 60, 10, $rows);
            $placed = $run['result']['placed'];
            $this->assertSame([], $run['result']['unscheduled'], $strategy);
            $finalStart = $placed['r3']['date'].' '.$placed['r3']['start'];
            foreach (['r1', 'r2'] as $feeder) {
                $this->assertGreaterThanOrEqual($placed[$feeder]['date'].' '.$placed[$feeder]['finish'], $finalStart, "$strategy: final starts after $feeder finishes");
            }
        }
    }

    public function testStrategiesNeverDoubleBookStudentsOrRooms(): void
    {
        foreach ([DayStrategy::EARLIEST, DayStrategy::ROUND_ROBIN, DayStrategy::BALANCED] as $strategy) {
            $ledger = new TimetableLedger();
            $bookings = [];
            // Three events share students 1-4 and room 1; each run builds on the previous ledger.
            foreach ([10 => ['a' => [1], 'b' => [2]], 11 => ['a' => [1, 3], 'b' => [2, 4]], 12 => ['a' => [3], 'b' => [4]]] as $eventId => $students) {
                $run = self::runStrategy($strategy, $ledger, self::units($students), self::conventionBands(), [1 => 1], 45, $eventId);
                $this->assertSame([], $run['result']['unscheduled'], "$strategy event $eventId");
                foreach ($run['result']['placed'] as $key => $slot) {
                    $bookings[] = $slot + ['event' => $eventId, 'students' => $students[$key]];
                }
                $ledger = $run['ledger'];
            }
            foreach ($bookings as $i => $x) {
                foreach ($bookings as $j => $y) {
                    if ($j <= $i || $x['date'] !== $y['date'] || $x['finish'] <= $y['start'] || $y['finish'] <= $x['start']) {
                        continue;
                    }
                    $this->assertSame([], array_values(array_intersect($x['students'], $y['students'])), "$strategy student overlap");
                    $this->assertNotSame($x['room_id'], $y['room_id'], "$strategy room overlap");
                }
            }
        }
    }

    public function testBlockedPeriodsAreRespectedByEveryStrategy(): void
    {
        $blocked = [self::TUE => [[600, 660]], self::WED => [[600, 660]], self::THU => [[600, 660]]];
        foreach ([DayStrategy::EARLIEST, DayStrategy::ROUND_ROBIN, DayStrategy::BALANCED] as $strategy) {
            $run = self::runStrategy($strategy, new TimetableLedger(), self::units(['a' => [1], 'b' => [2], 'c' => [3], 'd' => [4]]), self::conventionBands(), [1 => 1], 60, 10, [], $blocked);
            foreach ($run['result']['placed'] as $slot) {
                $s = TimetableLedger::toMinutes($slot['start']);
                $f = TimetableLedger::toMinutes($slot['finish']);
                $this->assertTrue($f <= 600 || $s >= 660, "$strategy booking {$slot['start']}-{$slot['finish']} avoids the 10:00-11:00 block");
            }
        }
    }
}
