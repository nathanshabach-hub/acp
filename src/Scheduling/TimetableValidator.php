<?php
declare(strict_types=1);

namespace App\Scheduling;

/**
 * Checks a whole season timetable against the scheduling rules and lists every problem.
 *
 * Input bookings (one per schedulingtimings row):
 *   id, category, event_id, room_id, date (Y-m-d|null), start/finish (minutes|null), bye,
 *   round, match, feeders (row ids), students (confirmed ids), possible (unknown knockout
 *   winners), capacity (optional lane override), label.
 *
 * Context (see TimetableLoader): dates, blocked, event_windows, room_windows, pinned,
 * event_rooms (event => room => lanes), events (number, name, duration), rooms, buffer.
 *
 * Every issue has a stable key built from its type and row ids, so a later run can tell
 * whether an issue is new or was already there.
 */
final class TimetableValidator
{
    public const STUDENT_OVERLAP = 'student_overlap';
    public const ROOM_CLASH = 'room_clash';
    public const ROOM_CAPACITY = 'room_capacity';
    public const KNOCKOUT_ORDER = 'knockout_order';
    public const PHANTOM_MATCH = 'phantom_match';
    public const DUPLICATE_MATCH = 'duplicate_match';
    public const OUTSIDE_HOURS = 'outside_hours';
    public const BLOCKED_PERIOD = 'blocked_period';
    public const PINNED = 'pinned_violation';
    public const UNMAPPED_ROOM = 'unmapped_room';
    public const UNSCHEDULED = 'unscheduled';

    public const LABELS = [
        self::STUDENT_OVERLAP => 'Student overlaps',
        self::ROOM_CLASH => 'Room used by two events at once',
        self::ROOM_CAPACITY => 'Room lanes over capacity',
        self::KNOCKOUT_ORDER => 'Knockout match before its earlier round',
        self::PHANTOM_MATCH => 'Phantom knockout matches',
        self::DUPLICATE_MATCH => 'Duplicate knockout matches',
        self::OUTSIDE_HOURS => 'Outside convention dates or hours',
        self::BLOCKED_PERIOD => 'Overlapping lunch, judging or sports blocks',
        self::PINNED => 'Pinned event settings not respected',
        self::UNMAPPED_ROOM => 'Room not mapped to the event',
        self::UNSCHEDULED => 'Unscheduled bookings',
    ];

    /** Issue types a booking move can repair. */
    public const MOVABLE_TYPES = [
        self::STUDENT_OVERLAP, self::ROOM_CLASH, self::ROOM_CAPACITY, self::KNOCKOUT_ORDER,
        self::OUTSIDE_HOURS, self::BLOCKED_PERIOD, self::PINNED, self::UNMAPPED_ROOM, self::UNSCHEDULED,
    ];

    public function __construct(private array $context)
    {
    }

    /**
     * @param array<int, array> $bookings row id => booking
     * @return array{counts: array<string,int>, issues: list<array{type:string,key:string,rows:list<int>,students:list<int>,message:string}>,
     *   bookings_by_category: array<int,int>, total_issues: int}
     */
    public function validate(array $bookings): array
    {
        $issues = [];
        $add = function (string $type, array $rows, string $message, array $students = []) use (&$issues): void {
            $rows = array_values(array_unique(array_map('intval', $rows)));
            sort($rows);
            $key = $type.':'.implode('-', $rows);
            if (!isset($issues[$key])) {
                $issues[$key] = ['type' => $type, 'key' => $key, 'rows' => $rows, 'students' => [], 'message' => $message];
            }
            $issues[$key]['students'] = array_values(array_unique(array_merge($issues[$key]['students'], array_map('intval', $students))));
        };

        $byCategory = [];
        $timed = [];
        foreach ($bookings as $id => $booking) {
            if (!empty($booking['bye'])) {
                continue;
            }
            if (!self::isTimed($booking)) {
                $add(self::UNSCHEDULED, [$id], $this->describe($booking).' has no time');
                continue;
            }
            $category = (int)$booking['category'];
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
            $timed[$id] = $booking;
            $this->checkSingle($booking, $add);
        }

        $this->checkStudents($timed, $add);
        $this->checkRooms($timed, $add);
        $this->checkKnockouts($bookings, $add);

        $counts = array_fill_keys(array_keys(self::LABELS), 0);
        foreach ($issues as $issue) {
            $counts[$issue['type']]++;
        }
        ksort($byCategory);

        return [
            'counts' => $counts,
            'issues' => array_values($issues),
            'bookings_by_category' => $byCategory,
            'total_issues' => count($issues),
        ];
    }

