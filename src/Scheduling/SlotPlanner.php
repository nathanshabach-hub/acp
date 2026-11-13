<?php
declare(strict_types=1);

namespace App\Scheduling;

/**
 * Greedy, constraint-checked placement of one event's bookings into the
 * convention's time bands.
 *
 * Hard rules (never relaxed): the slot comes from the slot resolver (convention
 * dates, daily hours, event/room windows), does not overlap a blocked period,
 * the room is free for this event (lanes respected), no confirmed participant
 * is already busy, and a knockout match never starts before its feeder
 * matches have finished. Anything that cannot meet those rules is returned as
 * unscheduled with the reasons that blocked it.
 *
 * Soft preferences: a gap of $preferredGap minutes between a student's
 * bookings, and no overlap with possible (not yet known) knockout winners.
 */
final class SlotPlanner
{
    public const REASON_TEXT = [
        'no_rooms' => 'no room is mapped to this event',
        'no_duration' => 'the event duration (setup + round + judging) is 0 minutes',
        'no_window' => 'no time band is left for this event (convention days, pinned day or pinned start time)',
        'room_other_event' => 'the room is occupied by another event',
        'room_full' => 'every lane in the room is already in use',
        'student_busy' => 'a participant is already competing at that time',
        'feeder_unscheduled' => 'an earlier-round match could not be scheduled',
        'feeder_running' => 'an earlier-round match has not finished yet',
        'feeder_later_day' => 'an earlier-round match is on a later day',
    ];

    /** @var callable(array, int, string, string): ?array */
    private $slotResolver;

    /**
     * @param callable(array $band, int $roomId, string $start, string $finish): ?array $slotResolver
     *   Returns ['start' => 'H:i:s', 'finish' => 'H:i:s'] inside the band, or null when
     *   the room has no valid slot left in that band.
     * @param array<int, array{bye:bool, feeders:list<int>, date:?string, finish:?string}> $knockoutRows
     * @param array<string, list<array{0:int,1:int}>> $blockedPeriods date => [[startMinute, endMinute], ...]
     */
    public function __construct(
        private TimetableLedger $ledger,
        callable $slotResolver,
        private array $knockoutRows = [],
        private array $blockedPeriods = [],
        private int $preferredGap = 5,
        private int $stepMinutes = 5,
    ) {
        $this->slotResolver = $slotResolver;
    }

