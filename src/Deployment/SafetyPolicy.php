<?php
declare(strict_types=1);

namespace App\Deployment;

use Cake\Core\Configure;
use Cake\Http\Exception\ServiceUnavailableException;
use Cake\Log\Log;
use RuntimeException;

final class SafetyPolicy
{
    public static function assertIntegrationAllowed(string $integration): void
    {
        $environment = Configure::read('Environment.name', 'local');
        if ($environment === 'demo' || ($environment !== 'local'
            && !in_array($integration, (array)Configure::read('Integrations.allowed', []), true))) {
            Log::warning('An external integration was blocked by environment policy.');
            throw new ServiceUnavailableException('This integration is disabled in this environment.');
        }
    }

    public static function assertCommandAllowed(array $arguments): void
    {
        if (Configure::read('Environment.name', 'local') === 'local') {
            return;
        }
        $command = $arguments[1] ?? 'help';
        $allowed = (array)Configure::read('Commands.allowed', []);
        if (Configure::read('Environment.name') === 'demo') {
            $allowed = array_intersect($allowed, ['result_export_cleanup']);
        }
        if ((count($arguments) <= 2 && in_array($command, ['help', '--help', '-h'], true))
            || in_array($command, $allowed, true)) {
            return;
        }
        Log::warning('A CLI command was blocked by environment policy.');
        throw new RuntimeException('This command is disabled in this environment.');
    }
}
