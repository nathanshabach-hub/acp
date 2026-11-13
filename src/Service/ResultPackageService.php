<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\GuidelineBreachPolicy;
use Cake\ORM\Locator\LocatorInterface;
use RuntimeException;

final class ResultPackageService
{
    public function __construct(private LocatorInterface $tables)
    {
    }

    public function events(array $eventIds, int $schoolId, int $seasonId, int $registrationId): array
    {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        if (!$eventIds) {
            return [];
        }

        $positionsByEvent = [];
        $studentIds = [];
        $groupPositionIds = [];
        $submissionIds = [];
        $positions = $this->tables->get('Resultpositions')->find()
            ->where([
                'Resultpositions.user_id' => $schoolId,
                'Resultpositions.conventionseason_id' => $seasonId,
                'Resultpositions.event_id IN' => $eventIds,
                'Resultpositions.position >' => 0,
                'Resultpositions.position <=' => 6,
            ])
            ->orderBy(['Resultpositions.position' => 'ASC', 'Resultpositions.id' => 'ASC'])
            ->enableHydration(false)->all()->toList();
        $submissionIds = array_values(array_unique(array_filter(array_map(
            static fn(array $position): int => (int)($position['eventsubmission_id'] ?? 0),
            $positions
        ))));
        $acceptedSubmissionIds = [];
        if ($submissionIds) {
            $acceptedSubmissions = $this->tables->get('Eventsubmissions')->find()
                ->select(['id'])
                ->where([
                    'Eventsubmissions.id IN' => $submissionIds,
                    'Eventsubmissions.guideline_breach' => GuidelineBreachPolicy::STATUS_ACCEPTED,
                ])
                ->all();
            foreach ($acceptedSubmissions as $submission) {
                $acceptedSubmissionIds[(int)$submission->id] = true;
            }
        }

        foreach ($positions as $position) {
            if (isset($acceptedSubmissionIds[(int)($position['eventsubmission_id'] ?? 0)])) {
                continue;
            }
            $positionsByEvent[(int)$position['event_id']][] = $position;
            if ((int)$position['student_id'] > 0) {
                $studentIds[] = (int)$position['student_id'];
            } elseif (!empty($position['group_name'])) {
                $groupPositionIds[] = (int)$position['id'];
            }
            if (!empty($position['eventsubmission_id'])) {
                $submissionIds[] = (int)$position['eventsubmission_id'];
            }
        }

        $groupMembers = [];
        if ($groupPositionIds) {
            // Let the database compare legacy group names using its existing collation.
            $members = $this->tables->get('Crstudentevents')->find()
                ->select([
                    'position_id' => 'PackagePositions.id',
                    'student_id' => 'Crstudentevents.student_id',
                ])
                ->innerJoin(['PackagePositions' => 'resultpositions'], [
                    'PackagePositions.event_id = Crstudentevents.event_id',
                    'PackagePositions.group_name = Crstudentevents.group_name',
                    'PackagePositions.conventionseason_id = Crstudentevents.conventionseason_id',
                    'PackagePositions.user_id = Crstudentevents.user_id',
                ])
                ->where([
                    'PackagePositions.id IN' => $groupPositionIds,
                    'Crstudentevents.user_id' => $schoolId,
                    'Crstudentevents.conventionseason_id' => $seasonId,
                ])
                ->orderBy(['Crstudentevents.id' => 'ASC'])
                ->enableHydration(false)->all();
            foreach ($members as $member) {
                $groupMembers[(int)$member['position_id']][] = (int)$member['student_id'];
                $studentIds[] = (int)$member['student_id'];
            }
        }

        $students = [];
        if ($studentIds) {
            $rows = $this->tables->get('Users')->find()
                ->select(['Users.id', 'Users.first_name', 'Users.middle_name', 'Users.last_name', 'Users.school_id'])
                ->contain(['Schools' => ['fields' => ['Schools.id', 'Schools.first_name']]])
                ->where(['Users.id IN' => array_values(array_unique($studentIds))])
                ->enableHydration(false)->all();
            foreach ($rows as $student) {
                $students[(int)$student['id']] = $student;
            }
        }

        $submissionScope = [[
            'Eventsubmissions.conventionseason_id' => $seasonId,
            'Eventsubmissions.event_id IN' => $eventIds,
        ]];
        if ($submissionIds) {
            $submissionScope[] = ['Eventsubmissions.id IN' => array_values(array_unique($submissionIds))];
        }
        $submissions = $this->tables->get('Eventsubmissions')->find()
            ->select([
                'id', 'event_id', 'student_id', 'user_id', 'conventionseason_id',
                'conventionregistration_id', 'guideline_breach',
            ])
            ->where([
                'Eventsubmissions.command_performance' => 1,
                'AND' => [
                    ['OR' => $submissionScope],
                    ['OR' => [
                        ['Eventsubmissions.guideline_breach !=' => GuidelineBreachPolicy::STATUS_ACCEPTED],
                        ['Eventsubmissions.guideline_breach_level' => GuidelineBreachPolicy::LEVEL_MINOR],
                        ['Eventsubmissions.guideline_breach IS' => null],
                    ]],
                ],
            ])
            ->enableHydration(false)->all();
        $submissionCp = $eventCp = $schoolCp = $registrationCp = $studentCp = [];
        foreach ($submissions as $submission) {
            $submissionCp[(int)$submission['id']] = true;
            if ((int)$submission['conventionseason_id'] !== $seasonId) {
                continue;
            }
            $eventId = (int)$submission['event_id'];
            $sameSchool = (int)$submission['user_id'] === $schoolId;
            $sameRegistration = (int)$submission['conventionregistration_id'] === $registrationId;
            if ($sameSchool) {
                $schoolCp[$eventId] = true;
            }
            if ($sameRegistration) {
                $registrationCp[$eventId] = true;
            }
            if ($sameSchool && $sameRegistration) {
                $eventCp[$eventId] = true;
            }
            $studentCp[$eventId][(int)$submission['student_id']] = true;
        }

        $result = [];
        $events = $this->tables->get('Events')->find()
            ->select(['id', 'event_name', 'event_id_number'])
            ->where(['Events.id IN' => $eventIds])
            ->orderBy(['Events.event_id_number' => 'ASC'])
            ->enableHydration(false)->all();
        foreach ($events as $event) {
            $eventId = (int)$event['id'];
            $eventPositions = $positionsByEvent[$eventId] ?? [];
            if (!$eventPositions && !isset($eventCp[$eventId])) {
                continue;
            }
            foreach ($eventPositions as &$position) {
                $position['showName'] = '';
                $position['showSchoolName'] = '';
                $position['commandPerformance'] = isset($eventCp[$eventId]);
                $ids = (int)$position['student_id'] > 0
                    ? [(int)$position['student_id']]
                    : ($groupMembers[(int)$position['id']] ?? []);
                $names = [];
                foreach ($ids as $id) {
                    if (!isset($students[$id])) {
                        throw new RuntimeException('Result package references a missing student: ' . $id);
                    }
                    $student = $students[$id];
                    if (!isset($student['Schools']['first_name'])) {
                        throw new RuntimeException('Result package student references a missing school: ' . $id);
                    }
                    $names[] = $student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name'];
                    $position['showSchoolName'] = $student['Schools']['first_name'];
                }
                $position['showName'] = implode(', ', $names);
                if ((int)$position['student_id'] > 0) {
                    $position['commandPerformance'] = isset($submissionCp[(int)$position['eventsubmission_id']])
                        || isset($studentCp[$eventId][(int)$position['student_id']])
                        || isset($schoolCp[$eventId])
                        || isset($registrationCp[$eventId]);
                }
            }
            unset($position);
            $event['positions'] = $eventPositions;
            $result[] = $event;
        }

        return $result;
    }
}
