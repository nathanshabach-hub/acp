<?php
declare(strict_types=1);

namespace App\Scheduling;

/**
 * In-memory record of every booked competition slot for one convention season.
 *
 * Rooms: a room session belongs to one event. Sessions of different events may
 * never overlap in the same room; sessions of the same event may overlap up to
 * the room's lane capacity (number_games).
 *
 * Students: each confirmed participant may not have two overlapping bookings
 * (plus an optional hard transition buffer). Possible participants (unknown
 * knockout winners) are tracked separately and only used as a soft preference.
 */
final class TimetableLedger
{
    /** @var array<int, array<string, array<string, array{s:int,f:int,event:int}>>> room => date => key => session */
    private array $rooms = [];

    /** @var array<int, array<string, list<array{s:int,f:int,key:string,event:int}>>> student => date => intervals */
    private array $students = [];

    /** @var array<int, array<string, list<array{s:int,f:int,key:string,event:int}>>> student => date => intervals */
    private array $possible = [];

    private int $sessionCounter = 0;

    /** @var array<string, array{room:int, date:string, refs:int}> session key => where it is booked */
    private array $sessionIndex = [];

    public function __construct(private int $bufferMinutes = 0)
    {
        $this->bufferMinutes = max(0, $bufferMinutes);
    }

    public static function toMinutes(string|int $time): int
    {
        if (is_int($time)) {
            return $time;
        }
        $parts = explode(':', trim($time));

        return ((int)($parts[0] ?? 0)) * 60 + (int)($parts[1] ?? 0);
    }