    public static function isTimed(array $booking): bool
    {
        return !empty($booking['date']) && $booking['start'] !== null && $booking['finish'] !== null && (int)$booking['finish'] > (int)$booking['start'];
    }

    /**
     * Ledger session key: rows of category 1/4 sharing one slot use one room lane.
     */
    public static function sessionKey(array $booking): string
    {
        if (in_array((int)$booking['category'], [2, 3], true)) {
            return 'row-'.(int)$booking['id'];
        }

        return 'slot-'.(int)$booking['room_id'].'-'.(int)$booking['event_id'].'-'.$booking['date'].'-'.(int)$booking['start'].'-'.(int)$booking['finish'];
    }

    /**
     * Room lanes available to this booking's event in a room (0 = room not mapped).
     */
    public function lanesFor(array $booking, int $roomId): int
    {
        $rooms = $this->context['event_rooms'][(int)$booking['event_id']] ?? [];
        $lanes = (int)($rooms[$roomId] ?? 0);
        if ($lanes > 0 && !empty($booking['capacity'])) {
            $lanes = min($lanes, (int)$booking['capacity']);
        }

        return $lanes;
    }

    /**
     * Rule checks that need only the booking itself. Returns the failed rule codes.
     *
     * @return list<string>
     */
    public function slotProblems(array $booking, int $roomId, string $date, int $start, int $finish): array
    {
        $problems = [];
        $day = $this->context['dates'][$date] ?? null;
        $eventId = (int)$booking['event_id'];
        $eventWindow = $this->context['event_windows'][$eventId] ?? [];
        $roomWindow = $this->context['room_windows'][$roomId] ?? [];
        if ($day === null || $start < $day['start'] || $finish > $day['end']
            || (isset($eventWindow['from']) && $start < $eventWindow['from']) || (isset($eventWindow['to']) && $finish > $eventWindow['to'])
            || (isset($roomWindow['from']) && $start < $roomWindow['from']) || (isset($roomWindow['to']) && $finish > $roomWindow['to'])) {
            $problems[] = self::OUTSIDE_HOURS;
        }
        foreach ($this->context['blocked'][$date] ?? [] as $block) {
            if ($block[0] < $finish && $block[1] > $start) {
                $problems[] = self::BLOCKED_PERIOD;
                break;
            }
        }
        $pinned = $this->context['pinned'][$eventId] ?? null;
        if ($pinned) {
            if (!empty($pinned['room']) && (int)$pinned['room'] !== $roomId) {
                $problems[] = self::PINNED;
            } elseif (!empty($pinned['date']) || isset($pinned['start'])) {
                $earliestDate = $pinned['date'] ?? array_key_first($this->context['dates']);
                if ($date < $earliestDate || ($date === $earliestDate && $start < (int)($pinned['start'] ?? 0))) {
                    $problems[] = self::PINNED;
                }
            }
        }
        if (!empty($this->context['event_rooms'][$eventId]) && $this->lanesFor($booking, $roomId) === 0 && !in_array(self::PINNED, $problems, true)) {
            $problems[] = self::UNMAPPED_ROOM;
        }

        return $problems;
    }

    private function checkSingle(array $booking, callable $add): void
    {
        $problems = $this->slotProblems($booking, (int)$booking['room_id'], (string)$booking['date'], (int)$booking['start'], (int)$booking['finish']);
        $where = $this->describe($booking).' at '.$this->when($booking);
        foreach ($problems as $problem) {
            $message = match ($problem) {
                self::OUTSIDE_HOURS => $where.' is outside the convention dates, daily hours or the event/room availability window',
                self::BLOCKED_PERIOD => $where.' overlaps '.$this->blockedLabel($booking),
                self::PINNED => $where.' does not respect the pinned '.$this->pinnedText((int)$booking['event_id']),
                self::UNMAPPED_ROOM => $where.' uses a room that is not mapped to the event',
                default => $where,
            };
            $add($problem, [(int)$booking['id']], $message);
        }
    }

