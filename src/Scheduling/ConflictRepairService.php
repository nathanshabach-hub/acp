<?php
declare(strict_types=1);

namespace App\Scheduling;

use Cake\Database\Connection;
use Cake\Database\Driver\Mysql;

/**
 * Preview, apply and revert of conflict repairs for one season.
 *
 * Apply and revert run under a per-season lock inside one transaction. They lock the
 * season's timing rows, recheck the preview against the current database, write, reload
 * and validate again from the database. Any new issue (or any error) rolls everything
 * back. Run records are written before commit and removed if the commit fails.
 */
class ConflictRepairService
{
    public const APPLIED = 'applied';
    public const REVERTED = 'reverted';
    public const NOTHING = 'nothing';
    public const NOT_FOUND = 'not_found';
    public const STALE = 'stale';
    public const ALREADY_DONE = 'already_done';
    public const NEW_ISSUES = 'new_issues';
    public const BUSY = 'busy';
    public const FAILED = 'failed';

    public function __construct(private Connection $db, private ScheduleRepairStore $store, private int $lockTimeoutSeconds = 10)
    {
    }

    /**
     * Dry run: plans repairs and saves the plan. Changes nothing in the database.
     *
     * @return array{preview: array, plan: array}
     */
    public function preview(int $seasonId, string $seasonSlug = ''): array
    {
        $data = (new TimetableLoader($this->db))->load($seasonId);
        $plan = (new ConflictRepairPlanner($data['context']))->plan($data['bookings']);
        $preview = $this->store->savePlan($seasonId, [
            'season_slug' => $seasonSlug,
            'snapshot_hash' => TimetableLoader::timingHash($data['rows']),
            'context_hash' => self::contextHash($data['context']),
            'before' => $plan['before'],
            'after' => $plan['after'],
            'moves' => $plan['moves'],
            'unresolved' => $plan['unresolved'],
            'not_movable' => $plan['not_movable'],
            'bookings' => $plan['bookings'],
        ]);

        return ['preview' => $preview, 'plan' => $plan];
    }