    public static function toTime(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    public function bufferMinutes(): int
    {
        return $this->bufferMinutes;
    }

    /**
     * Records a booking. Bookings that share $sessionKey in the same room
     * (for example a batch of spellers or two combined schools) use one lane.
     *
     * @param list<int> $studentIds confirmed participants
     * @param list<int> $possibleIds participants that may play (unknown knockout winners)
     * @param int $capacity lane limit this session imposes on its event in the room
     *   while it runs (for example 1 for a single-board knockout round); 0 = room lanes
     * @return string the session key used
     */
    public function book(int $roomId, string $date, string|int $start, string|int $finish, int $eventId, array $studentIds, array $possibleIds = [], ?string $sessionKey = null, int $capacity = 0): string
    {
        $s = self::toMinutes($start);
        $f = self::toMinutes($finish);
        $sessionKey ??= 'session-'.(++$this->sessionCounter);
        if ($f <= $s) {
            return $sessionKey;
        }

        if ($roomId > 0 && !isset($this->rooms[$roomId][$date][$sessionKey])) {
            $this->rooms[$roomId][$date][$sessionKey] = ['s' => $s, 'f' => $f, 'event' => $eventId, 'cap' => max(0, $capacity)];
        }
        $this->sessionIndex[$sessionKey] ??= ['room' => $roomId, 'date' => $date, 'refs' => 0];
        $this->sessionIndex[$sessionKey]['refs']++;
        foreach (self::ids($studentIds) as $id) {
            $this->students[$id][$date][] = ['s' => $s, 'f' => $f, 'key' => $sessionKey, 'event' => $eventId];
        }
        foreach (self::ids($possibleIds) as $id) {
            $this->possible[$id][$date][] = ['s' => $s, 'f' => $f, 'key' => $sessionKey, 'event' => $eventId];
        }

        return $sessionKey;
    }

    /**
     * Removes one booking made with book(). Bookings sharing a session key (combined
     * schools, speller batches) keep the room session until the last one is released.
     *
     * @param list<int> $studentIds confirmed participants passed to book()
     * @param list<int> $possibleIds possible participants passed to book()
     */
    public function release(string $sessionKey, array $studentIds, array $possibleIds = []): void
    {
        $session = $this->sessionIndex[$sessionKey] ?? null;
        if ($session === null) {
            return;
        }
        $date = $session['date'];
        $strip = function (array &$byStudent, array $ids) use ($date, $sessionKey): void {
            foreach (self::ids($ids) as $id) {
                if (!isset($byStudent[$id][$date])) {
                    continue;
                }
                $byStudent[$id][$date] = array_values(array_filter($byStudent[$id][$date], fn ($interval) => $interval['key'] !== $sessionKey));
            }
        };
        $strip($this->students, $studentIds);
        $strip($this->possible, $possibleIds);

        if (--$this->sessionIndex[$sessionKey]['refs'] <= 0) {
            unset($this->rooms[$session['room']][$date][$sessionKey], $this->sessionIndex[$sessionKey]);
        }
    }

    /**
     * Sessions booked in a room on a date.
     *
     * @return list<array{s:int,f:int,event:int,cap:int}>
     */
    public function roomSessions(int $roomId, string $date): array
    {
        return array_values($this->rooms[$roomId][$date] ?? []);
    }

    /**
     * Why the room cannot take another session of $eventId in [start, finish).
     *
     * @param ?string $ownSessionKey session the booking already belongs to (a speller batch or
     *   combined schools); joining it uses no extra lane, so it is not counted
     * @return array{reason:string, next:int}|null null when the room is free;
     *   'next' is the earliest minute at which the blockage may clear.
     */
    public function roomBlock(int $roomId, string $date, string|int $start, string|int $finish, int $eventId, int $capacity = 1, ?string $ownSessionKey = null): ?array
    {
        $s = self::toMinutes($start);
        $f = self::toMinutes($finish);
        $overlapping = [];
        foreach ($this->rooms[$roomId][$date] ?? [] as $key => $session) {
            if ($ownSessionKey !== null && (string)$key === $ownSessionKey) {
                continue;
            }
            if ($session['s'] < $f && $session['f'] > $s) {
                $overlapping[] = $session;
            }
        }
        if (!$overlapping) {
            return null;
        }

        $other = array_filter($overlapping, fn ($session) => $session['event'] !== $eventId);
        if ($other) {
            return ['reason' => 'room_other_event', 'next' => min(array_column($other, 'f'))];
        }

        // The tightest limit wins: a single-board session already in the room keeps
        // later games out just as it would refuse to join them.
        $limit = max(1, $capacity);
        foreach ($overlapping as $session) {
            if (($session['cap'] ?? 0) > 0) {
                $limit = min($limit, $session['cap']);
            }
        }
        if (self::maxConcurrent($overlapping, $s, $f) + 1 > $limit) {
            return ['reason' => 'room_full', 'next' => min(array_column($overlapping, 'f'))];
        }

        return null;
    }

    /**
     * Confirmed students who are already busy (including the hard buffer).
     *
     * @param list<int> $studentIds
     * @return array<int, int> student id => minute at which that booking (plus buffer) ends
     */
    public function studentBlocks(array $studentIds, string $date, string|int $start, string|int $finish): array
    {
        $s = self::toMinutes($start);
        $f = self::toMinutes($finish);
        $blocked = [];
        foreach (self::ids($studentIds) as $id) {
            foreach ($this->students[$id][$date] ?? [] as $interval) {
                if ($interval['s'] < $f + $this->bufferMinutes && $interval['f'] + $this->bufferMinutes > $s) {
                    $blocked[$id] = max($blocked[$id] ?? 0, $interval['f'] + $this->bufferMinutes);
                }
            }
        }

        return $blocked;
    }

    /**
     * Soft cost of a slot: confirmed students with less than $gapMinutes between
     * bookings, and any overlap that involves a possible (not yet known) participant.
     * Possible overlaps inside the same event are ignored: a bracket already keeps
     * one player out of two matches of the same round (e.g. Final and 3rd place).
     *
     * @param list<int> $studentIds confirmed participants of the candidate booking
     * @param list<int> $possibleIds possible participants of the candidate booking
     */
    public function softPenalty(array $studentIds, array $possibleIds, string $date, string|int $start, string|int $finish, int $gapMinutes, int $eventId = 0): int
    {
        $s = self::toMinutes($start);
        $f = self::toMinutes($finish);
        $penalty = 0;
        foreach (self::ids($studentIds) as $id) {
            foreach ($this->students[$id][$date] ?? [] as $interval) {
                if ($interval['s'] < $f + $gapMinutes && $interval['f'] + $gapMinutes > $s) {
                    $penalty++;
                }
            }
            foreach ($this->possible[$id][$date] ?? [] as $interval) {
                if ($interval['s'] < $f && $interval['f'] > $s && ($eventId === 0 || $interval['event'] !== $eventId)) {
                    $penalty++;
                }
            }
        }
        foreach (self::ids($possibleIds) as $id) {
            foreach (array_merge($this->students[$id][$date] ?? [], $this->possible[$id][$date] ?? []) as $interval) {
                if ($interval['s'] < $f && $interval['f'] > $s && ($eventId === 0 || $interval['event'] !== $eventId)) {
                    $penalty++;
                }
            }
        }

        return $penalty;
    }

    /**
     * @return list<array{s:int,f:int,key:string,event:int}>
     */
    public function studentIntervals(int $studentId, string $date): array
    {
        return $this->students[$studentId][$date] ?? [];
    }

    /**
     * Minutes each date has at least one session running in a room, summed over rooms
     * (parallel lanes of one room count once).
     *
     * @return array<string, int> date => busy room-minutes
     */
    public function busyMinutesByDate(): array
    {
        $busy = [];
        foreach ($this->rooms as $byDate) {
            foreach ($byDate as $date => $sessions) {
                $intervals = array_values($sessions);
                usort($intervals, fn ($a, $b) => $a['s'] <=> $b['s']);
                $runStart = null;
                $runEnd = null;
                $minutes = 0;
                foreach ($intervals as $interval) {
                    if ($runEnd === null || $interval['s'] > $runEnd) {
                        $minutes += $runEnd === null ? 0 : $runEnd - $runStart;
                        $runStart = $interval['s'];
                        $runEnd = $interval['f'];
                    } else {
                        $runEnd = max($runEnd, $interval['f']);
                    }
                }
                $minutes += $runEnd === null ? 0 : $runEnd - $runStart;
                $busy[$date] = ($busy[$date] ?? 0) + $minutes;
            }
        }
        ksort($busy);

        return $busy;
    }

    /**
     * Number of different events that have at least one room session.
     */
    public function eventCount(): int
    {
        $events = [];
        foreach ($this->rooms as $byDate) {
            foreach ($byDate as $sessions) {
                foreach ($sessions as $session) {
                    $events[$session['event']] = true;
                }
            }
        }

        return count($events);
    }

    /**
     * Consecutive bookings of the given students that start less than $gapMinutes
     * after the previous one finished (back-to-back bookings).
     *
     * @param list<int> $studentIds
     */
    public function tightGaps(array $studentIds, int $gapMinutes): int
    {
        $count = 0;
        foreach (self::ids($studentIds) as $id) {
            foreach ($this->students[$id] ?? [] as $intervals) {
                $unique = [];
                foreach ($intervals as $interval) {
                    $unique[$interval['key']] = $interval;
                }
                $list = array_values($unique);
                usort($list, fn ($a, $b) => $a['s'] <=> $b['s']);
                for ($i = 1; $i < count($list); $i++) {
                    $gap = $list[$i]['s'] - $list[$i - 1]['f'];
                    if ($gap >= 0 && $gap < $gapMinutes) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Highest number of sessions running at the same moment inside [s, f).
     *
     * @param list<array{s:int,f:int}> $sessions
     */
    private static function maxConcurrent(array $sessions, int $s, int $f): int
    {
        $points = [];
        foreach ($sessions as $session) {
            $points[] = [max($s, $session['s']), 1];
            $points[] = [min($f, $session['f']), -1];
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

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
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
