<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Scheduling\ScheduleRepairStore;
use PHPUnit\Framework\TestCase;

class ScheduleRepairStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/schedule_repair_store_'.uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
        parent::tearDown();
    }

    public function testSaveAndReadPlanAndRun(): void
    {
        $store = new ScheduleRepairStore($this->tmpDir);
        $plan = $store->savePlan(26, ['snapshot_hash' => 'abc', 'moves' => []]);
        $this->assertNotEmpty($plan['token']);
        $loaded = $store->readPlan(26, $plan['token']);
        $this->assertSame('abc', $loaded['snapshot_hash']);

        $run = $store->saveRun(26, ['moves' => [1], 'before' => ['total_issues' => 3], 'after' => ['total_issues' => 1]]);
        $this->assertNotEmpty($run['run_id']);
        $history = $store->listRuns(26);
        $this->assertCount(1, $history);
        $this->assertSame($run['run_id'], $history[0]['run_id']);
    }

    public function testTokensAndRunIdsCannotEscapeTheStore(): void
    {
        $store = new ScheduleRepairStore($this->tmpDir);
        file_put_contents($this->tmpDir.'/secret.json', '{"x":1}');
        mkdir($this->tmpDir.'/season_26', 0750, true);

        $this->assertNull($store->readPlan(26, '../secret'));
        $this->assertNull($store->readPlan(26, '../../'.basename($this->tmpDir).'/secret'));
        $this->assertNull($store->readRun(26, '../secret'));
        $this->assertFalse(ScheduleRepairStore::isValidToken('ABCDEF'));
        $this->assertFalse(ScheduleRepairStore::isValidRunId('20260101_000000_abc/../x'));
        $this->assertTrue(ScheduleRepairStore::isValidToken(str_repeat('a1', 10)));
        $this->assertTrue(ScheduleRepairStore::isValidRunId('20260101_120000_0123abcd'));
    }

    public function testFilesAreWrittenAtomicallyWithRestrictedPermissions(): void
    {
        $store = new ScheduleRepairStore($this->tmpDir.'/repairs');
        $plan = $store->savePlan(26, ['moves' => []]);
        $run = $store->saveRun(26, ['moves' => []]);
        $dir = $this->tmpDir.'/repairs/season_26';

        $this->assertSame(0750, fileperms($dir) & 0777);
        $this->assertSame(0640, fileperms($dir.'/plan_'.$plan['token'].'.json') & 0777);
        $this->assertSame(0640, fileperms($dir.'/run_'.$run['run_id'].'.json') & 0777);
        $this->assertSame([], glob($dir.'/.tmp_*'), 'no temp files left behind');
    }

    public function testRunAndPlanStatusUpdates(): void
    {
        $store = new ScheduleRepairStore($this->tmpDir);
        $plan = $store->savePlan(26, ['moves' => []]);
        $store->markPlanApplied(26, $plan['token'], '20260101_120000_0123abcd');
        $this->assertSame('20260101_120000_0123abcd', $store->readPlan(26, $plan['token'])['applied_run']);

        $run = $store->saveRun(26, ['moves' => []]);
        $store->markRunReverted(26, $run['run_id'], ['restored' => [10], 'skipped' => []]);
        $reverted = $store->readRun(26, $run['run_id']);
        $this->assertTrue($reverted['reverted']);
        $this->assertSame([10], $reverted['revert']['restored']);
        $this->assertTrue($store->listRuns(26)[0]['reverted']);

        $store->deleteRun(26, $run['run_id']);
        $this->assertNull($store->readRun(26, $run['run_id']));
    }

    public function testRevertOperationsSkipsRowsChangedAfterApply(): void
    {
        $run = [
            'row_before' => [
                10 => ['room_id' => '1', 'day' => 'Tuesday', 'start_time' => '08:00:00', 'finish_time' => '08:30:00', 'sch_date_time' => '2026-10-27 08:00:00', 'modified' => 'a'],
                11 => ['room_id' => '1', 'day' => 'Tuesday', 'start_time' => '08:30:00', 'finish_time' => '09:00:00', 'sch_date_time' => '2026-10-27 08:30:00', 'modified' => 'a'],
            ],
            'row_applied' => [
                10 => ['room_id' => '2', 'day' => 'Tuesday', 'start_time' => '09:00:00', 'finish_time' => '09:30:00', 'sch_date_time' => '2026-10-27 09:00:00', 'modified' => 'b'],
                11 => ['room_id' => '2', 'day' => 'Tuesday', 'start_time' => '09:30:00', 'finish_time' => '10:00:00', 'sch_date_time' => '2026-10-27 09:30:00', 'modified' => 'b'],
            ],
        ];
        $current = [
            10 => ['room_id' => '2', 'day' => 'Tuesday', 'start_time' => '09:00:00', 'finish_time' => '09:30:00', 'sch_date_time' => '2026-10-27 09:00:00', 'modified' => 'b'],
            11 => ['room_id' => '9', 'day' => 'Tuesday', 'start_time' => '09:30:00', 'finish_time' => '10:00:00', 'sch_date_time' => '2026-10-27 09:30:00', 'modified' => 'c'],
        ];

        $ops = ScheduleRepairStore::revertOperations($run, $current);
        $this->assertArrayHasKey(10, $ops['restore']);
        $this->assertCount(1, $ops['skipped']);
        $this->assertSame(11, $ops['skipped'][0]['row_id']);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
