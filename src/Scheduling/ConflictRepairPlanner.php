<?php
declare(strict_types=1);

namespace App\Scheduling;

/**
 * Builds a minimal repair plan for an existing timetable. Nothing is saved here:
 * the plan lists the bookings to move (from / to / reason) and the problems that
 * cannot be repaired, with the rules that blocked them.
 *
 * 1. TimetableValidator lists the problems. Every booking involved in a movable
 *    problem becomes a candidate (a "unit": one row, or every row of a combined
 *    Category 1 slot).
 * 2. Keep phase: candidates are checked in keep-priority order (pinned events,
 *    knockouts, large groups and least recently edited first). A candidate whose
 *    current slot passes every rule against the bookings kept so far stays put.
 * 3. Move phase: the rest are re-placed one at a time, nearest first: same room
 *    and day, another mapped room the same day, then other days. Slots of
 *    bookings still waiting to move stay reserved, so a booking that cannot move
 *    never ends up clashing with one that did. If no single move fits, a swap with
 *    another booking of the same event is tried. Otherwise the booking is left
 *    unchanged and reported.
 * 4. The result is validated again. A plan that would create any new problem is
 *    rejected for the bookings involved.
 *
 * Every slot is checked with the same rules as timetable generation: TimetableLedger
 * (students, rooms, lanes), blocked periods, convention hours, event/room windows,
 * room mappings, pinned settings and knockout feeder/dependent order.
 */
final class ConflictRepairPlanner
{
    public const TIER_TEXT = [
        0 => 'same room, same day',
        1 => 'another room, same day',
        2 => 'same room, another day',
        3 => 'another room, another day',
        4 => 'first valid slot (was unscheduled)',
    ];

    public const REASON_TEXT = SlotPlanner::REASON_TEXT + [
        TimetableValidator::OUTSIDE_HOURS => 'outside the convention hours or the event/room availability window',
        TimetableValidator::BLOCKED_PERIOD => 'overlaps lunch, a judging break or the sports block',
        TimetableValidator::PINNED => 'breaks a pinned room, day or start time',
        TimetableValidator::UNMAPPED_ROOM => 'room not mapped to the event',
        'dependent_starts' => 'a later knockout round starts before the match would finish',
        'no_slot' => 'no slot in the convention fits the event duration',
    ];

    private TimetableValidator $validator;

    /** @var array<int, array> row id => booking (current plan state) */
    private array $bookings = [];

    /** @var array<int, list<int>> row id => rows fed by it */
    private array $dependents = [];

    private TimetableLedger $ledger;

    /** @var array<int, true> rows whose final slot is still being decided (keep phase only) */
    private array $undecided = [];

    public function __construct(private array $context, private int $stepMinutes = 5)
    {
        $this->validator = new TimetableValidator($context);
    }

    /**
     * @param array<int, array> $bookings row id => booking (see TimetableValidator)
     * @param list<int> $lockedRows rows that must not be moved
     * @return array{before: array, after: array, moves: list<array>, unresolved: list<array>, not_movable: list<array>, bookings: array<int,array>}
     */
    public function plan(array $bookings, array $lockedRows = []): array
    {
        $before = $this->validator->validate($bookings);
        $locked = array_fill_keys(array_map('intval', $lockedRows), true);
        $result = null;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $result = $this->attempt($bookings, $before, $locked);
            $after = $this->validator->validate($result['bookings']);
            $beforeKeys = array_fill_keys(array_column($before['issues'], 'key'), true);
            $movedRows = [];
            foreach ($result['moves'] as $move) {
                foreach ($move['rows'] as $rowId) {
                    $movedRows[$rowId] = true;
                }
            }
            $culprits = [];
            foreach ($after['issues'] as $issue) {
                if (isset($beforeKeys[$issue['key']])) {
                    continue;
                }
                foreach ($issue['rows'] as $rowId) {
                    if (isset($movedRows[$rowId])) {
                        $culprits[$rowId] = true;
                    }
                }
            }
            $result['after'] = $after;
            if (!$culprits) {
                break;
            }
            // Never accept a new problem: lock the moved rows involved and plan again.
            $locked += $culprits;
            $result = null;
        }
        if ($result === null) {
            $result = ['bookings' => $bookings, 'moves' => [], 'unresolved' => [], 'not_movable' => [], 'after' => $before];
        }
        $result['before'] = $before;

