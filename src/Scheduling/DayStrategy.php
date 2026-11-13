<?php
declare(strict_types=1);

namespace App\Scheduling;

/**
 * Chooses the day order in which one event's time bands are offered to SlotPlanner.
 *
 * - earliest:    bands in date order; every event fills the earliest valid slots.
 * - round_robin: successive events start on successive convention days, then continue
 *                in date order.
 * - balanced:    every allowed start day is tried on a copy of the ledger and the trial
 *                with the best cost() is kept.
 *
 * Strategies only reorder bands. Every hard rule (students, rooms, lanes, knockout
 * order, breaks, hours, pinned settings) is still enforced by SlotPlanner.
 */
final class DayStrategy
{
    public const EARLIEST = 'earliest';
    public const ROUND_ROBIN = 'round_robin';
    public const BALANCED = 'balanced';

    public const LABELS = [
        self::EARLIEST => 'Earliest Available',
        self::BALANCED => 'Balanced Days',
        self::ROUND_ROBIN => 'Round-Robin Days',
    ];

    /** Cost of each extra day an event spans (compact events: knockouts, consecutive groups). */
    public const SPAN_COST_COMPACT = 240;
    public const SPAN_COST = 60;
    /** Bookings finishing inside the last hour of the day count as late. */
    public const LATE_WINDOW_MINUTES = 60;
    public const LATE_COST_PER_MINUTE = 2;
    public const TIGHT_GAP_COST = 15;

    /**
     * Strategy from the wizard; NULL/unknown falls back to the old round-robin checkbox.
     */
    public static function normalise(?string $mode, bool $roundRobinFlag): string
    {
        $mode = strtolower(trim((string)$mode));
        if (isset(self::LABELS[$mode])) {
            return $mode;
        }

        return $roundRobinFlag ? self::ROUND_ROBIN : self::EARLIEST;
    }

    /**
     * @param list<array{date:string}> $bands
     * @return list<string> distinct dates in band order
     */
    public static function dates(array $bands): array
    {
        $dates = [];
        foreach ($bands as $band) {
            $dates[$band['date']] = $band['date'];
        }

        return array_values($dates);
    }

    /**
     * Bands from $startDate onwards in their original (date) order. With $wrap, bands on
     * earlier dates are appended afterwards as overflow; knockouts never wrap because a
     * later round may not be placed on an earlier day.
     *
     * @param list<array{date:string}> $bands
     */
    public static function bandsFromDate(array $bands, string $startDate, bool $wrap): array
    {
        $from = [];
        $before = [];
        foreach ($bands as $band) {
            if ($band['date'] >= $startDate) {
                $from[] = $band;
            } else {
                $before[] = $band;
            }
        }

        return $wrap ? array_merge($from, $before) : $from;
    }

    /**
     * Start dates in the order a strategy prefers them.
     *
     * @param list<string> $dates allowed dates for the event, ascending
     * @param int $eventIndex number of events already placed (round robin rotation)
     * @return list<string>
     */
    public static function candidateStartDates(string $strategy, array $dates, int $eventIndex): array
    {
        if (!$dates || $strategy === self::EARLIEST) {
            return array_slice($dates, 0, 1);
        }
        if ($strategy === self::ROUND_ROBIN) {
            $offset = max(0, $eventIndex) % count($dates);

            return array_merge(array_slice($dates, $offset), array_slice($dates, 0, $offset));
        }

        return $dates;
    }

    /**
     * Available room-minutes per date: band minutes minus blocked periods, times rooms.
     *
     * @param list<array{date:string,start:string,end:string}> $bands
     * @param array<string, list<array{0:int,1:int}>> $blockedPeriods
     * @return array<string, int>
     */
    public static function capacityByDate(array $bands, array $blockedPeriods, int $roomCount): array
    {
        $capacity = [];
        foreach ($bands as $band) {
            $s = TimetableLedger::toMinutes($band['start']);
            $f = TimetableLedger::toMinutes($band['end']);
            $minutes = max(0, $f - $s);
            foreach ($blockedPeriods[$band['date']] ?? [] as [$bs, $bf]) {
                $minutes -= max(0, min($f, $bf) - max($s, $bs));
            }
            $capacity[$band['date']] = ($capacity[$band['date']] ?? 0) + max(0, $minutes) * max(1, $roomCount);
        }
        ksort($capacity);

        return $capacity;
    }

    /**
     * Last band end per date.
     *
     * @param list<array{date:string,end:string}> $bands
     * @return array<string, int>
     */
    public static function dayEndByDate(array $bands): array
    {
        $ends = [];
        foreach ($bands as $band) {
            $ends[$band['date']] = max($ends[$band['date']] ?? 0, TimetableLedger::toMinutes($band['end']));
        }

        return $ends;
    }

