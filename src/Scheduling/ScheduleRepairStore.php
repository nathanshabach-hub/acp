<?php
declare(strict_types=1);

namespace App\Scheduling;

/**
 * Persists conflict repair previews/runs as JSON in logs/schedule_repairs (outside the
 * web root). Files are written atomically (temp file + rename) with owner/group-only
 * permissions, and read-modify-write updates are serialised with an exclusive lock.
 */
final class ScheduleRepairStore
{
    private const TOKEN_PATTERN = '/^[a-f0-9]{20}$/';
    private const RUN_ID_PATTERN = '/^\d{8}_\d{6}_[a-f0-9]{8}$/';
    private const DIR_MODE = 0750;
    private const FILE_MODE = 0640;

    public const TIMING_FIELDS = [
        'room_id',
        'day',
        'start_time',
        'finish_time',
        'sch_date_time',
        'modified',
    ];

    public function __construct(private string $rootDir)
    {
    }

    public function savePlan(int $seasonId, array $payload): array
    {
        $token = $this->token();
        $record = [
            'token' => $token,
            'season_id' => $seasonId,
            'created_at' => gmdate('c'),
        ] + $payload;
        $this->writeJson($this->planPath($seasonId, $token), $record);

        return $record;
    }

    public function readPlan(int $seasonId, string $token): ?array
    {
        if (!self::isValidToken($token)) {
            return null;
        }

        return $this->readJson($this->planPath($seasonId, $token));
    }

    /**
     * Records that a preview was applied so it cannot be replayed.
     */
    public function markPlanApplied(int $seasonId, string $token, string $runId): void
    {
        if (!self::isValidToken($token)) {
            return;
        }
        $this->update($this->planPath($seasonId, $token), fn (array $record) => ['applied_run' => $runId, 'applied_at' => gmdate('c')] + $record);
    }

    public function saveRun(int $seasonId, array $payload): array
    {
        $runId = gmdate('Ymd_His').'_'.substr($this->token(), 0, 8);
        $record = [
            'run_id' => $runId,
            'season_id' => $seasonId,
            'created_at' => gmdate('c'),
            'reverted' => false,
        ] + $payload;
        $this->writeJson($this->runPath($seasonId, $runId), $record);

        return $record;
    }

    public function readRun(int $seasonId, string $runId): ?array
    {
        if (!self::isValidRunId($runId)) {
            return null;
        }

        return $this->readJson($this->runPath($seasonId, $runId));
    }

    /**
     * Removes a run record (used when the database commit that it describes fails).
     */
    public function deleteRun(int $seasonId, string $runId): void
    {
        if (self::isValidRunId($runId) && is_file($this->runPath($seasonId, $runId))) {
            unlink($this->runPath($seasonId, $runId));
        }
    }

    public static function isValidToken(string $token): bool
    {
        return (bool)preg_match(self::TOKEN_PATTERN, $token);
    }

    public static function isValidRunId(string $runId): bool
    {
        return (bool)preg_match(self::RUN_ID_PATTERN, $runId);
    }

    /**
     * @return list<array{run_id:string,created_at:string,reverted:bool,moves:int,total_issues_before:int,total_issues_after:int}>
     */
    public function listRuns(int $seasonId, int $limit = 20): array
    {
        $pattern = $this->seasonDir($seasonId).'/run_*.json';
        $rows = [];
        foreach (glob($pattern) ?: [] as $path) {
            $row = $this->readJson($path);
            if (!$row) {
                continue;
            }
            $rows[] = [
                'run_id' => (string)($row['run_id'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'reverted' => (bool)($row['reverted'] ?? false),
                'moves' => count($row['moves'] ?? []),
                'total_issues_before' => (int)($row['before']['total_issues'] ?? 0),
                'total_issues_after' => (int)($row['after']['total_issues'] ?? 0),
            ];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * @param array<string, mixed> $details restored/skipped rows and the validation after revert
     */
    public function markRunReverted(int $seasonId, string $runId, array $details = []): void
    {
        if (!self::isValidRunId($runId)) {
            return;
        }
        $this->update($this->runPath($seasonId, $runId), fn (array $record) => ['reverted' => true, 'reverted_at' => gmdate('c'), 'revert' => $details] + $record);
    }

    /**
     * Revert only rows that still match applied values; skip rows edited afterwards.
     *
     * @param array<int,array<string,mixed>> $currentRows keyed by row id
     * @return array{restore: array<int,array<string,mixed>>, skipped: list<array{row_id:int,reason:string}>}
     */
    public static function revertOperations(array $run, array $currentRows): array
    {
        $restore = [];
        $skipped = [];
        $before = $run['row_before'] ?? [];
        $applied = $run['row_applied'] ?? [];
        foreach ($before as $rowId => $original) {
            $id = (int)$rowId;
            if (!isset($currentRows[$id])) {
                $skipped[] = ['row_id' => $id, 'reason' => 'row_missing'];
                continue;
            }
            $current = $currentRows[$id];
            $expected = $applied[$id] ?? null;
            if (!is_array($expected)) {
                $skipped[] = ['row_id' => $id, 'reason' => 'missing_applied_snapshot'];
                continue;
            }
            if (!self::sameTiming($current, $expected)) {
                $skipped[] = ['row_id' => $id, 'reason' => 'changed_after_apply'];
                continue;
            }
            $restore[$id] = $original;
        }

        return ['restore' => $restore, 'skipped' => $skipped];
    }

    public static function sameTiming(array $a, array $b): bool
    {
        foreach (self::TIMING_FIELDS as $field) {
            if ((string)($a[$field] ?? '') !== (string)($b[$field] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function seasonDir(int $seasonId): string
    {
        return rtrim($this->rootDir, '/').'/season_'.$seasonId;
    }

    private function planPath(int $seasonId, string $token): string
    {
        return $this->seasonDir($seasonId).'/plan_'.$token.'.json';
    }

    private function runPath(int $seasonId, string $runId): string
    {
        return $this->seasonDir($seasonId).'/run_'.$runId.'.json';
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, self::DIR_MODE, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create repair store directory.');
        }
    }

    /**
     * Atomic write: readers never see a half-written file.
     */
    private function writeJson(string $path, array $record): void
    {
        $this->ensureDir(dirname($path));
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temp = tempnam(dirname($path), '.tmp_');
        if ($temp === false) {
            throw new \RuntimeException('Cannot create repair store file.');
        }
        try {
            if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) {
                throw new \RuntimeException('Cannot write repair store file.');
            }
            chmod($temp, self::FILE_MODE);
            if (!rename($temp, $path)) {
                throw new \RuntimeException('Cannot save repair store file.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /**
     * Read-modify-write under an exclusive per-season lock so concurrent updates are not lost.
     *
     * @param callable(array): array $change
     */
    private function update(string $path, callable $change): void
    {
        $this->ensureDir(dirname($path));
        $lock = fopen(dirname($path).'/.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock repair store.');
        }
        try {
            flock($lock, LOCK_EX);
            $record = $this->readJson($path);
            if ($record !== null) {
                $this->writeJson($path, $change($record));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function token(): string
    {
        return bin2hex(random_bytes(10));
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
