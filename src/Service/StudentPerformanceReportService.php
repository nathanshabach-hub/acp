<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\ORM\Locator\LocatorInterface;
use Cake\ORM\Query\SelectQuery;

final class StudentPerformanceReportService
{
    public function __construct(private LocatorInterface $tables)
    {
    }

    public static function positiveId(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/^[1-9][0-9]*$/D', (string)$value)
            || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new BadRequestException('Invalid report identifier or page.');
        }
        return (int)$value;
    }

    public function students(int $schoolId, string $search = '', int $page = 1, int $limit = 25): array
    {
        self::positiveId($schoolId);
        self::positiveId($page);
        if ($limit < 1 || $limit > 100 || !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 100) {
            throw new BadRequestException('Invalid report search or page size.');
        }
        $query = $this->studentQuery($schoolId);
        if ($search !== '') {
            $query->where(function ($exp, $q) use ($search) {
                $name = $exp->and([]);
                foreach (preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $term) {
                    $part = $exp->or([]);
                    foreach (['first_name', 'middle_name', 'last_name'] as $field) {
                        $part->gt($q->func()->locate([$term, 'Users.' . $field => 'identifier']), 0);
                    }
                    $name->add($part);
                }
                if (ctype_digit($search) && filter_var($search, FILTER_VALIDATE_INT) !== false) {
                    return $exp->or([$name, ['Users.id' => (int)$search]]);
                }
                return $name;
            });
        }
        $total = $query->count();
        $pages = max(1, (int)ceil($total / $limit));
        if ($page > $pages) {
            throw new NotFoundException('Report page not found.');
        }
        $students = $query->select(['Users.id', 'Users.first_name', 'Users.middle_name', 'Users.last_name'])
            ->orderBy(['Users.first_name' => 'ASC', 'Users.last_name' => 'ASC', 'Users.id' => 'ASC'])
            ->limit($limit)->page($page)->enableHydration(false)->all()->toArray();
        $ids = array_column($students, 'id');
        $histories = $ids ? $this->histories($schoolId, $ids) : [];
        $entries = $ids ? $this->entries($schoolId, $ids) : [];
        foreach ($students as &$student) {
            $conventions = [];
            foreach ($histories as $history) {
                if ((int)$history['student_id'] === (int)$student['id']) {
                    $conventions[(int)$history['conventionseason_id']] = true;
                }
            }
            $events = [];
            foreach ($entries as $entry) {
                if ((int)$entry['student_id'] === (int)$student['id']
                    && isset($conventions[(int)$entry['conventionseason_id']])) {
                    $events[$entry['conventionseason_id'] . ':' . $entry['event_id']] = true;
                }
            }
            $student['conventions'] = count($conventions);
            $student['events'] = count($events);
        }
        return compact('students', 'total', 'page', 'pages', 'limit', 'search');
    }

    public function preview(int $schoolId, int $studentId): array
    {
        self::positiveId($schoolId);
        self::positiveId($studentId);
        $student = $this->studentQuery($schoolId)->where(['Users.id' => $studentId])
            ->select(['Users.id', 'Users.first_name', 'Users.middle_name', 'Users.last_name'])
            ->enableHydration(false)->first();
        if (!$student) {
            throw new NotFoundException('Student report not found.');
        }
        $history = $this->histories($schoolId, [$studentId]);
        $entries = $this->entries($schoolId, [$studentId]);
        $released = array_values(array_unique(array_column(array_filter(
            $history, static fn($row) => (int)$row['results_release'] === 1
        ), 'conventionseason_id')));
        $positions = $released ? $this->positions($schoolId, $student, $released, $entries) : [];
        return $this->assemble($student, $history, $entries, $positions);
    }

    private function studentQuery(int $schoolId): SelectQuery
    {
        $registrations = $this->tables->get('Conventionregistrationstudents')->find()
            ->select(['Conventionregistrationstudents.student_id'])
            ->innerJoin(['Registration' => 'conventionregistrations'], [
                'Registration.id = Conventionregistrationstudents.conventionregistration_id',
                'Registration.user_id' => $schoolId,
            ])
            ->where(['Conventionregistrationstudents.user_id' => $schoolId]);
        return $this->tables->get('Users')->find()->where([
            'Users.school_id' => $schoolId, 'Users.user_type' => 'Student',
            'Users.id IN' => $registrations,
        ]);
    }

    private function histories(int $schoolId, array $studentIds): array
    {
        return $this->tables->get('Conventionregistrationstudents')->find()->select([
            'student_id' => 'Conventionregistrationstudents.student_id',
            'conventionseason_id' => 'Registration.conventionseason_id',
            'year' => 'Season.season_year', 'name' => 'Convention.name',
            'results_release' => 'Season.results_release',
            'registration_date' => 'Season.registration_start_date',
        ])->innerJoin(['Registration' => 'conventionregistrations'], [
            'Registration.id = Conventionregistrationstudents.conventionregistration_id',
            'Registration.user_id' => $schoolId,
            'Registration.convention_id = Conventionregistrationstudents.convention_id',
            'Registration.season_id = Conventionregistrationstudents.season_id',
            'Registration.season_year = Conventionregistrationstudents.season_year',
        ])->innerJoin(['Season' => 'conventionseasons'], [
            'Season.id = Registration.conventionseason_id',
            'Season.convention_id = Registration.convention_id',
            'Season.season_id = Registration.season_id',
            'Season.season_year = Registration.season_year',
        ])->innerJoin(['Convention' => 'conventions'], ['Convention.id = Season.convention_id'])
            ->where(['Conventionregistrationstudents.user_id' => $schoolId,
                'Conventionregistrationstudents.student_id IN' => $studentIds])
            ->distinct()->orderBy(['Season.season_year' => 'ASC',
                'Season.registration_start_date' => 'ASC', 'Registration.conventionseason_id' => 'ASC'])
            ->enableHydration(false)->all()->toArray();
    }

    private function entries(int $schoolId, array $studentIds): array
    {
        return $this->tables->get('Crstudentevents')->find()
            ->select(['Crstudentevents.student_id', 'Crstudentevents.conventionseason_id',
                'Crstudentevents.event_id', 'Crstudentevents.group_name',
                'Crstudentevents.conventionregistration_id'])
            ->innerJoin(['Registration' => 'conventionregistrations'], [
                'Registration.id = Crstudentevents.conventionregistration_id',
                'Registration.user_id' => $schoolId,
                'Registration.conventionseason_id = Crstudentevents.conventionseason_id',
                'Registration.convention_id = Crstudentevents.convention_id',
                'Registration.season_id = Crstudentevents.season_id',
                'Registration.season_year = Crstudentevents.season_year',
            ])->innerJoin(['StudentRegistration' => 'conventionregistrationstudents'], [
                'StudentRegistration.conventionregistration_id = Registration.id',
                'StudentRegistration.student_id = Crstudentevents.student_id',
                'StudentRegistration.user_id' => $schoolId,
            ])->where(['Crstudentevents.user_id' => $schoolId,
                'Crstudentevents.student_id IN' => $studentIds, 'Crstudentevents.event_id >' => 0])
            ->distinct()->enableHydration(false)->all()->toArray();
    }

    private function positions(int $schoolId, array $student, array $released, array $entries): array
    {
        $registrations = $this->tables->get('Conventionregistrationstudents')->find()
            ->select(['Conventionregistrationstudents.conventionregistration_id'])->where([
                'Conventionregistrationstudents.user_id' => $schoolId,
                'Conventionregistrationstudents.student_id' => $student['id'],
                'Conventionregistrationstudents.convention_id = Resultpositions.convention_id',
                'Conventionregistrationstudents.season_id = Resultpositions.season_id',
                'Conventionregistrationstudents.season_year = Resultpositions.season_year',
            ]);
        $targets = [[
            'Resultpositions.user_id' => $schoolId,
            'Resultpositions.student_id' => $student['id'],
            'Resultpositions.conventionregistration_id IN' => $registrations,
        ]];
        foreach ($entries as $entry) {
            if (in_array($entry['conventionseason_id'], $released)) {
                $targets[] = [
                    'Resultpositions.conventionregistration_id' => $entry['conventionregistration_id'],
                    'Resultpositions.conventionseason_id' => $entry['conventionseason_id'],
                    'Resultpositions.event_id' => $entry['event_id'],
                    'Resultpositions.user_id' => $schoolId,
                    'OR' => [
                        ['Resultpositions.student_id' => $student['id']],
                        ['Resultpositions.student_id IN' => [0], 'Resultpositions.group_name' => (string)$entry['group_name'],
                            'Resultpositions.group_name !=' => ''],
                        ['Resultpositions.student_id IS' => null, 'Resultpositions.group_name' => (string)$entry['group_name'],
                            'Resultpositions.group_name !=' => ''],
                    ],
                ];
            }
        }
        $combined = $this->combinedTargets($schoolId, $student, $released, $entries);
        $targets = array_merge($targets, $combined);
        return $this->tables->get('Resultpositions')->find()->select([
            'Resultpositions.conventionseason_id', 'Resultpositions.event_id', 'Resultpositions.position',
            'event_name' => 'Event.event_name',
        ])->innerJoin(['Season' => 'conventionseasons'], [
            'Season.id = Resultpositions.conventionseason_id', 'Season.results_release' => 1,
            'Season.convention_id = Resultpositions.convention_id',
            'Season.season_id = Resultpositions.season_id',
            'Season.season_year = Resultpositions.season_year',
        ])->innerJoin(['Header' => 'results'], [
            'Header.id = Resultpositions.result_id',
            'Header.conventionseason_id = Resultpositions.conventionseason_id',
            'Header.convention_id = Resultpositions.convention_id',
            'Header.season_id = Resultpositions.season_id',
            'Header.season_year = Resultpositions.season_year',
            'Header.event_id = Resultpositions.event_id',
        ])->innerJoin(['Registration' => 'conventionregistrations'], [
            'Registration.id = Resultpositions.conventionregistration_id',
            'Registration.user_id = Resultpositions.user_id',
            'Registration.conventionseason_id = Resultpositions.conventionseason_id',
            'Registration.convention_id = Resultpositions.convention_id',
            'Registration.season_id = Resultpositions.season_id',
            'Registration.season_year = Resultpositions.season_year',
        ])->innerJoin(['Event' => 'events'], ['Event.id = Resultpositions.event_id'])
            ->where(['Resultpositions.conventionseason_id IN' => $released, 'OR' => $targets])
            ->enableHydration(false)->all()->toArray();
    }

    private function combinedTargets(int $schoolId, array $student, array $released, array $entries): array
    {
        $db = $this->tables->get('Combinerequests')->getConnection();
        $teams = new CombinedTeams($db);
        $legacyConditions = [];
        $targets = [];
        if ($teams->available()) {
            $legacyConditions['Combinerequests.combined_team_id IS'] = null;
            $managed = $db->execute(
                'SELECT t.*, r.user_id AS source_school_id FROM combined_team_members m
                 JOIN combined_teams t ON t.id = m.team_id AND t.status = 1
                 JOIN conventionregistrations own ON own.id = m.registration_id AND own.user_id = ?
                 JOIN conventionregistrations r ON r.id = t.source_registration_id
                 WHERE m.student_id = ?', [$schoolId, $student['id']]
            )->fetchAll('assoc');
            foreach ($managed as $team) {
                if (in_array((int)$team['conventionseason_id'], array_map('intval', $released), true)) {
                    $targets[] = [
                        'Resultpositions.user_id' => $team['source_school_id'],
                        'Resultpositions.conventionregistration_id' => $team['source_registration_id'],
                        'Resultpositions.conventionseason_id' => $team['conventionseason_id'],
                        'Resultpositions.event_id' => $team['event_id'],
                        'Resultpositions.group_name' => $team['group_name'],
                        'OR' => [['Resultpositions.student_id' => 0], ['Resultpositions.student_id IS' => null]],
                    ];
                }
            }
        }
        $requests = $this->tables->get('Combinerequests')->find()->select([
            'Combinerequests.conventionseason_id', 'Combinerequests.event_id',
            'Combinerequests.combine_with_user_id', 'Combinerequests.student_name',
            'Combinerequests.conventionregistration_id',
        ])->innerJoin(['SourceRegistration' => 'conventionregistrations'], [
            'SourceRegistration.id = Combinerequests.conventionregistration_id',
            'SourceRegistration.user_id' => $schoolId,
            'SourceRegistration.conventionseason_id = Combinerequests.conventionseason_id',
            'SourceRegistration.convention_id = Combinerequests.convention_id',
            'SourceRegistration.season_id = Combinerequests.season_id',
            'SourceRegistration.season_year = Combinerequests.season_year',
        ])->where($legacyConditions)->where(['Combinerequests.user_id' => $schoolId, 'Combinerequests.status' => 1,
            'Combinerequests.conventionseason_id IN' => $released])->enableHydration(false)->all();
        $name = self::normalizedName(implode(' ', [$student['first_name'], $student['middle_name'], $student['last_name']]));
        foreach ($requests as $request) {
            if (self::normalizedName((string)$request['student_name']) !== $name || $name === '') {
                continue;
            }
            foreach ($entries as $entry) {
                if ((int)$entry['event_id'] !== (int)$request['event_id']
                    || (int)$entry['conventionseason_id'] !== (int)$request['conventionseason_id']
                    || (int)$entry['conventionregistration_id'] !== (int)$request['conventionregistration_id']
                    || trim((string)$entry['group_name']) === '') {
                    continue;
                }
                // A legacy approval names a student. Require an unambiguous exact
                // name plus stable-ID membership at both schools; never fuzzy match.
                $candidates = $this->tables->get('Crstudentevents')->find()->select([
                    'Crstudentevents.student_id', 'first_name' => 'Student.first_name',
                    'middle_name' => 'Student.middle_name', 'last_name' => 'Student.last_name',
                ])->innerJoin(['Student' => 'users'], [
                    'Student.id = Crstudentevents.student_id', 'Student.school_id' => $schoolId,
                    'Student.user_type' => 'Student',
                ])->where(['Crstudentevents.conventionregistration_id' => $entry['conventionregistration_id'],
                    'Crstudentevents.user_id' => $schoolId, 'Crstudentevents.event_id' => $entry['event_id'],
                    'Crstudentevents.conventionseason_id' => $entry['conventionseason_id']])
                    ->enableHydration(false)->all();
                $matched = [];
                foreach ($candidates as $candidate) {
                    if (self::normalizedName(implode(' ', [$candidate['first_name'], $candidate['middle_name'], $candidate['last_name']])) === $name) {
                        $matched[(int)$candidate['student_id']] = true;
                    }
                }
                if (array_keys($matched) !== [(int)$student['id']]) {
                    continue;
                }
                $members = $this->tables->get('Crstudentevents')->find()
                    ->select(['Crstudentevents.conventionregistration_id'])
                    ->innerJoin(['Host' => 'conventionregistrations'], [
                        'Host.id = Crstudentevents.conventionregistration_id',
                        'Host.user_id = Crstudentevents.user_id',
                        'Host.conventionseason_id = Crstudentevents.conventionseason_id',
                        'Host.convention_id = Crstudentevents.convention_id',
                        'Host.season_id = Crstudentevents.season_id',
                        'Host.season_year = Crstudentevents.season_year',
                    ])->where(['Crstudentevents.user_id' => $request['combine_with_user_id'],
                        'Crstudentevents.student_id' => $student['id'],
                        'Crstudentevents.event_id' => $entry['event_id'],
                        'Crstudentevents.conventionseason_id' => $entry['conventionseason_id'],
                        'Crstudentevents.group_name' => $entry['group_name']])
                    ->enableHydration(false)->all();
                foreach ($members as $member) {
                    $targets[] = [
                        'Resultpositions.user_id' => $request['combine_with_user_id'],
                        'Resultpositions.conventionregistration_id' => $member['conventionregistration_id'],
                        'Resultpositions.conventionseason_id' => $entry['conventionseason_id'],
                        'Resultpositions.event_id' => $entry['event_id'],
                        'Resultpositions.group_name' => $entry['group_name'],
                        'OR' => [['Resultpositions.student_id' => 0], ['Resultpositions.student_id IS' => null]],
                    ];
                }
            }
        }
        return $targets;
    }

    private static function normalizedName(string $name): string
    {
        return mb_strtolower((string)preg_replace('/\s+/', ' ', trim($name)));
    }

    private function assemble(array $student, array $history, array $entries, array $positions): array
    {
        $conventions = [];
        foreach ($history as $row) {
            $id = (int)$row['conventionseason_id'];
            $conventions[$id] = [
                'id' => $id, 'name' => $row['name'], 'year' => (int)$row['year'],
                'released' => (int)$row['results_release'] === 1, 'events' => 0,
                'placements' => array_fill(1, 6, 0), 'awards' => [], 'conflicts' => 0, 'awards_without_entries' => 0,
            ];
        }
        $eventKeys = [];
        foreach ($entries as $entry) {
            $id = (int)$entry['conventionseason_id'];
            if (isset($conventions[$id]) && (int)$entry['student_id'] === (int)$student['id']) {
                $eventKeys[$id][(int)$entry['event_id']] = true;
            }
        }
        $awardKeys = [];
        foreach ($positions as $position) {
            $id = (int)$position['conventionseason_id'];
            if (!isset($conventions[$id]) || !$conventions[$id]['released']) {
                continue;
            }
            $event = (int)$position['event_id'];
            $place = $position['position'] === null ? 'unset' : (string)(int)$position['position'];
            $awardKeys[$id][$event]['places'][$place] = true;
            $awardKeys[$id][$event]['name'] = $position['event_name'];
        }
        $totals = ['conventions' => count($conventions), 'events' => 0,
            'placements' => array_fill(1, 6, 0), 'conflicts' => 0, 'automatic_awards' => 0,
            'awards_without_entries' => 0];
        foreach ($conventions as $id => &$convention) {
            $convention['events'] = count($eventKeys[$id] ?? []);
            $totals['events'] += $convention['events'];
            foreach ($awardKeys[$id] ?? [] as $event => $award) {
                if (count($award['places']) !== 1) {
                    $convention['conflicts']++;
                    $totals['conflicts']++;
                    continue;
                }
                $place = (int)array_key_first($award['places']);
                if ($place < 1 || $place > 6) {
                    continue;
                }
                $automatic = (bool)preg_match('/^(golden (apple|harp|lamb)|christian (soldier|worker)|silver apple) award (u16|open)$/i', trim($award['name']));
                $convention['placements'][$place]++;
                $totals['placements'][$place]++;
                $totals['automatic_awards'] += (int)$automatic;
                if (!isset($eventKeys[$id][$event])) {
                    $convention['awards_without_entries']++;
                    $totals['awards_without_entries']++;
                }
                $convention['awards'][] = ['event' => $award['name'], 'position' => $place, 'automatic' => $automatic];
            }
            if (!$convention['released']) {
                $convention['placements'] = null;
            }
        }
        return ['student' => $student, 'conventions' => array_values($conventions), 'totals' => $totals];
    }
}