    /**
     * @return array{status:string, message:string, run?:array, new_issues?:list<array>, after?:array}
     */
    public function apply(int $seasonId, string $token): array
    {
        $preview = $this->store->readPlan($seasonId, $token);
        if (!$preview) {
            return self::result(self::NOT_FOUND, 'Preview not found. Please run preview again.');
        }
        if (!empty($preview['applied_run'])) {
            return self::result(self::ALREADY_DONE, 'This preview has already been applied.');
        }
        $moves = $preview['moves'] ?? [];
        $planned = $preview['bookings'] ?? [];
        $movedRows = [];
        foreach ($moves as $move) {
            foreach ($move['rows'] ?? [] as $rowId) {
                $movedRows[(int)$rowId] = true;
            }
        }
        if (!$movedRows) {
            $unresolved = count($preview['unresolved'] ?? []);

            return self::result(self::NOTHING, $unresolved
                ? 'No booking could be moved safely. '.$unresolved.' booking(s) remain unresolved (see the preview for the reasons). Nothing changed.'
                : 'No movable conflicts were found. Nothing changed.');
        }

        return $this->locked($seasonId, function () use ($seasonId, $token, $preview, $moves, $planned, $movedRows): array {
            $loader = new TimetableLoader($this->db);
            $current = $loader->load($seasonId);
            $currentHash = TimetableLoader::timingHash($current['rows']);
            if ($currentHash !== (string)($preview['snapshot_hash'] ?? '')) {
                return self::result(self::STALE, 'The timetable changed since the preview. Please run the preview again.');
            }
            if (self::contextHash($current['context']) !== (string)($preview['context_hash'] ?? '')) {
                return self::result(self::STALE, 'Rooms, events, pinned settings or convention hours changed since the preview. Please run the preview again.');
            }

            $validator = new TimetableValidator($current['context']);
            $beforeReport = $validator->validate($current['bookings']);

            $beforeRows = [];
            $appliedRows = [];
            $proposed = $current['bookings'];
            $modifiedNow = date('Y-m-d H:i:s');
            foreach (array_keys($movedRows) as $rowId) {
                $booking = $planned[$rowId] ?? null;
                if (!isset($current['rows'][$rowId]) || !is_array($booking) || empty($booking['date'])) {
                    return self::result(self::STALE, 'Planned booking '.$rowId.' no longer matches the timetable. Please run the preview again.');
                }
                $start = TimetableLedger::toTime((int)$booking['start']);
                $beforeRows[$rowId] = self::timingSnapshot($current['rows'][$rowId]);
                $appliedRows[$rowId] = [
                    'room_id' => (int)$booking['room_id'],
                    'day' => $current['context']['dates'][$booking['date']]['day'] ?? date('l', strtotime((string)$booking['date'])),
                    'start_time' => $start,
                    'finish_time' => TimetableLedger::toTime((int)$booking['finish']),
                    'sch_date_time' => $booking['date'].' '.$start,
                    'modified' => $modifiedNow,
                ];
                $proposed[$rowId] = ['room_id' => (int)$booking['room_id'], 'date' => (string)$booking['date'], 'start' => (int)$booking['start'], 'finish' => (int)$booking['finish']] + $proposed[$rowId];
            }

            // Revalidate the proposal against current rules before writing anything.
            $newIssues = self::newIssues($validator->validate($proposed), $beforeReport);
            if ($newIssues) {
                return self::result(self::NEW_ISSUES, 'The repair would introduce new issues. No changes were saved.', ['new_issues' => $newIssues]);
            }

            foreach ($appliedRows as $rowId => $fields) {
                $this->writeRow($rowId, $fields);
            }

            // Validate again from what is now in the database.
            $after = $loader->load($seasonId);
            $afterReport = (new TimetableValidator($after['context']))->validate($after['bookings']);
            $newIssues = self::newIssues($afterReport, $beforeReport);
            if ($newIssues) {
                return self::result(self::NEW_ISSUES, 'The repair would introduce new issues. No changes were saved.', ['new_issues' => $newIssues]);
            }
            foreach ($appliedRows as $rowId => $fields) {
                $appliedRows[$rowId] = self::timingSnapshot($after['rows'][$rowId]);
            }
            $this->updateConflictFields($seasonId, $afterReport, $after['rows']);

            $run = $this->store->saveRun($seasonId, [
                'token' => $token,
                'snapshot_hash_before' => $currentHash,
                'snapshot_hash_after' => TimetableLoader::timingHash($after['rows']),
                'row_before' => $beforeRows,
                'row_applied' => $appliedRows,
                'moves' => $moves,
                'unresolved' => $preview['unresolved'] ?? [],
                'not_movable' => $preview['not_movable'] ?? [],
                'before' => $beforeReport,
                'after' => $afterReport,
            ]);

            return self::result(self::APPLIED, 'Conflict repair applied. Moved '.count($appliedRows).' booking(s).', ['run' => $run, 'after' => $afterReport]);
        }, function (array $result) use ($seasonId, $token): void {
            $this->store->markPlanApplied($seasonId, $token, (string)$result['run']['run_id']);
        });
    }

    /**
     * Restores rows changed by a run, skipping rows edited after the run. The revert is
     * refused if it would create an issue that existed neither before the original
     * repair nor now.
     *
     * @return array{status:string, message:string, restored?:list<int>, skipped?:list<array>, new_issues?:list<array>, after?:array}
     */
    public function revert(int $seasonId, string $runId): array
    {
        $run = $this->store->readRun($seasonId, $runId);
        if (!$run) {
            return self::result(self::NOT_FOUND, 'Conflict repair run not found.');
        }
        if (!empty($run['reverted'])) {
            return self::result(self::ALREADY_DONE, 'This repair run has already been reverted.');
        }

        return $this->locked($seasonId, function () use ($seasonId, $runId, $run): array {
            $loader = new TimetableLoader($this->db);
            $current = $loader->load($seasonId);
            $ops = ScheduleRepairStore::revertOperations($run, $current['rows']);
            if (!$ops['restore']) {
                return self::result(self::NOTHING, 'Nothing to revert: every repaired booking was changed after the repair.', ['skipped' => $ops['skipped']]);
            }
            $currentReport = (new TimetableValidator($current['context']))->validate($current['bookings']);

            foreach ($ops['restore'] as $rowId => $snapshot) {
                $this->writeRow((int)$rowId, $snapshot);
            }

            $after = $loader->load($seasonId);
            $afterReport = (new TimetableValidator($after['context']))->validate($after['bookings']);
            $allowed = array_merge($currentReport['issues'], $run['before']['issues'] ?? []);
            $newIssues = self::newIssues($afterReport, ['issues' => $allowed]);
            if ($newIssues) {
                return self::result(self::NEW_ISSUES, 'Reverting would create new issues because some bookings changed after the repair. Nothing was reverted.', ['new_issues' => $newIssues, 'skipped' => $ops['skipped']]);
            }
            $this->updateConflictFields($seasonId, $afterReport, $after['rows']);

            return self::result(self::REVERTED, 'Restored '.count($ops['restore']).' booking(s). Skipped '.count($ops['skipped']).'.', [
                'restored' => array_map('intval', array_keys($ops['restore'])),
                'skipped' => $ops['skipped'],
                'after' => $afterReport,
            ]);
        }, function (array $result) use ($seasonId, $runId): void {
            $this->store->markRunReverted($seasonId, $runId, [
                'restored' => $result['restored'],
                'skipped' => $result['skipped'],
                'total_issues_after' => $result['after']['total_issues'] ?? null,
            ]);
        });
    }