    /**
     * @param list<array{id:string|int, students?:list<int>, possible?:list<int>, row_id?:?int, capacity?:?int}> $units
     * @param list<array{date:string, day:string, start:string, end:string}> $bands
     * @param array<int, int> $roomLanes room id => lanes (in preferred order)
     * @return array{placed: array<string|int, array{room_id:int,date:string,day:string,start:string,finish:string}>,
     *   unscheduled: array<string|int, array{reasons:array<string,int>, students:list<int>}>}
     */
    public function place(array $units, array $bands, array $roomLanes, int $duration, int $eventId, int $batchSize = 1): array
    {
        $pending = [];
        foreach ($units as $unit) {
            $pending[(string)$unit['id']] = $unit;
        }
        $reasons = [];
        $blockingStudents = [];
        $placed = [];
        $batchSize = max(1, $batchSize);
        $roomLanes = array_filter($roomLanes, fn ($lanes) => (int)$lanes > 0);

        if ($pending && $duration <= 0) {
            return $this->unscheduledResult($pending, $placed, 'no_duration');
        }
        if ($pending && !$roomLanes) {
            return $this->unscheduledResult($pending, $placed, 'no_rooms');
        }
        if ($pending && !$bands) {
            return $this->unscheduledResult($pending, $placed, 'no_window');
        }

        $guard = 0;
        foreach ($bands as $band) {
            if (!$pending) {
                break;
            }
            $bandStart = TimetableLedger::toMinutes($band['start']);
            $bandEnd = TimetableLedger::toMinutes($band['end']);
            $lanes = [];
            foreach ($roomLanes as $roomId => $laneCount) {
                for ($lane = 0; $lane < (int)$laneCount; $lane++) {
                    $lanes[] = ['room' => (int)$roomId, 'cursor' => $bandStart];
                }
            }

            while ($pending && $lanes) {
                if (++$guard > 500000) {
                    break 2;
                }
                $laneIndex = self::earliestLane($lanes);
                $roomId = $lanes[$laneIndex]['room'];
                $cursor = $lanes[$laneIndex]['cursor'];

                $slot = $this->resolveSlot($band, $roomId, $cursor, $duration, $bandStart, $bandEnd);
                if ($slot === null) {
                    array_splice($lanes, $laneIndex, 1);
                    continue;
                }
                [$s, $f] = $slot;

                $chosen = [];
                $chosenStudents = [];
                $softChosen = [];
                $hints = [];
                $roomBlockByCapacity = [];
                foreach ($pending as $key => $unit) {
                    $students = $unit['students'] ?? [];
                    $rowId = (int)($unit['row_id'] ?? 0);
                    if ($rowId > 0 && $this->knockoutRows) {
                        $ready = $this->readyAt($rowId);
                        if ($ready === false) {
                            $reasons[$key]['feeder_unscheduled'] = ($reasons[$key]['feeder_unscheduled'] ?? 0) + 1;
                            continue;
                        }
                        if ($ready !== null) {
                            if ($ready['date'] > $band['date']) {
                                $reasons[$key]['feeder_later_day'] = ($reasons[$key]['feeder_later_day'] ?? 0) + 1;
                                continue;
                            }
                            if ($ready['date'] === $band['date'] && $ready['minute'] > $s) {
                                $reasons[$key]['feeder_running'] = ($reasons[$key]['feeder_running'] ?? 0) + 1;
                                $hints[] = $ready['minute'];
                                continue;
                            }
                        }
                    }

                    $capacity = (int)($unit['capacity'] ?? 0) ?: (int)$roomLanes[$roomId];
                    if (!array_key_exists($capacity, $roomBlockByCapacity)) {
                        $roomBlockByCapacity[$capacity] = $this->ledger->roomBlock($roomId, $band['date'], $s, $f, $eventId, $capacity);
                    }
                    $roomBlock = $roomBlockByCapacity[$capacity];
                    if ($roomBlock !== null) {
                        $reasons[$key][$roomBlock['reason']] = ($reasons[$key][$roomBlock['reason']] ?? 0) + 1;
                        $hints[] = $roomBlock['next'];
                        continue;
                    }

                    $busy = $this->ledger->studentBlocks($students, $band['date'], $s, $f);
                    if (!$busy && $chosenStudents && array_intersect($students, $chosenStudents)) {
                        $busy = array_fill_keys(array_values(array_intersect($students, $chosenStudents)), $f);
                    }
                    if ($busy) {
                        $reasons[$key]['student_busy'] = ($reasons[$key]['student_busy'] ?? 0) + 1;
                        foreach (array_keys($busy) as $studentId) {
                            $blockingStudents[$key][$studentId] = $studentId;
                        }
                        $hints[] = min($busy);
                        continue;
                    }

                    $penalty = $this->ledger->softPenalty($students, $unit['possible'] ?? [], $band['date'], $s, $f, $this->preferredGap, $eventId);
                    if ($penalty === 0) {
                        $chosen[] = $key;
                        $chosenStudents = array_merge($chosenStudents, $students);
                        if (count($chosen) >= $batchSize) {
                            break;
                        }
                    } else {
                        $softChosen[] = ['key' => $key, 'penalty' => $penalty, 'students' => $students];
                    }
                }

                // Only back-to-back candidates fit: wait one preferred gap once before accepting them.
                if (!$chosen && $softChosen && empty($lanes[$laneIndex]['deferred']) && $s + $this->preferredGap + $duration <= $bandEnd && $this->preferredGap > 0) {
                    $lanes[$laneIndex]['deferred'] = true;
                    $lanes[$laneIndex]['cursor'] = $s + $this->preferredGap;
                    continue;
                }

                if (count($chosen) < $batchSize && $softChosen) {
                    usort($softChosen, fn ($a, $b) => $a['penalty'] <=> $b['penalty']);
                    foreach ($softChosen as $candidate) {
                        if (count($chosen) >= $batchSize) {
                            break;
                        }
                        if (array_intersect($candidate['students'], $chosenStudents)) {
                            continue;
                        }
                        $chosen[] = $candidate['key'];
                        $chosenStudents = array_merge($chosenStudents, $candidate['students']);
                    }
                }

                if (!$chosen) {
                    $next = $hints ? min(array_filter($hints, fn ($minute) => $minute > $s) ?: [$s + $this->stepMinutes]) : $s + $this->stepMinutes;
                    $lanes[$laneIndex]['cursor'] = max($next, $cursor + 1);
                    if ($lanes[$laneIndex]['cursor'] >= $bandEnd) {
                        array_splice($lanes, $laneIndex, 1);
                    }
                    continue;
                }

                $students = [];
                $possible = [];
                $sessionCapacity = 0;
                foreach ($chosen as $key) {
                    $students = array_merge($students, $pending[$key]['students'] ?? []);
                    $possible = array_merge($possible, $pending[$key]['possible'] ?? []);
                    $unitCapacity = (int)($pending[$key]['capacity'] ?? 0);
                    if ($unitCapacity > 0) {
                        $sessionCapacity = $sessionCapacity > 0 ? min($sessionCapacity, $unitCapacity) : $unitCapacity;
                    }
                }
                $this->ledger->book($roomId, $band['date'], $s, $f, $eventId, $students, $possible, null, $sessionCapacity);
                foreach ($chosen as $key) {
                    $placed[$key] = [
                        'room_id' => $roomId,
                        'date' => $band['date'],
                        'day' => $band['day'],
                        'start' => TimetableLedger::toTime($s),
                        'finish' => TimetableLedger::toTime($f),
                    ];
                    $rowId = (int)($pending[$key]['row_id'] ?? 0);
                    if ($rowId > 0 && isset($this->knockoutRows[$rowId])) {
                        $this->knockoutRows[$rowId]['date'] = $band['date'];
                        $this->knockoutRows[$rowId]['finish'] = TimetableLedger::toTime($f);
                    }
                    unset($pending[$key], $reasons[$key], $blockingStudents[$key]);
                }

                $lanes[$laneIndex]['cursor'] = $f;
                $lanes[$laneIndex]['deferred'] = false;
                if ($f >= $bandEnd) {
                    array_splice($lanes, $laneIndex, 1);
                }
            }
        }

        $unscheduled = [];
        foreach ($pending as $key => $unit) {
            $unitReasons = $reasons[$key] ?? ['no_window' => 1];
            arsort($unitReasons);
            $unscheduled[$key] = [
                'reasons' => $unitReasons,
                'students' => array_values($blockingStudents[$key] ?? []),
            ];
        }

        return ['placed' => $placed, 'unscheduled' => $unscheduled];
    }