        return $result;
    }

    private function attempt(array $bookings, array $before, array $locked): array
    {
        $this->bookings = $bookings;
        $this->dependents = [];
        foreach ($bookings as $id => $booking) {
            foreach ($booking['feeders'] ?? [] as $feederId) {
                $this->dependents[(int)$feederId][] = (int)$id;
            }
        }

        $issuesByRow = [];
        $notMovable = [];
        foreach ($before['issues'] as $issue) {
            if (!in_array($issue['type'], TimetableValidator::MOVABLE_TYPES, true)) {
                $notMovable[] = $issue;
                continue;
            }
            foreach ($issue['rows'] as $rowId) {
                $issuesByRow[$rowId][] = $issue;
            }
        }

        $units = $this->buildUnits($bookings);
        $unitOfRow = [];
        foreach ($units as $unitKey => $unit) {
            foreach ($unit['rows'] as $rowId) {
                $unitOfRow[$rowId] = $unitKey;
            }
        }
        $candidates = [];
        foreach (array_keys($issuesByRow) as $rowId) {
            if (isset($unitOfRow[$rowId])) {
                $candidates[$unitOfRow[$rowId]] = true;
            }
        }

        // Ledger of every booking that is not a candidate.
        $this->ledger = new TimetableLedger((int)($this->context['buffer'] ?? 0));
        foreach ($units as $unitKey => $unit) {
            if (!isset($candidates[$unitKey]) && $unit['timed']) {
                $this->bookUnit($unit);
            }
        }

        $order = array_keys($candidates);
        usort($order, fn ($a, $b) => $this->keepPriority($units[$b], $locked) <=> $this->keepPriority($units[$a], $locked) ?: $units[$a]['rows'][0] <=> $units[$b]['rows'][0]);

        // Keep phase. Knockout partners that are still undecided do not count against a
        // booking: of a feeder and its dependent in the wrong order, only one has to move.
        $toMove = [];
        $this->undecided = [];
        foreach ($order as $unitKey) {
            foreach ($units[$unitKey]['rows'] as $rowId) {
                $this->undecided[$rowId] = true;
            }
        }
        foreach ($order as $unitKey) {
            $unit = $units[$unitKey];
            foreach ($unit['rows'] as $rowId) {
                unset($this->undecided[$rowId]);
            }
            if ($unit['timed'] && $this->check($unit, (int)$unit['room_id'], (string)$unit['date'], (int)$unit['start'], (int)$unit['finish'], true) === null) {
                $this->bookUnit($unit);
                continue;
            }
            $toMove[] = $unitKey;
        }
        $this->undecided = [];
        // Waiting units keep their current slot reserved until they move.
        foreach ($toMove as $unitKey) {
            if ($units[$unitKey]['timed']) {
                $this->bookUnit($units[$unitKey]);
            }
        }

        $moves = [];
        $unresolved = [];
        foreach ($toMove as $unitKey) {
            $unit = $units[$unitKey];
            $reasonIssues = [];
            foreach ($unit['rows'] as $rowId) {
                foreach ($issuesByRow[$rowId] ?? [] as $issue) {
                    $reasonIssues[$issue['key']] = $issue;
                }
            }
            $reasonText = array_values(array_map(fn ($issue) => $issue['message'], $reasonIssues));
            $reasonTypes = array_values(array_unique(array_column($reasonIssues, 'type')));

            if (isset($locked[$unit['rows'][0]])) {
                $unresolved[] = $this->unresolvedEntry($unit, $reasonTypes, $reasonText, ['locked' => 1]);
                continue;
            }
            if ($unit['timed']) {
                $this->releaseUnit($unit);
            }
            $found = $this->findSlot($unit);
            if ($found['slot'] !== null) {
                $slot = $found['slot'];
                $moves[] = $this->moveEntry($unit, $slot, $found['tier'], $reasonTypes, $reasonText);
                $unit = $this->applySlot($unit, $slot);
                $units[$unitKey] = $unit;
                $this->bookUnit($unit);
                continue;
            }
            $swap = $unit['timed'] ? $this->findSwap($unit, $units, $candidates, $locked) : null;
            if ($swap !== null) {
                $partner = $units[$swap['partner']];
                $moves[] = $this->moveEntry($unit, $swap['slot'], 5, $reasonTypes, $reasonText, $partner['rows']);
                $moves[] = $this->moveEntry($partner, $swap['partner_slot'], 5, [], ['swapped with '.$this->validator->describe($this->bookings[$unit['rows'][0]])], $unit['rows']);
                $units[$unitKey] = $this->applySlot($unit, $swap['slot']);
                $units[$swap['partner']] = $this->applySlot($partner, $swap['partner_slot']);
                $this->bookUnit($units[$unitKey]);
                $this->bookUnit($units[$swap['partner']]);
                continue;
            }
            if ($unit['timed']) {
                $this->bookUnit($unit);
            }
            $unresolved[] = $this->unresolvedEntry($unit, $reasonTypes, $reasonText, $found['reasons']);
        }

        return ['bookings' => $this->bookings, 'moves' => $moves, 'unresolved' => $unresolved, 'not_movable' => $notMovable];
    }

    /**
     * One unit per row, except Category 1 rows sharing a slot (combined schools), which move together.
     *
     * @return array<string, array>
     */
    private function buildUnits(array $bookings): array
    {
        $units = [];
        foreach ($bookings as $id => $booking) {
            if (!empty($booking['bye'])) {
                continue;
            }
            $timed = TimetableValidator::isTimed($booking);
            $key = (int)$booking['category'] === 1 && $timed ? 'u-'.TimetableValidator::sessionKey($booking) : 'u-row-'.$id;
            if (!isset($units[$key])) {
                $units[$key] = $booking + ['rows' => [], 'timed' => $timed, 'all_students' => [], 'all_possible' => []];
            }
            $units[$key]['rows'][] = (int)$id;
            $units[$key]['all_students'] = array_values(array_unique(array_merge($units[$key]['all_students'], array_map('intval', $booking['students'] ?? []))));
            $units[$key]['all_possible'] = array_values(array_unique(array_merge($units[$key]['all_possible'], array_map('intval', $booking['possible'] ?? []))));
        }
        foreach ($units as &$unit) {
            sort($unit['rows']);
        }

        return $units;
    }

    /**
     * Higher keeps its slot. The most recently edited booking (usually the manual change) moves first.
     *
     * @return list<int>
     */
    private function keepPriority(array $unit, array $locked): array
    {
        $modified = (string)($unit['modified'] ?? '');

        return [
            isset($locked[$unit['rows'][0]]) ? 1 : 0,
            $unit['timed'] ? 1 : 0,
            empty($this->context['pinned'][(int)$unit['event_id']]) ? 0 : 1,
            in_array((int)$unit['category'], [2, 3], true) ? 1 : 0,
            count($unit['all_students']),
            $modified === '' ? 0 : -(int)strtotime($modified),
        ];
    }

    private function bookUnit(array $unit): void
    {
        foreach ($unit['rows'] as $rowId) {
            $row = $this->bookings[$rowId];
            $this->ledger->book((int)$row['room_id'], (string)$row['date'], (int)$row['start'], (int)$row['finish'], (int)$row['event_id'], $row['students'] ?? [], $row['possible'] ?? [], TimetableValidator::sessionKey($row), (int)($row['capacity'] ?? 0));
        }
    }

    private function releaseUnit(array $unit): void
    {
        foreach ($unit['rows'] as $rowId) {
            $row = $this->bookings[$rowId];
            $this->ledger->release(TimetableValidator::sessionKey($row), $row['students'] ?? [], $row['possible'] ?? []);
        }
    }

    private function applySlot(array $unit, array $slot): array
    {
        foreach ($unit['rows'] as $rowId) {
            $this->bookings[$rowId]['room_id'] = $slot['room_id'];
            $this->bookings[$rowId]['date'] = $slot['date'];
            $this->bookings[$rowId]['start'] = $slot['start'];
            $this->bookings[$rowId]['finish'] = $slot['finish'];
        }
        $unit['room_id'] = $slot['room_id'];
        $unit['date'] = $slot['date'];
        $unit['start'] = $slot['start'];
        $unit['finish'] = $slot['finish'];
        $unit['timed'] = true;

        return $unit;
    }

    /**
     * Rule check for one slot against the current ledger. Returns null when valid,
     * otherwise the first rule that failed.
     */
    private function check(array $unit, int $roomId, string $date, int $start, int $finish, bool $currentSlot = false): ?string
    {
        $problems = $this->validator->slotProblems($unit, $roomId, $date, $start, $finish);
        if ($problems) {
            return $problems[0];
        }
        $here = TimetableValidator::absolute($date, $start);
        $end = TimetableValidator::absolute($date, $finish);
        foreach ($unit['rows'] as $rowId) {
            foreach ($this->bookings[$rowId]['feeders'] ?? [] as $feederId) {
                if (isset($this->undecided[(int)$feederId])) {
                    continue;
                }
                $ready = TimetableValidator::readyFrom($this->bookings, (int)$feederId);
                if ($ready === false) {
                    return 'feeder_unscheduled';
                }
                if ($ready !== null && $ready > $here) {
                    return 'feeder_running';
                }
            }
            $deadline = $this->deadline($rowId);
            if ($deadline !== null && $end > $deadline) {
                return 'dependent_starts';
            }
        }
        $lanes = max(1, $this->validator->lanesFor($unit, $roomId) ?: 1);
        // A booking staying in its batch session (spellers, combined schools) shares that lane.
        $ownSession = $currentSlot ? TimetableValidator::sessionKey($this->bookings[$unit['rows'][0]]) : null;
        $roomBlock = $this->ledger->roomBlock($roomId, $date, $start, $finish, (int)$unit['event_id'], $lanes, $ownSession);
        if ($roomBlock !== null) {
            return $roomBlock['reason'];
        }
        if ($this->ledger->studentBlocks($unit['all_students'], $date, $start, $finish)) {
            return 'student_busy';
        }

        return null;
    }

    /**
     * Earliest absolute minute at which a later round fed by $rowId starts (byes pass it on).
     */
    private function deadline(int $rowId, int $depth = 0): ?int
    {
        if ($depth > 32) {
            return null;
        }
        $deadline = null;
        foreach ($this->dependents[$rowId] ?? [] as $dependentId) {
            $dependent = $this->bookings[$dependentId] ?? null;
            if ($dependent === null || isset($this->undecided[$dependentId])) {
                continue;
            }
            if (!empty($dependent['bye'])) {
                $candidate = $this->deadline($dependentId, $depth + 1);
            } elseif (TimetableValidator::isTimed($dependent)) {
                $candidate = TimetableValidator::absolute((string)$dependent['date'], (int)$dependent['start']);
            } else {
                $candidate = null;
            }
            if ($candidate !== null) {
                $deadline = min($deadline ?? $candidate, $candidate);
            }
        }

        return $deadline;
    }

    private function duration(array $unit): int
    {
        $configured = (int)($this->context['events'][(int)$unit['event_id']]['duration'] ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        return $unit['timed'] ? (int)$unit['finish'] - (int)$unit['start'] : 0;
    }

    /**
     * Nearest valid slot: tier (room/day change), then no back-to-back, then distance in minutes.
     *
     * @return array{slot: ?array, tier: int, reasons: array<string,int>}
     */
    private function findSlot(array $unit): array
    {
        $duration = $this->duration($unit);
        if ($duration <= 0) {
            return ['slot' => null, 'tier' => 0, 'reasons' => ['no_duration' => 1]];
        }
        $eventId = (int)$unit['event_id'];
        $pinned = $this->context['pinned'][$eventId] ?? [];
        $rooms = array_keys($this->context['event_rooms'][$eventId] ?? []);
        if (!empty($pinned['room'])) {
            $rooms = [(int)$pinned['room']];
        }
        if (!$rooms && $unit['timed'] && (int)$unit['room_id'] > 0) {
            $rooms = [(int)$unit['room_id']];
        }
        if (!$rooms) {
            return ['slot' => null, 'tier' => 0, 'reasons' => ['no_rooms' => 1]];
        }
        sort($rooms);
        $origin = $unit['timed'] ? TimetableValidator::absolute((string)$unit['date'], (int)$unit['start']) : null;
        $gap = (int)($this->context['preferred_gap'] ?? 5);

        $reasons = [];
        $tiers = $unit['timed'] ? [0, 1, 2, 3] : [4];
        foreach ($tiers as $tier) {
            $best = null;
            foreach (array_keys($this->context['dates']) as $date) {
                $sameDay = $unit['timed'] && $date === $unit['date'];
                if (($tier <= 1 && !$sameDay) || (in_array($tier, [2, 3], true) && $sameDay)) {
                    continue;
                }
                foreach ($rooms as $roomId) {
                    $sameRoom = $unit['timed'] && $roomId === (int)$unit['room_id'];
                    if ((in_array($tier, [0, 2], true) && !$sameRoom) || (in_array($tier, [1, 3], true) && $sameRoom)) {
                        continue;
                    }
                    foreach ($this->candidateStarts($unit, $roomId, $date, $duration) as $start) {
                        $finish = $start + $duration;
                        $problem = $this->check($unit, $roomId, $date, $start, $finish);
                        if ($problem !== null) {
                            $reasons[$problem] = ($reasons[$problem] ?? 0) + 1;
                            continue;
                        }
                        $absolute = TimetableValidator::absolute($date, $start);
                        $soft = $this->ledger->softPenalty($unit['all_students'], $unit['all_possible'], $date, $start, $finish, $gap, $eventId) > 0 ? 1 : 0;
                        $rank = [$soft, $origin === null ? $absolute : abs($absolute - $origin), $roomId, $absolute];
                        if ($best === null || $rank < $best['rank']) {
                            $best = ['rank' => $rank, 'slot' => ['room_id' => $roomId, 'date' => $date, 'start' => $start, 'finish' => $finish]];
                        }
                    }
                }
            }
            if ($best !== null) {
                return ['slot' => $best['slot'], 'tier' => $tier, 'reasons' => []];
            }
        }
        arsort($reasons);

        return ['slot' => null, 'tier' => 0, 'reasons' => $reasons ?: ['no_slot' => 1]];
    }

    /**
     * Start minutes to try in one room on one date: a regular grid, the original start,
     * the ends of blocked periods and the ends of sessions already in the room.
     *
     * @return list<int>
     */
    private function candidateStarts(array $unit, int $roomId, string $date, int $duration): array
    {
        $day = $this->context['dates'][$date];
        $starts = [];
        for ($minute = (int)$day['start']; $minute + $duration <= (int)$day['end']; $minute += $this->stepMinutes) {
            $starts[$minute] = true;
        }
        if ($unit['timed'] && $unit['date'] === $date) {
            $starts[(int)$unit['start']] = true;
        }
        foreach ($this->context['blocked'][$date] ?? [] as $block) {
            $starts[(int)$block[1]] = true;
        }
        foreach ($this->ledger->roomSessions($roomId, $date) as $session) {
            $starts[(int)$session['f']] = true;
        }
        foreach ([$this->context['event_windows'][(int)$unit['event_id']]['from'] ?? null, $this->context['room_windows'][$roomId]['from'] ?? null, $this->context['pinned'][(int)$unit['event_id']]['start'] ?? null] as $from) {
            if ($from !== null) {
                $starts[(int)$from] = true;
            }
        }
        $list = array_keys(array_filter($starts));
        sort($list);

        return array_values(array_filter($list, fn ($minute) => $minute >= (int)$day['start'] && $minute + $duration <= (int)$day['end']));
    }

    /**
     * Two bookings of the same event exchange slots when both slots then pass every rule.
     *
     * @return array{partner:string, slot:array, partner_slot:array}|null
     */
    private function findSwap(array $unit, array $units, array $candidates, array $locked): ?array
    {
        if (in_array((int)$unit['category'], [2, 3], true)) {
            return null;
        }
        $mySlot = ['room_id' => (int)$unit['room_id'], 'date' => (string)$unit['date'], 'start' => (int)$unit['start'], 'finish' => (int)$unit['finish']];
        $origin = TimetableValidator::absolute($mySlot['date'], $mySlot['start']);
        $best = null;
        foreach ($units as $partnerKey => $partner) {
            if (isset($candidates[$partnerKey]) || isset($locked[$partner['rows'][0]]) || !$partner['timed']
                || (int)$partner['event_id'] !== (int)$unit['event_id'] || (int)$partner['category'] !== (int)$unit['category']
                || ((int)$partner['finish'] - (int)$partner['start']) !== ($mySlot['finish'] - $mySlot['start'])) {
                continue;
            }
            $partnerSlot = ['room_id' => (int)$partner['room_id'], 'date' => (string)$partner['date'], 'start' => (int)$partner['start'], 'finish' => (int)$partner['finish']];
            $this->releaseUnit($partner);
            $ok = $this->check($unit, $partnerSlot['room_id'], $partnerSlot['date'], $partnerSlot['start'], $partnerSlot['finish']) === null;
            if ($ok) {
                $moved = $this->applySlot($unit, $partnerSlot);
                $this->bookUnit($moved);
                $ok = $this->check($partner, $mySlot['room_id'], $mySlot['date'], $mySlot['start'], $mySlot['finish']) === null;
                $this->releaseUnit($moved);
                $this->applySlot($unit, $mySlot);
            }
            $this->bookUnit($partner);
            if ($ok) {
                $distance = abs(TimetableValidator::absolute($partnerSlot['date'], $partnerSlot['start']) - $origin);
                if ($best === null || $distance < $best['distance']) {
                    $best = ['distance' => $distance, 'partner' => $partnerKey, 'slot' => $partnerSlot, 'partner_slot' => $mySlot];
                }
            }
        }
        if ($best !== null) {
            $this->releaseUnit($units[$best['partner']]);
        }

        return $best;
    }

    private function slotView(array $slot): array
    {
        $day = $this->context['dates'][$slot['date']]['day'] ?? date('l', strtotime((string)$slot['date']));

        return [
            'room_id' => (int)$slot['room_id'],
            'room' => $this->validator->roomName((int)$slot['room_id']),
            'date' => (string)$slot['date'],
            'day' => $day,
            'start' => TimetableLedger::toTime((int)$slot['start']),
            'finish' => TimetableLedger::toTime((int)$slot['finish']),
        ];
    }

    private function moveEntry(array $unit, array $slot, int $tier, array $types, array $reasons, array $swappedWith = []): array
    {
        $first = $this->bookings[$unit['rows'][0]];

        return [
            'rows' => $unit['rows'],
            'category' => (int)$unit['category'],
            'event_id' => (int)$unit['event_id'],
            'booking' => $this->validator->describe($first),
            'students' => $unit['all_students'],
            'from' => $unit['timed'] ? $this->slotView(['room_id' => $unit['room_id'], 'date' => $unit['date'], 'start' => $unit['start'], 'finish' => $unit['finish']]) : null,
            'to' => $this->slotView($slot),
            'how' => $tier === 5 ? 'swapped with another booking of the same event' : (self::TIER_TEXT[$tier] ?? ''),
            'issue_types' => $types,
            'reasons' => $reasons,
            'swapped_with' => $swappedWith,
        ];
    }

    private function unresolvedEntry(array $unit, array $types, array $issues, array $reasons): array
    {
        $first = $this->bookings[$unit['rows'][0]];
        $text = [];
        foreach (array_slice($reasons, 0, 4, true) as $code => $count) {
            $text[] = $code === 'locked'
                ? 'kept in place because moving it would create a new problem'
                : (self::REASON_TEXT[$code] ?? $code).' ('.$count.' slot'.($count === 1 ? '' : 's').')';
        }

        return [
            'rows' => $unit['rows'],
            'category' => (int)$unit['category'],
            'event_id' => (int)$unit['event_id'],
            'booking' => $this->validator->describe($first),
            'students' => $unit['all_students'],
            'current' => $unit['timed'] ? $this->slotView(['room_id' => $unit['room_id'], 'date' => $unit['date'], 'start' => $unit['start'], 'finish' => $unit['finish']]) : null,
            'duration' => $this->duration($unit),
            'issue_types' => $types,
            'issues' => $issues,
            'blocked_by' => $text,
        ];
    }
}
