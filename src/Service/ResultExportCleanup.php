<?php
declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class ResultExportCleanup
{
    public const RETENTION_SECONDS = 7200;
    private const DIRECTORY_PATTERN = '/\A[a-f0-9]{32}\z/';
    private const LOCK_FILE = '.active.lock';

    public function __construct(private string $temporaryDirectory)
    {
    }

    /**
     * @return array{removed:int, eligible:int, skipped:int, failures:int}
     */
    public function run(bool $dryRun = false): array
    {
        $counts = ['removed' => 0, 'eligible' => 0, 'skipped' => 0, 'failures' => 0];
        $root = $this->canonicalRoot();
        if ($root === null) {
            return $counts;
        }

        $entries = scandir($root);
        if ($entries === false) {
            throw new RuntimeException('Could not inspect the result export directory.');
        }
        $cutoff = time() - self::RETENTION_SECONDS;
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            if (!preg_match(self::DIRECTORY_PATTERN, $entry)) {
                continue;
            }
            $directory = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_link($directory) || !is_dir($directory)) {
                $counts['skipped']++;
                continue;
            }
            $resolved = realpath($directory);
            $modified = filemtime($directory);
            if ($resolved === false || dirname($resolved) !== $root || $modified === false) {
                $counts['failures']++;
                continue;
            }
            if ($modified > $cutoff) {
                $counts['skipped']++;
                continue;
            }

            $lockPath = $directory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
            if (is_link($lockPath) || !is_file($lockPath)) {
                $counts['skipped']++;
                continue;
            }
            $lock = @fopen($lockPath, 'r');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                $counts['skipped']++;
                continue;
            }

            $counts['eligible']++;
            if (!$dryRun && $this->removeDirectory($directory, $root)) {
                $counts['removed']++;
            } elseif (!$dryRun) {
                $counts['failures']++;
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $counts;
    }

    private function canonicalRoot(): ?string
    {
        $expected = rtrim($this->temporaryDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'student-result-exports';
        if (is_link($expected)) {
            throw new RuntimeException('The result export directory is not a safe canonical directory.');
        }
        if (!file_exists($expected)) {
            return null;
        }
        $root = realpath($expected);
        $temporaryDirectory = realpath($this->temporaryDirectory);
        if ($root === false || $temporaryDirectory === false
            || dirname($root) !== $temporaryDirectory
            || basename($root) !== 'student-result-exports'
            || !is_dir($root)) {
            throw new RuntimeException('The result export directory is not a safe canonical directory.');
        }

        return $root;
    }

    private function removeDirectory(string $directory, string $root): bool
    {
        $resolved = realpath($directory);
        if ($resolved === false || dirname($resolved) !== $root || is_link($directory)) {
            return false;
        }
        $entries = scandir($resolved);
        if ($entries === false) {
            return false;
        }
        foreach (array_diff($entries, ['.', '..', self::LOCK_FILE]) as $entry) {
            $path = $resolved . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || !is_dir($path)) {
                if (!@unlink($path)) {
                    return false;
                }
                continue;
            }
            if (!$this->removeNestedDirectory($path)) {
                return false;
            }
        }

        $lockPath = $resolved . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        if (is_link($lockPath) || !is_file($lockPath) || !@unlink($lockPath)) {
            return false;
        }
        if (@rmdir($resolved)) {
            return true;
        }
        @file_put_contents($lockPath, '', LOCK_EX);

        return false;
    }

    private function removeNestedDirectory(string $directory): bool
    {
        if (is_link($directory) || !is_dir($directory)) {
            return false;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            return false;
        }
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || !is_dir($path)) {
                if (!@unlink($path)) {
                    return false;
                }
                continue;
            }
            if (!$this->removeNestedDirectory($path)) {
                return false;
            }
        }

        return @rmdir($directory);
    }
}