    private function checkStudents(array $timed, callable $add): void
    {
        $buffer = (int)($this->context['buffer'] ?? 0);
        $byStudent = [];
        foreach ($timed as $id => $booking) {
            foreach (array_unique(array_map('intval', $booking['students'] ?? [])) as $studentId) {
                if ($studentId > 0) {
                    $byStudent[$studentId][$booking['date']][] = $id;
                }
            }
        }
        foreach ($byStudent as $studentId => $byDate) {
            foreach ($byDate as $ids) {
                usort($ids, fn ($a, $b) => $timed[$a]['start'] <=> $timed[$b]['start']);
                $count = count($ids);
                for ($i = 0; $i < $count; $i++) {
                    $a = $timed[$ids[$i]];
                    for ($j = $i + 1; $j < $count && $timed[$ids[$j]]['start'] < $a['finish'] + $buffer; $j++) {
                        $b = $timed[$ids[$j]];
                        if (self::sessionKey($a) === self::sessionKey($b)) {
                            continue;
                        }
                        $add(self::STUDENT_OVERLAP, [$a['id'], $b['id']], $this->describe($a).' at '.$this->when($a).' overlaps '.$this->describe($b).' at '.$this->when($b), [$studentId]);
                    }
                }
            }
        }
    }

    private function checkRooms(array $timed, callable $add): void
    {
        $sessions = [];
        foreach ($timed as $id => $booking) {
            $key = self::sessionKey($booking);
            $sessions[(int)$booking['room_id']][$booking['date']][$key] ??= ['booking' => $booking, 'rows' => []];
            $sessions[(int)$booking['room_id']][$booking['date']][$key]['rows'][] = $id;
        }
        foreach ($sessions as $roomId => $byDate) {
            if ($roomId <= 0) {
                continue;
            }
            foreach ($byDate as $list) {
                $list = array_values($list);
                usort($list, fn ($a, $b) => $a['booking']['start'] <=> $b['booking']['start']);
                $count = count($list);
                for ($i = 0; $i < $count; $i++) {
                    $a = $list[$i]['booking'];
                    $sameEvent = [];
                    for ($j = 0; $j < $count; $j++) {
                        $b = $list[$j]['booking'];
                        if ($j === $i || $b['start'] >= $a['finish'] || $b['finish'] <= $a['start']) {
                            continue;
                        }
                        if ((int)$a['event_id'] !== (int)$b['event_id']) {
                            if ($j > $i) {
                                $add(self::ROOM_CLASH, array_merge($list[$i]['rows'], $list[$j]['rows']), 'Room '.$this->roomName($roomId).': '.$this->describe($a).' at '.$this->when($a).' and '.$this->describe($b).' at '.$this->when($b));
                            }
                        } else {
                            $sameEvent[] = $list[$j];
                        }
                    }
                    if (!$sameEvent) {
                        continue;
                    }
                    $lanes = max(1, $this->lanesFor($a, $roomId) ?: 1);
                    $peak = self::peak(array_map(fn ($s) => $s['booking'], $sameEvent), (int)$a['start'], (int)$a['finish']) + 1;
                    if ($peak > $lanes) {
                        $rows = $list[$i]['rows'];
                        foreach ($sameEvent as $other) {
                            $rows = array_merge($rows, $other['rows']);
                        }
                        $add(self::ROOM_CAPACITY, $rows, 'Room '.$this->roomName($roomId).': '.$peak.' '.$this->eventName((int)$a['event_id']).' sessions at once around '.$this->when($a).', '.$lanes.' lane(s) allowed');
                    }
                }
            }
        }
    }

    private function checkKnockouts(array $bookings, callable $add): void
    {
        $byEvent = [];
        foreach ($bookings as $id => $booking) {
            if (in_array((int)$booking['category'], [2, 3], true)) {
                $byEvent[(int)$booking['event_id']][$id] = $booking;
            }
        }
        foreach ($byEvent as $eventRows) {
            $entrants = 0;
            foreach ($eventRows as $row) {
                if ((int)$row['round'] === 1) {
                    $entrants += !empty($row['bye']) ? 1 : 2;
                }
            }
            $rounds = KnockoutBracketPlanner::roundCount($entrants);
            $seen = [];
            foreach ($eventRows as $id => $row) {
                if ($rounds > 0 && (int)$row['round'] > $rounds) {
                    $add(self::PHANTOM_MATCH, [$id], $this->describe($row).' is beyond the final ('.$rounds.' rounds for '.$entrants.' entrants)');
                }
                $matchKey = (int)$row['round'].'-'.(int)$row['match'];
                if (isset($seen[$matchKey])) {
                    $add(self::DUPLICATE_MATCH, [$seen[$matchKey], $id], $this->describe($row).' appears twice');
                }
                $seen[$matchKey] = $id;
                if (!empty($row['bye']) || !self::isTimed($row)) {
                    continue;
                }
                $start = self::absolute((string)$row['date'], (int)$row['start']);
                foreach ($row['feeders'] ?? [] as $feederId) {
                    $ready = self::readyFrom($bookings, (int)$feederId);
                    if ($ready === false || ($ready !== null && $ready > $start)) {
                        $feeder = $bookings[(int)$feederId] ?? null;
                        $add(self::KNOCKOUT_ORDER, [(int)$feederId, $id], $this->describe($row).' at '.$this->when($row).' starts before '.($feeder ? $this->describe($feeder) : 'row '.$feederId).' '.($ready === false ? 'is scheduled' : 'finishes'));
                    }
                }
            }
        }
    }

