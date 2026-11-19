<?php
namespace App\Service;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use InvalidArgumentException;

class PermissionFormSubmissions
{
    private string $metadataFile;
    private string $uploadDirectory;

    public function __construct(?string $root = null)
    {
        $root ??= TMP . 'permission-form-submissions';
        $this->metadataFile = $root . DS . 'submissions.json';
        $this->uploadDirectory = $root . DS . 'files';
    }

    public function submit(
        UploadedFileInterface $file,
        string $formType,
        int $userId,
        int $registrationId,
        int $conventionSeasonId,
        string $schoolName
    ): array {
        $allowedTypes = [
            'hssp_students' => 'HSSP Students Form',
            'schools' => 'Schools Form',
            'adult_registration' => 'Adult Registration Form',
        ];
        if (!isset($allowedTypes[$formType])) {
            throw new InvalidArgumentException('Please choose a valid permission form type.');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('The permission form could not be uploaded.');
        }

        $originalName = trim((string)$file->getClientFilename());
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $size = (int)$file->getSize();
        if ($extension !== 'pdf' || $size <= 0 || $size > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Upload a PDF permission form no larger than 10 MB.');
        }

        $temporaryPath = (string)$file->getStream()->getMetadata('uri');
        $mimeType = is_file($temporaryPath) ? (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath) : '';
        if ($mimeType !== 'application/pdf') {
            throw new InvalidArgumentException('The uploaded file is not a valid PDF.');
        }

        $this->ensureDirectories();
        $id = bin2hex(random_bytes(16));
        $storedName = $id . '.pdf';
        $file->moveTo($this->uploadDirectory . DS . $storedName);

        $submission = [
            'id' => $id,
            'form_type' => $formType,
            'form_label' => $allowedTypes[$formType],
            'user_id' => $userId,
            'conventionregistration_id' => $registrationId,
            'conventionseason_id' => $conventionSeasonId,
            'school_name' => $schoolName,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'size' => $size,
            'submitted_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $this->append($submission);
        } catch (\Throwable $exception) {
            @unlink($this->uploadDirectory . DS . $storedName);
            throw $exception;
        }

        return $submission;
    }

    public function all(): array
    {
        if (!is_file($this->metadataFile)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($this->metadataFile), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Permission form submission metadata is invalid.');
        }

        return $decoded;
    }

    public function forSeason(int $conventionSeasonId): array
    {
        if ($conventionSeasonId <= 0) {
            return [];
        }

        return array_values(array_filter($this->all(), static function (array $submission) use ($conventionSeasonId): bool {
            return (int)($submission['conventionseason_id'] ?? 0) === $conventionSeasonId;
        }));
    }

    public function forRegistration(int $registrationId): array
    {
        return array_values(array_filter($this->all(), static function (array $submission) use ($registrationId): bool {
            return (int)($submission['conventionregistration_id'] ?? 0) === $registrationId;
        }));
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $submission) {
            if (hash_equals((string)($submission['id'] ?? ''), $id)) {
                return $submission;
            }
        }

        return null;
    }

    public function filePath(array $submission): string
    {
        $storedName = basename((string)($submission['stored_name'] ?? ''));
        $path = $this->uploadDirectory . DS . $storedName;
        if ($storedName === '' || !is_file($path)) {
            throw new RuntimeException('The submitted permission form file is missing.');
        }

        return $path;
    }

    private function ensureDirectories(): void
    {
        foreach ([dirname($this->metadataFile), $this->uploadDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create permission form storage.');
            }
        }
    }

    private function append(array $submission): void
    {
        $this->ensureDirectories();
        $handle = fopen($this->metadataFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open permission form submission storage.');
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Unable to lock permission form submission storage.');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $submissions = $contents !== '' ? json_decode($contents, true) : [];
        if (!is_array($submissions)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Permission form submission metadata is invalid.');
        }
        array_unshift($submissions, $submission);
        $encoded = json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Unable to encode permission form submissions.');
        }

        ftruncate($handle, 0);
        rewind($handle);
        $written = fwrite($handle, $encoded);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if ($written === false || $written !== strlen($encoded)) {
            throw new RuntimeException('Unable to save permission form submission metadata.');
        }
    }
}