    /**
     * Knockout feeder readiness, following bye feeders back to the match that feeds them.
     *
     * @return array{date:string, minute:int}|null|false null = no dependency, false = a feeder is unscheduled
     */
    public function readyAt(int $rowId, int $depth = 0)
    {
        $row = $this->knockoutRows[$rowId] ?? null;
        if ($row === null || $depth > 32) {
            return null;
        }

        $ready = null;
        foreach ($row['feeders'] as $feederId) {
            $feeder = $this->knockoutRows[(int)$feederId] ?? null;
            if ($feeder === null) {
                continue;
            }
            if ($feeder['bye']) {
                $candidate = $this->readyAt((int)$feederId, $depth + 1);
            } elseif (empty($feeder['date']) || empty($feeder['finish'])) {
                return false;
            } else {
                $candidate = ['date' => (string)$feeder['date'], 'minute' => TimetableLedger::toMinutes((string)$feeder['finish'])];
            }
            if ($candidate === false) {
                return false;
            }
            if ($candidate !== null && ($ready === null || [$candidate['date'], $candidate['minute']] > [$ready['date'], $ready['minute']])) {
                $ready = $candidate;
            }
        }

        return $ready;
    }

    /**
     * Confirmed and possible participants of every knockout row.
     * A bye advances its player for certain; a real match only says one of its
     * players will advance, so later rounds treat them as possible participants.
     *
     * @param array<int, array{bye:bool, feeders:list<int>, players:list<int>}> $rows
     * @return array<int, array{confirmed:list<int>, possible:list<int>}>
     */
    public static function knockoutParticipants(array $rows): array
    {
        $advancing = [];
        $participants = [];
        $resolve = function (int $id, int $depth) use (&$resolve, &$advancing, &$participants, $rows): array {
            if (isset($participants[$id])) {
                return $participants[$id];
            }
            $row = $rows[$id] ?? null;
            if ($row === null || $depth > 32) {
                return $participants[$id] = ['confirmed' => [], 'possible' => []];
            }
            $feeders = array_values(array_filter(array_map('intval', $row['feeders'] ?? []), fn ($feederId) => $feederId > 0));
            if (!$feeders) {
                $own = ['confirmed' => self::uniqueIds($row['players'] ?? []), 'possible' => []];
            } else {
                $own = ['confirmed' => [], 'possible' => []];
                foreach ($feeders as $feederId) {
                    $resolve($feederId, $depth + 1);
                    $own['confirmed'] = array_merge($own['confirmed'], $advancing[$feederId]['confirmed']);
                    $own['possible'] = array_merge($own['possible'], $advancing[$feederId]['possible']);
                }
                $own['confirmed'] = self::uniqueIds($own['confirmed']);
                $own['possible'] = array_values(array_diff(self::uniqueIds($own['possible']), $own['confirmed']));
            }
            $participants[$id] = $own;
            $advancing[$id] = $row['bye']
                ? $own
                : ['confirmed' => [], 'possible' => self::uniqueIds(array_merge($own['confirmed'], $own['possible']))];

            return $own;
        };
        foreach (array_keys($rows) as $id) {
            $resolve((int)$id, 0);
        }

        return $participants;
    }

