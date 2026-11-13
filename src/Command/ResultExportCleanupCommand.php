<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\ResultExportCleanup;
use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Log\Log;
use RuntimeException;

final class ResultExportCleanupCommand extends BaseCommand
{
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Remove stale temporary student result exports.')
            ->addOption('dry-run', [
                'boolean' => true,
                'help' => 'Report eligible exports without deleting files.',
            ]);
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $cleanup = new ResultExportCleanup((string)Configure::read('App.paths.temporary', ROOT . DS . 'tmp' . DS));
            $counts = $cleanup->run((bool)$args->getOption('dry-run'));
        } catch (RuntimeException $exception) {
            Log::error('Result export cleanup could not safely inspect its configured directory.');
            $io->err('Result export cleanup stopped because its configured directory is unsafe or unavailable.');

            return static::CODE_ERROR;
        }

        $dryRun = (bool)$args->getOption('dry-run');
        $io->out(sprintf(
            'Result export cleanup %s: eligible=%d removed=%d skipped=%d failures=%d',
            $dryRun ? 'dry run' : 'complete',
            $counts['eligible'],
            $counts['removed'],
            $counts['skipped'],
            $counts['failures'],
        ));
        Log::info(sprintf(
            'Result export cleanup %s: eligible=%d removed=%d skipped=%d failures=%d',
            $dryRun ? 'dry run' : 'complete',
            $counts['eligible'],
            $counts['removed'],
            $counts['skipped'],
            $counts['failures'],
        ));
        if ($counts['failures'] > 0) {
            Log::warning(sprintf('Result export cleanup encountered %d filesystem failure(s).', $counts['failures']));
        }

        return $counts['failures'] > 0 ? static::CODE_ERROR : static::CODE_SUCCESS;
    }
}
