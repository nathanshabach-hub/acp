<?php
declare(strict_types=1);

namespace App\Utility;

use RuntimeException;
use SplFileObject;

final class PortalErrorLogs
{
    public function priority(string $header): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+(?:[^:\s]+\s+)?(Emergency|Alert|Critical|Error|Warning|Notice|Info|Debug):/i', $header, $match)) {
            return 'major';
        }
        $level = strtolower($match[1]);
        if (in_array($level, ['emergency', 'alert', 'critical'], true)) {
            return 'extreme';
        }
        $classification = $this->classify($header);
        if (in_array($classification['label'], ['Safety block', 'Request rejected', 'Scheduling warning', 'Diagnostic'], true)) {
            return 'minor';
        }

        return $level === 'error' ? 'major' : 'minor';
    }

    public function classify(string $header): array
    {
        $manual = [
            'label' => 'Manual investigation required',
            'style' => 'warning',
            'guidance' => 'No tested automatic repair is available. Reproduce the issue and review the code, configuration, or data. This historical entry does not prove the problem still exists.',
        ];
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+(?:[^:\s]+\s+)?(Emergency|Alert|Critical|Error|Warning|Notice|Info|Debug):\s*(.*)$/i', $header, $match)) {
            return $manual;
        }
        $message = trim($match[2]);
        $safetyMessages = [
            'An external integration was blocked by environment policy.',
            'A CLI command was blocked by environment policy.',
            'A payment request was blocked by environment policy.',
            'A request was blocked because its deployment URL did not match configuration.',
        ];
        if (in_array($message, $safetyMessages, true)) {
            return [
                'label' => 'Safety block',
                'style' => 'info',
                'guidance' => 'An environment safeguard rejected a request. If unexpected, review the deployment configuration and intended operation; do not disable safeguards as an automatic repair.',
            ];
        }
        if (preg_match('/^\[Cake\\\\Http\\\\Exception\\\\BadRequestException\] (?:Select a scheduling category from 1 to 4\.|Select an available portal error log\.|Invalid log search or page\.)(?: in |$)/', $message)) {
            return [
                'label' => 'Request rejected',
                'style' => 'info',
                'guidance' => 'Input validation rejected an invalid request. This is not a scheduling execution failure. Check the requested URL or input if this happens during normal use; validation remains enabled.',
            ];
        }
        if (preg_match('/^C1 summary season \d+:/', $message)) {
            if (preg_match('/\bsave_failures=([1-9]\d*)\b/', $message)) {
                return $manual;
            }
            if (preg_match('/\b(?:window_exceeded_events|no_rooms|no_groups|season_room_fallback|ungrouped_fallback|skipped_without_submission)=([1-9]\d*)\b/', $message)) {
                return [
                    'label' => 'Scheduling warning',
                    'style' => 'warning',
                    'guidance' => 'Review the recorded scheduling counts and entry warnings. Room capacity, groups, or submissions may need attention. Do not regenerate schedules or change data without reviewing the affected season.',
                ];
            }
        }
        if (preg_match('/^(?:C1 parsed window season \d+:|C1 summary season \d+:|Conventions index filter:)/', $message)) {
            return [
                'label' => 'Diagnostic',
                'style' => 'default',
                'guidance' => 'This is a recorded diagnostic message, not necessarily an error. Review its values for failures before taking action; no automatic repair is offered.',
            ];
        }
        return $manual;
    }

    public function files(): array
    {
        $files = [];
        $root = realpath(LOGS);
        if ($root === false || !is_readable($root)) {
            throw new RuntimeException('The portal log directory is not readable.');
        }
        foreach (new \DirectoryIterator($root) as $file) {
            $name = $file->getFilename();
            if (!preg_match('/^(?:cli-)?error\.log(?:\.\d+)?$/D', $name) || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $files[$name] = $file->getMTime();
        }
        arsort($files);
        return array_keys($files);
    }

    public function clearAll(): int
    {
        $names = $this->files();
        $root = realpath(LOGS);
        foreach ($names as $name) {
            $path = LOGS . $name;
            if (is_link($path) || realpath($path) !== $root . DIRECTORY_SEPARATOR . $name) {
                throw new RuntimeException('An error log changed during deletion. Refresh and try again.');
            }
            $handle = fopen($path, 'r+');
            if ($handle === false) {
                throw new RuntimeException('Unable to open error log for deletion: ' . $name);
            }
            try {
                if (!flock($handle, LOCK_EX) || !ftruncate($handle, 0) || !fflush($handle)) {
                    throw new RuntimeException('Unable to clear error log: ' . $name);
                }
            } finally {
                fclose($handle);
            }
        }

        return count($names);
    }

    public function read(string $name, string $search, int $page): array
    {
        if (!in_array($name, $this->files(), true)) {
            throw new \InvalidArgumentException('Select an available portal error log.');
        }
        $path = realpath(LOGS . $name);
        if ($path === false || dirname($path) !== realpath(LOGS) || !is_readable($path)) {
            throw new RuntimeException('The selected error log is not readable.');
        }
        $file = new SplFileObject($path, 'r');
        $matches = static fn(string $line): bool => trim($line) !== '' && ($search === '' || stripos($line, $search) !== false);
        $total = 0;
        foreach ($file as $line) {
            if (is_string($line) && $matches($line)) {
                $total++;
            }
        }
        $pages = max(1, (int)ceil($total / 100));
        $page = min(max(1, $page), $pages);
        $start = max(0, $total - $page * 100);
        $end = $total - ($page - 1) * 100;
        $rows = [];
        $index = 0;
        $classification = $this->classify('');
        $eventNumber = 0;
        $eventHeader = 'Log entry without a recognized timestamp header';
        $file->rewind();
        foreach ($file as $lineNumber => $line) {
            if (is_string($line) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+(?:[^:\s]+\s+)?(?:Emergency|Alert|Critical|Error|Warning|Notice|Info|Debug):/i', $line)) {
                $classification = $this->classify($line);
                $eventNumber = $lineNumber + 1;
                $eventHeader = rtrim($line);
            }
            if (!is_string($line) || !$matches($line)) {
                continue;
            }
            if ($index >= $start && $index < $end) {
                $rows[] = [
                    'number' => $lineNumber + 1,
                    'text' => rtrim($line),
                    'classification' => $classification,
                    'eventNumber' => $eventNumber,
                    'eventHeader' => $eventHeader,
                ];
            }
            $index++;
            if ($index >= $end) {
                break;
            }
        }
        $rows = array_reverse($rows);
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['eventNumber'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'header' => $row['eventHeader'],
                    'priority' => $this->priority($row['eventHeader']),
                    'classification' => $row['classification'],
                    'rows' => [],
                ];
            }
            array_unshift($groups[$key]['rows'], $row);
        }
        return ['rows' => $rows, 'groups' => array_values($groups), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
