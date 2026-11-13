<?php
declare(strict_types=1);

namespace App\Scheduling;

use Cake\Database\Connection;

/**
 * Reads one season's timetable and scheduling rules from the database into the plain
 * arrays used by TimetableValidator and ConflictRepairPlanner. Read-only.
 *
 * The rules mirror timetable generation: rooms come from the season room/event mapping
 * (Category 1 falls back to every season room when an event has none), Category 2/3
 * rooms offer number_games lanes, Category 1/4 rooms one lane, a pinned room replaces
 * the mapping, and team rows are resolved to every member of the team.
 */
final class TimetableLoader
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array{season: array, scheduling: ?array, context: array, bookings: array<int,array>, rows: array<int,array>}
     */
    public function load(int $seasonId): array
    {
        $season = $this->db->execute('SELECT * FROM conventionseasons WHERE id = ?', [$seasonId])->fetch('assoc') ?: [];
        $scheduling = $this->schedulingRecord($season);
        $rows = [];
        foreach ($this->db->execute(
            'SELECT * FROM schedulingtimings WHERE conventionseasons_id = ? AND convention_id = ? AND season_id = ? AND season_year = ? ORDER BY id',
            [$seasonId, $season['convention_id'] ?? 0, $season['season_id'] ?? 0, $season['season_year'] ?? 0]
        )->fetchAll('assoc') as $row) {
            $rows[(int)$row['id']] = $row;
        }

        $context = $this->context($seasonId, $season, $scheduling, $rows);
        $members = $this->groupMembers($seasonId);
        $people = [];
        $bookings = [];
        $knockoutRows = [];
        foreach ($rows as $id => $row) {
            $students = $this->rowStudents($row, $members);
            $category = (int)$row['schedule_category'];
            $placed = !empty($row['start_time']) && !empty($row['finish_time']) && !empty($row['sch_date_time']);
            $bookings[$id] = [
                'id' => $id,
                'category' => $category,
                'event_id' => (int)$row['event_id'],
                'room_id' => (int)$row['room_id'],
                'date' => $placed ? date('Y-m-d', strtotime((string)$row['sch_date_time'])) : null,
                'start' => $placed ? TimetableLedger::toMinutes((string)$row['start_time']) : null,
                'finish' => $placed ? TimetableLedger::toMinutes((string)$row['finish_time']) : null,
                'bye' => (int)($row['is_bye'] ?? 0) === 1,
                'round' => (int)($row['round_number'] ?? 0),
                'match' => (int)($row['match_number'] ?? 0),
                'feeders' => array_values(array_filter([(int)($row['schtimeautoid1'] ?? 0), (int)($row['schtimeautoid2'] ?? 0)])),
                'students' => $students,
                'possible' => [],
                'capacity' => null,
                'label' => '',
                'modified' => (string)($row['modified'] ?? ''),
            ];
            foreach (['user_id', 'user_id_opponent'] as $field) {
                if ((int)($row[$field] ?? 0) > 0) {
                    $people[(int)$row[$field]] = true;
                }
            }
            if (in_array($category, [2, 3], true)) {
                $knockoutRows[$category.'-'.(int)$row['event_id']][$id] = [
                    'bye' => $bookings[$id]['bye'],
                    'feeders' => $bookings[$id]['feeders'],
                    'players' => (int)($row['round_number'] ?? 0) <= 1 ? $students : [],
                ];
            }
        }

        // Later knockout rounds: confirmed players only through byes, the rest are possible players.
        foreach ($knockoutRows as $eventRows) {
            $roundMatches = [];
            foreach ($eventRows as $id => $knockoutRow) {
                if (!$knockoutRow['bye']) {
                    $roundMatches[$bookings[$id]['round']] = ($roundMatches[$bookings[$id]['round']] ?? 0) + 1;
                }
            }
            foreach (SlotPlanner::knockoutParticipants($eventRows) as $id => $participants) {
                $bookings[$id]['students'] = $participants['confirmed'];
                $bookings[$id]['possible'] = $participants['possible'];
                if (self::singleBoardRound($context['events'][$bookings[$id]['event_id']]['name'] ?? '', (int)($roundMatches[$bookings[$id]['round']] ?? 0))) {
                    $bookings[$id]['capacity'] = 1;
                }
            }
        }

        $names = $this->userNames(array_keys($people));
        foreach ($rows as $id => $row) {
            $bookings[$id]['label'] = $this->label($row, $names);
        }
        $context['people'] = $names;

        return ['season' => $season, 'scheduling' => $scheduling, 'context' => $context, 'bookings' => $bookings, 'rows' => $rows];
    }

    /**
     * Hash of every timing field of the season, used to detect edits between preview and apply.
     */
    public static function timingHash(array $rows): string
    {
        $parts = [];
        foreach ($rows as $id => $row) {
            $parts[] = $id.'|'.implode('|', array_map(fn ($field) => (string)($row[$field] ?? ''), ScheduleRepairStore::TIMING_FIELDS));
        }

        return sha1(implode("\n", $parts));
    }

    /**
     * Chess/checkers U16 and Open finals rounds (4 matches or fewer) use one board.
     */
    public static function singleBoardRound(string $eventName, int $roundMatchCount): bool
    {
        if ($roundMatchCount <= 0 || $roundMatchCount > 4) {
            return false;
        }
        $name = strtolower($eventName);

        return (str_contains($name, 'checkers') || str_contains($name, 'chess')) && (str_contains($name, 'u16') || str_contains($name, 'open'));
    }

    private function schedulingRecord(array $season): ?array
    {
        if (!$season) {
            return null;
        }
        $records = $this->db->execute(
            'SELECT * FROM schedulings WHERE conventionseasons_id = ? AND convention_id = ? AND season_id = ? AND season_year = ? ORDER BY modified DESC, id DESC',
            [$season['id'], $season['convention_id'], $season['season_id'], $season['season_year']]
        )->fetchAll('assoc');
        foreach ($records as $record) {
            if (self::time($record['normal_starting_time']) !== null && self::time($record['normal_finish_time']) !== null
                && self::time($record['lunch_time_start']) !== null && self::time($record['lunch_time_end']) !== null) {
                return $record;
            }
        }

        return $records[0] ?? null;
    }

    private function context(int $seasonId, array $season, ?array $scheduling, array $rows): array
    {
        $schedulingData = $scheduling ?? [];
        $dates = [];
        if (!empty($schedulingData['start_date'])) {
            $start = date('Y-m-d', strtotime((string)$schedulingData['start_date']));
            $firstDay = (int)($schedulingData['starting_different_time_first_day_yes_no'] ?? 0) === 1;
            for ($index = 0; $index < max(1, (int)$schedulingData['number_of_days']); $index++) {
                $date = date('Y-m-d', strtotime($start.' +'.$index.' day'));
                $dayStart = $index === 0 && $firstDay ? self::time($schedulingData['different_first_day_start_time']) : self::time($schedulingData['normal_starting_time']);
                $dayEnd = $index === 0 && $firstDay ? self::time($schedulingData['different_first_day_end_time']) : self::time($schedulingData['normal_finish_time']);
                if ($dayStart !== null && $dayEnd !== null && $dayEnd > $dayStart) {
                    $dates[$date] = ['day' => date('l', strtotime($date)), 'start' => $dayStart, 'end' => $dayEnd];
                }
            }
        }

        $settings = [];
        foreach ([
            'lunch_time_start', 'lunch_time_end',
            'judging_breaks_morning_break_starting_time', 'judging_breaks_morning_break_finish_time',
            'judging_breaks_afternoon_break_start_time', 'judging_breaks_afternoon_break_finish_time',
            'sports_day_starting_time', 'sports_day_finish_time',
            'sports_day_other_starting_time', 'sports_day_other_finish_time',
        ] as $field) {
            $minutes = self::time($schedulingData[$field] ?? null);
            $settings[$field] = $minutes === null ? null : TimetableLedger::toTime($minutes);
        }
        foreach (['judging_breaks_yes_no', 'sports_day_yes_no', 'sports_day_having_events_after_sport_yes_no'] as $field) {
            $settings[$field] = (int)($schedulingData[$field] ?? 0);
        }
        $settings['sports_day'] = $schedulingData['sports_day'] ?? null;
        $dateList = [];
        foreach ($dates as $date => $day) {
            $dateList[] = ['date' => $date, 'day' => $day['day']];
        }
        $blocked = SlotPlanner::labelledBlockedPeriods($settings, $dateList);

        $categoryOfEvent = [];
        foreach ($rows as $row) {
            $categoryOfEvent[(int)$row['event_id']] = (int)$row['schedule_category'];
        }

        $events = [];
        if ($categoryOfEvent) {
            $ids = implode(',', array_map('intval', array_keys($categoryOfEvent)));
            foreach ($this->db->execute("SELECT id, event_id_number, event_name, setup_time, round_time, judging_time FROM events WHERE id IN ($ids)")->fetchAll('assoc') as $event) {
                $events[(int)$event['id']] = [
                    'number' => (string)$event['event_id_number'],
                    'name' => (string)$event['event_name'],
                    'duration' => (int)$event['setup_time'] + (int)$event['round_time'] + (int)$event['judging_time'],
                    'category' => $categoryOfEvent[(int)$event['id']],
                ];
            }
        }

        $mapping = [];
        $seasonRooms = [];
        foreach ($this->db->execute(
            'SELECT room_id, event_ids, number_games FROM conventionseasonroomevents WHERE conventionseasons_id = ? AND convention_id = ? AND season_id = ? AND season_year = ?',
            [$seasonId, $season['convention_id'] ?? 0, $season['season_id'] ?? 0, $season['season_year'] ?? 0]
        )->fetchAll('assoc') as $roomEvent) {
            $roomId = (int)$roomEvent['room_id'];
            if ($roomId <= 0) {
                continue;
            }
            $seasonRooms[$roomId] = 1;
            foreach (explode(',', (string)$roomEvent['event_ids']) as $eventId) {
                $eventId = (int)trim($eventId);
                if ($eventId > 0) {
                    $mapping[$eventId][$roomId] = ($mapping[$eventId][$roomId] ?? 0) + max(1, (int)$roomEvent['number_games']);
                }
            }
        }

        $pinned = [];
        $eventWindows = [];
        $tweakRooms = [];
        foreach ($this->db->execute('SELECT * FROM schedulingeventtweaks WHERE conventionseasons_id = ?', [$seasonId])->fetchAll('assoc') as $tweak) {
            $eventId = (int)$tweak['event_id'];
            $pinnedDate = null;
            if (!empty($tweak['pinned_day'])) {
                foreach ($dates as $date => $day) {
                    if ($day['day'] === trim((string)$tweak['pinned_day'])) {
                        $pinnedDate = $date;
                        break;
                    }
                }
            }
            $entry = array_filter([
                'room' => (int)$tweak['pinned_room_id'] ?: null,
                'date' => $pinnedDate,
                'start' => self::time($tweak['pinned_start_time']),
            ], fn ($value) => $value !== null);
            if ($entry) {
                $pinned[$eventId] = $entry;
            }
            if (!empty($tweak['pinned_room_id'])) {
                $tweakRooms[$eventId] = (int)$tweak['pinned_room_id'];
            }
            $window = array_filter(['from' => self::time($tweak['available_from_time']), 'to' => self::time($tweak['available_to_time'])], fn ($value) => $value !== null);
            if ($window) {
                $eventWindows[$eventId] = $window;
            }
        }

        $eventRooms = [];
        foreach ($categoryOfEvent as $eventId => $category) {
            if (isset($tweakRooms[$eventId])) {
                $eventRooms[$eventId] = [$tweakRooms[$eventId] => 1];
                continue;
            }
            $rooms = $mapping[$eventId] ?? [];
            if (!$rooms && $category === 1) {
                $rooms = $seasonRooms;
            }
            if (in_array($category, [1, 4], true)) {
                $rooms = array_fill_keys(array_keys($rooms), 1);
            }
            ksort($rooms);
            $eventRooms[$eventId] = $rooms;
        }

        $rooms = [];
        $roomWindows = [];
        foreach ($this->db->execute('SELECT id, room_name, available_from, available_to FROM conventionrooms WHERE convention_id = ?', [$season['convention_id'] ?? 0])->fetchAll('assoc') as $room) {
            $rooms[(int)$room['id']] = (string)$room['room_name'];
            $window = array_filter(['from' => self::time($room['available_from']), 'to' => self::time($room['available_to'])], fn ($value) => $value !== null);
            if ($window) {
                $roomWindows[(int)$room['id']] = $window;
            }
        }

        return [
            'dates' => $dates,
            'blocked' => $blocked,
            'event_windows' => $eventWindows,
            'room_windows' => $roomWindows,
            'pinned' => $pinned,
            'event_rooms' => $eventRooms,
            'events' => $events,
            'rooms' => $rooms,
            'buffer' => 0,
            'preferred_gap' => 5,
        ];
    }

    /**
     * @return array<int, array<int, array<string, list<int>>>> school => event => group => students
     */
    private function groupMembers(int $seasonId): array
    {
        $members = [];
        foreach ($this->db->execute('SELECT user_id, event_id, group_name, student_id FROM crstudentevents WHERE conventionseason_id = ? AND student_id > 0 ORDER BY id', [$seasonId])->fetchAll('assoc') as $member) {
            $group = trim((string)$member['group_name']);
            $members[(int)$member['user_id']][(int)$member['event_id']][$group === '' ? 'Ungrouped' : $group][] = (int)$member['student_id'];
        }

        return $members;
    }

    /**
     * Same participants as the generator: students on the row, or every member of a school team.
     *
     * @return list<int>
     */
    private function rowStudents(array $row, array $members): array
    {
        $ids = [];
        $isSchool = (string)($row['user_type'] ?? '') === 'School';
        if (!$isSchool) {
            foreach (['user_id', 'user_id_opponent'] as $field) {
                if ((int)($row[$field] ?? 0) > 0) {
                    $ids[] = (int)$row[$field];
                }
            }
        }
        foreach (['group_name_user_ids', 'group_name_opponent_user_ids'] as $field) {
            foreach (explode(',', (string)($row[$field] ?? '')) as $id) {
                if ((int)trim($id) > 0) {
                    $ids[] = (int)trim($id);
                }
            }
        }
        if ($isSchool) {
            foreach ([['user_id', 'group_name'], ['user_id_opponent', 'group_name_opponent']] as [$schoolField, $groupField]) {
                if ((int)($row[$schoolField] ?? 0) > 0) {
                    $group = trim((string)($row[$groupField] ?? ''));
                    $ids = array_merge($ids, $members[(int)$row[$schoolField]][(int)$row['event_id']][$group === '' ? 'Ungrouped' : $group] ?? []);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function userNames(array $ids): array
    {
        $names = [];
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            foreach ($this->db->execute('SELECT id, first_name, last_name FROM users WHERE id IN ('.implode(',', $chunk).')')->fetchAll('assoc') as $user) {
                $names[(int)$user['id']] = trim($user['first_name'].' '.$user['last_name']);
            }
        }

        return $names;
    }

    private function label(array $row, array $names): string
    {
        $side = function (string $userField, string $groupField) use ($row, $names): string {
            $userId = (int)($row[$userField] ?? 0);
            if ($userId <= 0) {
                return '';
            }
            $name = $names[$userId] ?? 'user '.$userId;
            $group = trim((string)($row[$groupField] ?? ''));

            return (string)($row['user_type'] ?? '') === 'School' && $group !== '' ? $name.' '.$group : $name;
        };
        $own = $side('user_id', 'group_name');
        $opponent = $side('user_id_opponent', 'group_name_opponent');
        if ((int)($row['is_bye'] ?? 0) === 1) {
            return trim($own.' (bye)');
        }

        return $opponent !== '' ? $own.' vs '.$opponent : $own;
    }

    /**
     * Minutes of day for a time-ish value, or null when empty.
     */
    public static function time(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return (int)$value->format('H') * 60 + (int)$value->format('i');
        }
        $timestamp = strtotime((string)$value);

        return $timestamp === false ? null : (int)date('H', $timestamp) * 60 + (int)date('i', $timestamp);
    }
}
