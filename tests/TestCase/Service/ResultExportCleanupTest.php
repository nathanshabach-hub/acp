<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ResultExportCleanup;
use Cake\TestSuite\TestCase;
use RuntimeException;

final class ResultExportCleanupTest extends TestCase
{
    private string $temporaryDirectory;
    private string $exportRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'result-export-cleanup-' . bin2hex(random_bytes(8));
        $this->exportRoot = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'student-result-exports';
        mkdir($this->exportRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeFixtureDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    public function testDryRunReportsEligibleExportWithoutDeletingFiles(): void
    {
        $directory = $this->createExport('a');
        $this->makeExpired($directory);

        $counts = (new ResultExportCleanup($this->temporaryDirectory))->run(true);

        $this->assertSame(['removed' => 0, 'eligible' => 1, 'skipped' => 0, 'failures' => 0], $counts);
        $this->assertFileExists($directory . DIRECTORY_SEPARATOR . 'results.zip');
    }

    public function testExpiredExportIsRemovedAndUnrelatedTemporaryFilesRemain(): void
    {
        $directory = $this->createExport('b');
        $this->makeExpired($directory);
        $unrelated = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'cake-cache.tmp';
        file_put_contents($unrelated, 'leave this file alone');

        $counts = (new ResultExportCleanup($this->temporaryDirectory))->run();

        $this->assertSame(['removed' => 1, 'eligible' => 1, 'skipped' => 0, 'failures' => 0], $counts);
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertFileExists($unrelated);
    }

    public function testRecentAndLockedExportsAreSkipped(): void
    {
        $recent = $this->createExport('c');
        $locked = $this->createExport('d');
        $this->makeExpired($locked);
        $lock = fopen($locked . DIRECTORY_SEPARATOR . '.active.lock', 'r');
        self::assertNotFalse($lock);
        flock($lock, LOCK_EX | LOCK_NB);

        try {
            $counts = (new ResultExportCleanup($this->temporaryDirectory))->run();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(0, $counts['removed']);
        $this->assertSame(2, $counts['skipped']);
        $this->assertDirectoryExists($recent);
        $this->assertDirectoryExists($locked);
    }

    public function testSymlinkedExportCannotCauseDeletionOutsideRoot(): void
    {
        $outside = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'outside';
        mkdir($outside);
        $protected = $outside . DIRECTORY_SEPARATOR . 'keep.txt';
        file_put_contents($protected, 'keep');
        $link = $this->exportRoot . DIRECTORY_SEPARATOR . str_repeat('e', 32);
        symlink($outside, $link);
        touch($link, time() - ResultExportCleanup::RETENTION_SECONDS - 10);

        $counts = (new ResultExportCleanup($this->temporaryDirectory))->run();

        $this->assertSame(0, $counts['removed']);
        $this->assertSame(1, $counts['skipped']);
        $this->assertFileExists($protected);
    }

    public function testSymlinkedCanonicalRootFailsClosed(): void
    {
        $realRoot = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'real-exports';
        mkdir($realRoot);
        rmdir($this->exportRoot);
        symlink($realRoot, $this->exportRoot);

        $this->expectException(RuntimeException::class);
        (new ResultExportCleanup($this->temporaryDirectory))->run(true);
    }

    private function createExport(string $suffix): string
    {
        $directory = $this->exportRoot . DIRECTORY_SEPARATOR . str_repeat($suffix, 32);
        mkdir($directory, 0700);
        file_put_contents($directory . DIRECTORY_SEPARATOR . '.active.lock', '');
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'results.zip', 'test archive');

        return $directory;
    }

    private function makeExpired(string $directory): void
    {
        $timestamp = time() - ResultExportCleanup::RETENTION_SECONDS - 10;
        touch($directory . DIRECTORY_SEPARATOR . '.active.lock', $timestamp);
        touch($directory . DIRECTORY_SEPARATOR . 'results.zip', $timestamp);
        touch($directory, $timestamp);
    }

    private function removeFixtureDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
            } else {
                $this->removeFixtureDirectory($path);
            }
        }
        rmdir($directory);
    }
}