    /**
     * Minute (absolute) at which the winner of $rowId is known. Byes pass on their own
     * feeders' readiness. null = no dependency, false = an earlier match is unscheduled.
     */
    public static function readyFrom(array $bookings, int $rowId, int $depth = 0): int|false|null
    {
        $row = $bookings[$rowId] ?? null;
        if ($row === null || $depth > 32) {
            return null;
        }
        if (empty($row['bye'])) {
            return self::isTimed($row) ? self::absolute((string)$row['date'], (int)$row['finish']) : false;
        }
        $ready = null;
        foreach ($row['feeders'] ?? [] as $feederId) {
            $candidate = self::readyFrom($bookings, (int)$feederId, $depth + 1);
            if ($candidate === false) {
                return false;
            }
            if ($candidate !== null) {
                $ready = max($ready ?? $candidate, $candidate);
            }
        }

        return $ready;
    }

    /**
     * Minutes since 1970-01-01 for a date and minute of day (used to compare across days).
     */
    public static function absolute(string $date, int $minute): int
    {
        return intdiv((int)(new \DateTimeImmutable($date.' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 60) + $minute;
    }

    /**
     * @param list<array> $bookings
     */
    private static function peak(array $bookings, int $s, int $f): int
    {
        $points = [];
        foreach ($bookings as $booking) {
            $points[] = [max($s, (int)$booking['start']), 1];
            $points[] = [min($f, (int)$booking['finish']), -1];
        }
        usort($points, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $running = 0;
        $max = 0;
        foreach ($points as $point) {
            $running += $point[1];
            $max = max($max, $running);
        }

        return $max;
    }

    public function describe(array $booking): string
    {
        $text = $this->eventName((int)$booking['event_id']);
        if (in_array((int)$booking['category'], [2, 3], true)) {
            $text .= ' R'.(int)$booking['round'].' M'.(int)$booking['match'];
        }
        if (!empty($booking['label'])) {
            $text .= ' ('.$booking['label'].')';
        }

        return $text.' [row '.(int)$booking['id'].']';
    }

    public function when(array $booking): string
    {
        if (!self::isTimed($booking)) {
            return 'no time';
        }
        $day = $this->context['dates'][$booking['date']]['day'] ?? date('l', strtotime((string)$booking['date']));

        return $day.' '.$booking['date'].' '.substr(TimetableLedger::toTime((int)$booking['start']), 0, 5).'-'.substr(TimetableLedger::toTime((int)$booking['finish']), 0, 5);
    }

    public function eventName(int $eventId): string
    {
        $event = $this->context['events'][$eventId] ?? null;

        return $event ? trim(($event['number'] ?? '').' '.($event['name'] ?? '')) : 'event '.$eventId;
    }

    public function roomName(int $roomId): string
    {
        return (string)($this->context['rooms'][$roomId] ?? 'room '.$roomId);
    }

    private function blockedLabel(array $booking): string
    {
        $labels = [];
        foreach ($this->context['blocked'][$booking['date']] ?? [] as $block) {
            if ($block[0] < (int)$booking['finish'] && $block[1] > (int)$booking['start']) {
                $labels[] = ($block[2] ?? 'a blocked period').' '.substr(TimetableLedger::toTime($block[0]), 0, 5).'-'.substr(TimetableLedger::toTime(min($block[1], 1439)), 0, 5);
            }
        }

        return implode(', ', $labels);
    }

    public function pinnedText(int $eventId): string
    {
        $pinned = $this->context['pinned'][$eventId] ?? [];
        $parts = [];
        if (!empty($pinned['room'])) {
            $parts[] = 'room '.$this->roomName((int)$pinned['room']);
        }
        if (!empty($pinned['date'])) {
            $parts[] = 'day '.($this->context['dates'][$pinned['date']]['day'] ?? $pinned['date']);
        }
        if (isset($pinned['start'])) {
            $parts[] = 'start '.substr(TimetableLedger::toTime((int)$pinned['start']), 0, 5);
        }

        return $parts ? implode(', ', $parts) : 'settings';
    }
}