    /**
     * Writes one timing row. Protected so tests can inject a failure.
     *
     * @param array<string, mixed> $fields
     */
    protected function writeRow(int $rowId, array $fields): void
    {
        $this->db->update('schedulingtimings', $fields, ['id' => $rowId]);
    }

    public static function contextHash(array $context): string
    {
        return sha1(json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    /**
     * Runs $work under the season lock in a transaction with the season's timing rows
     * locked. Commits only when $work reports success; $afterCommit then records it.
     */
    private function locked(int $seasonId, callable $work, callable $afterCommit): array
    {
        $lockName = 'acp_schedule_repair_'.$seasonId;
        $mysql = $this->db->getDriver() instanceof Mysql;
        if ($mysql && (int)$this->db->execute('SELECT GET_LOCK(?, ?) AS l', [$lockName, $this->lockTimeoutSeconds])->fetch('assoc')['l'] !== 1) {
            return self::result(self::BUSY, 'Another conflict repair is running for this season. Please try again.');
        }
        $savedRunId = null;
        try {
            $this->db->begin();
            try {
                $this->db->execute('SELECT id FROM schedulingtimings WHERE conventionseasons_id = ?'.($mysql ? ' FOR UPDATE' : ''), [$seasonId])->fetchAll();
                $result = $work();
                if (!in_array($result['status'], [self::APPLIED, self::REVERTED], true)) {
                    $this->db->rollback();

                    return $result;
                }
                $savedRunId = $result['run']['run_id'] ?? null;
                $this->db->commit();
            } catch (\Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollback();
                }
                if ($savedRunId !== null) {
                    $this->store->deleteRun($seasonId, (string)$savedRunId);
                }

                return self::result(self::FAILED, 'The change could not be saved and was rolled back.', ['error' => $exception->getMessage()]);
            }
            $afterCommit($result);

            return $result;
        } finally {
            if ($mysql) {
                $this->db->execute('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }

    private static function newIssues(array $report, array $baseline): array
    {
        $known = array_fill_keys(array_column($baseline['issues'] ?? [], 'key'), true);

        return array_values(array_filter($report['issues'], fn (array $issue) => !isset($known[$issue['key']])));
    }

    private function updateConflictFields(int $seasonId, array $report, array $rowsById): void
    {
        $users = [];
        $groupRows = [];
        foreach ($report['issues'] as $issue) {
            if ($issue['type'] !== TimetableValidator::STUDENT_OVERLAP) {
                continue;
            }
            foreach ($issue['students'] ?? [] as $studentId) {
                $users[(int)$studentId] = true;
            }
            foreach ($issue['rows'] ?? [] as $rowId) {
                if ((string)($rowsById[(int)$rowId]['user_type'] ?? '') === 'School') {
                    $groupRows[(int)$rowId] = true;
                }
            }
        }
        $this->db->update('schedulings', [
            'conflict_user_ids' => $users ? implode(',', array_keys($users)) : null,
            'conflict_user_ids_group' => $groupRows ? implode(',', array_keys($groupRows)) : null,
            'modified' => date('Y-m-d H:i:s'),
        ], ['conventionseasons_id' => $seasonId]);
    }

    private static function timingSnapshot(array $row): array
    {
        $snapshot = [];
        foreach (ScheduleRepairStore::TIMING_FIELDS as $field) {
            $snapshot[$field] = $row[$field] ?? null;
        }

        return $snapshot;
    }

    private static function result(string $status, string $message, array $extra = []): array
    {
        return ['status' => $status, 'message' => $message] + $extra;
    }
}