    /**
     * Lunch, judging breaks and sports-day blocks for each convention date.
     *
     * @param array<string, mixed> $settings wizard values (H:i:s strings, yes/no flags as 0/1)
     * @param list<array{date:string, day:string}> $dates
     * @return array<string, list<array{0:int,1:int}>>
     */
    public static function blockedPeriodsFromSettings(array $settings, array $dates): array
    {
        $blocked = [];
        foreach (self::labelledBlockedPeriods($settings, $dates) as $date => $blocks) {
            $blocked[$date] = array_map(fn ($block) => [$block[0], $block[1]], $blocks);
        }

        return $blocked;
    }

    /**
     * Same periods as blockedPeriodsFromSettings() with a label for reports
     * ('lunch', 'judging break', 'sports', 'outside after-sport window').
     *
     * @return array<string, list<array{0:int,1:int,2:string}>>
     */
    public static function labelledBlockedPeriods(array $settings, array $dates): array
    {
        $range = function ($start, $end): ?array {
            if (empty($start) || empty($end)) {
                return null;
            }
            $s = TimetableLedger::toMinutes((string)$start);
            $f = TimetableLedger::toMinutes((string)$end);

            return $f > $s ? [$s, $f] : null;
        };

        $daily = [];
        if ($lunch = $range($settings['lunch_time_start'] ?? null, $settings['lunch_time_end'] ?? null)) {
            $daily[] = [$lunch[0], $lunch[1], 'lunch'];
        }
        if ((int)($settings['judging_breaks_yes_no'] ?? 0) === 1) {
            foreach ([
                ['judging_breaks_morning_break_starting_time', 'judging_breaks_morning_break_finish_time'],
                ['judging_breaks_afternoon_break_start_time', 'judging_breaks_afternoon_break_finish_time'],
            ] as [$from, $to]) {
                if ($block = $range($settings[$from] ?? null, $settings[$to] ?? null)) {
                    $daily[] = [$block[0], $block[1], 'judging break'];
                }
            }
        }

        $blocked = [];
        foreach ($dates as $date) {
            $blocks = $daily;
            if ((int)($settings['sports_day_yes_no'] ?? 0) === 1 && ($settings['sports_day'] ?? null) === $date['day']) {
                if ($sport = $range($settings['sports_day_starting_time'] ?? null, $settings['sports_day_finish_time'] ?? null)) {
                    $blocks[] = [$sport[0], $sport[1], 'sports'];
                }
                $after = $range($settings['sports_day_other_starting_time'] ?? null, $settings['sports_day_other_finish_time'] ?? null);
                if ((int)($settings['sports_day_having_events_after_sport_yes_no'] ?? 0) === 1 && $after) {
                    $blocks[] = [0, $after[0], 'outside after-sport window'];
                    $blocks[] = [$after[1], 24 * 60, 'outside after-sport window'];
                }
            }
            $blocked[$date['date']] = $blocks;
        }

        return $blocked;
    }

    /**
     * Earliest valid [start, finish] at or after $cursor for this room and band.
     *
     * @return array{0:int,1:int}|null
     */
    private function resolveSlot(array $band, int $roomId, int $cursor, int $duration, int $bandStart, int $bandEnd): ?array
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            if ($cursor + $duration > $bandEnd) {
                return null;
            }
            $slot = ($this->slotResolver)($band, $roomId, TimetableLedger::toTime($cursor), TimetableLedger::toTime($cursor + $duration));
            if ($slot === null) {
                return null;
            }
            $s = TimetableLedger::toMinutes((string)$slot['start']);
            $f = TimetableLedger::toMinutes((string)$slot['finish']);
            if ($s < $bandStart || $f > $bandEnd || $f <= $s) {
                return null;
            }

            $blockEnd = null;
            foreach ($this->blockedPeriods[$band['date']] ?? [] as [$blockStart, $blockFinish]) {
                if ($blockStart < $f && $blockFinish > $s) {
                    $blockEnd = max($blockEnd ?? 0, $blockFinish);
                }
            }
            if ($blockEnd === null) {
                return [$s, $f];
            }
            $cursor = max($blockEnd, $cursor + 1);
        }

        return null;
    }

    private static function earliestLane(array $lanes): int
    {
        $best = 0;
        foreach ($lanes as $index => $lane) {
            if ($lane['cursor'] < $lanes[$best]['cursor']) {
                $best = $index;
            }
        }

        return $best;
    }

    private function unscheduledResult(array $pending, array $placed, string $reason): array
    {
        $unscheduled = [];
        foreach (array_keys($pending) as $key) {
            $unscheduled[$key] = ['reasons' => [$reason => 1], 'students' => []];
        }

        return ['placed' => $placed, 'unscheduled' => $unscheduled];
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function uniqueIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if ((int)$id > 0) {
                $clean[(int)$id] = (int)$id;
            }
        }

        return array_values($clean);
    }
}