    /**
     * Cost of one trial placement (lower is better). Unscheduled bookings are compared
     * first by better(), so balancing can never leave more bookings unscheduled.
     *
     * - imbalance: room-minutes away from an even share of the season's workload,
     *   where each day's share is proportional to its available capacity;
     * - span: extra days the event uses;
     * - late: minutes the event's sessions run inside the last hour of the day;
     * - tight: back-to-back bookings of the event's students.
     *
     * @param array<string, array{date:string,start:string,finish:string}> $placed
     * @param array<string, int> $busyByDate
     * @param array<string, int> $capacityByDate
     * @param array<string, int> $dayEndByDate
     * @return array{unscheduled:int, cost:float, imbalance:float, span:int, late:int, tight:int}
     */
    public static function cost(array $placed, int $unscheduled, array $busyByDate, array $capacityByDate, array $dayEndByDate, int $tightGaps, bool $compact): array
    {
        $totalBusy = array_sum($busyByDate);
        $totalCapacity = array_sum($capacityByDate);
        $imbalance = 0.0;
        if ($totalCapacity > 0) {
            foreach ($capacityByDate as $date => $capacity) {
                $imbalance += abs(($busyByDate[$date] ?? 0) - $capacity * $totalBusy / $totalCapacity);
            }
        }

        $dates = [];
        $late = 0;
        $sessions = [];
        foreach ($placed as $slot) {
            $dates[$slot['date']] = true;
            $sessions[$slot['date'].'|'.$slot['start'].'|'.$slot['finish']] = $slot;
        }
        foreach ($sessions as $slot) {
            $dayEnd = $dayEndByDate[$slot['date']] ?? null;
            if ($dayEnd !== null) {
                $late += max(0, TimetableLedger::toMinutes($slot['finish']) - max(TimetableLedger::toMinutes($slot['start']), $dayEnd - self::LATE_WINDOW_MINUTES));
            }
        }
        $span = max(0, count($dates) - 1);

        return [
            'unscheduled' => $unscheduled,
            'cost' => round($imbalance + $span * ($compact ? self::SPAN_COST_COMPACT : self::SPAN_COST) + $late * self::LATE_COST_PER_MINUTE + $tightGaps * self::TIGHT_GAP_COST, 2),
            'imbalance' => round($imbalance, 2),
            'span' => $span,
            'late' => $late,
            'tight' => $tightGaps,
        ];
    }

    /**
     * Runs the strategy for one event. $place(TimetableLedger $ledger, array $bands): array
     * must place the event on the given ledger (SlotPlanner::place result); $score(
     * TimetableLedger $trial, array $result): array returns cost(). The original ledger is
     * never modified: the chosen trial's ledger is returned instead.
     *
     * @return array{result:array, ledger:TimetableLedger, start_date:?string, trials:int}
     */
    public static function run(string $strategy, array $bands, TimetableLedger $ledger, bool $wrap, callable $place, callable $score): array
    {
        $candidates = self::candidateStartDates($strategy, self::dates($bands), $ledger->eventCount());
        if (count($candidates) <= 1) {
            $trial = clone $ledger;

            return ['result' => $place($trial, $bands), 'ledger' => $trial, 'start_date' => $candidates[0] ?? null, 'trials' => 1];
        }

        $best = null;
        $trials = 0;
        foreach ($candidates as $startDate) {
            $trial = clone $ledger;
            $result = $place($trial, self::bandsFromDate($bands, $startDate, $wrap));
            $trials++;
            $cost = $score($trial, $result);
            if ($best === null || self::better($cost, $best['cost'])) {
                $best = ['cost' => $cost, 'result' => $result, 'ledger' => $trial, 'start_date' => $startDate];
            }
            // Round robin keeps its preferred day unless that day leaves bookings unscheduled.
            if ($strategy === self::ROUND_ROBIN && $best['cost']['unscheduled'] === 0) {
                break;
            }
        }

        return ['result' => $best['result'], 'ledger' => $best['ledger'], 'start_date' => $best['start_date'], 'trials' => $trials];
    }

    /**
     * True when trial $a should replace the current best $b: fewer unscheduled bookings
     * first, then a clearly lower cost (ties keep the earlier-preferred trial).
     *
     * @param array{unscheduled:int, cost:float} $a
     * @param array{unscheduled:int, cost:float} $b
     */
    public static function better(array $a, array $b): bool
    {
        if ($a['unscheduled'] !== $b['unscheduled']) {
            return $a['unscheduled'] < $b['unscheduled'];
        }

        return $a['cost'] < $b['cost'] - 0.5;
    }
}
