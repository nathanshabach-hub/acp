<?php
declare(strict_types=1);

namespace App\Service;

use App\Controller\JudgeevaluationsController;
use App\View\AppView;
use Cake\Http\ServerRequest;
use DomainException;
use RuntimeException;
use ZipArchive;

final class EvaluationBatchExport
{
    public function generate(array $students, ServerRequest $request, string $directory, ?callable $onProgress = null): string
    {
        $binary = '';
        foreach (['google-chrome', 'google-chrome-stable', 'chromium-browser', 'chromium'] as $candidate) {
            $binary = trim((string)shell_exec('command -v ' . escapeshellarg($candidate)));
            if ($binary !== '') {
                break;
            }
        }
        $timeout = trim((string)shell_exec('command -v timeout'));
        if ($binary === '' || $timeout === '') {
            throw new RuntimeException('PDF export requires Chrome/Chromium and the timeout command on this server.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PDF export requires the PHP ZIP extension.');
        }
        $pdfs = [];
        foreach ($students as $student) {
            $name = trim(implode(' ', array_filter([
                trim((string)$student['first_name']), trim((string)$student['middle_name']), trim((string)$student['last_name']),
            ], static fn(string $part): bool => $part !== '')));
            $folder = trim((string)preg_replace('/[^a-z0-9-]+/i', '-', $name), '-');
            $folder = ($folder !== '' ? $folder : 'student') . '-' . (int)$student['id'];
            $event = [
                'type' => 'file',
                'student_id' => (int)$student['id'],
                'name' => $name,
                'file' => $folder . '/evaluations.pdf',
                'total' => count($students),
            ];
            if ($onProgress !== null) {
                $onProgress($event + ['status' => 'preparing', 'completed' => count($pdfs)]);
            }
            $base = $directory . DS . (int)$student['id'];
            $pdfRequest = $request->withQueryParams(['evaluations_only' => 1, 'autoprint' => 0]);
            $controller = new JudgeevaluationsController($pdfRequest);
            $result = $controller->indrespackprint((string)$student['slug']);
            if ($result !== null) {
                throw new DomainException('Could not access evaluation forms for ' . $name . '.');
            }
            $view = new AppView($pdfRequest);
            $view->set($controller->viewBuilder()->getVars());
            $view->setLayout('evaluation_export');
            $html = $view->render('Judgeevaluations/indrespackprint');
            if (!str_contains($html, '<div class="judge-evaluation-page">')) {
                throw new DomainException('No evaluation forms are available for ' . $name . '. Refresh the selection page.');
            }
            if (file_put_contents($base . '.html', $html) === false || !chmod($base . '.html', 0600)) {
                throw new RuntimeException('Could not write temporary evaluation forms.');
            }
            $output = [];
            $exitCode = 0;
            exec(escapeshellarg($timeout) . ' 30s ' . escapeshellarg($binary)
                . ' --headless --disable-gpu --no-pdf-header-footer'
                . ' --user-data-dir=' . escapeshellarg($directory . DS . 'chrome-profile')
                . ' --print-to-pdf=' . escapeshellarg($base . '.pdf')
                . ' ' . escapeshellarg('file://' . $base . '.html') . ' 2>&1', $output, $exitCode);
            if ($exitCode !== 0 || !is_file($base . '.pdf')
                || file_get_contents($base . '.pdf', false, null, 0, 5) !== '%PDF-') {
                throw new RuntimeException('PDF generation failed for student registration ' . (int)$student['id']
                    . ' (renderer exit ' . $exitCode . '): ' . implode("\n", array_slice($output, -10)));
            }
            if (!chmod($base . '.pdf', 0600)) {
                throw new RuntimeException('Could not protect temporary evaluation PDF.');
            }
            $pdfs[$folder . '/evaluations.pdf'] = $base . '.pdf';
            if ($onProgress !== null) {
                $onProgress($event + ['status' => 'ready', 'completed' => count($pdfs)]);
            }
        }
        if ($onProgress !== null) {
            $onProgress(['type' => 'packing', 'completed' => count($pdfs), 'total' => count($students)]);
        }
        $zipPath = $directory . DS . 'judges-evaluations.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create evaluation ZIP.');
        }
        try {
            foreach ($pdfs as $entry => $path) {
                if (!$zip->addFile($path, $entry)) {
                    throw new RuntimeException('Could not add evaluation PDF to ZIP.');
                }
            }
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('Could not finalize evaluation ZIP.');
            }
        }
        if (!is_file($zipPath) || filesize($zipPath) === 0) {
            throw new RuntimeException('Generated evaluation ZIP is empty.');
        }
        return $zipPath;
    }
}
