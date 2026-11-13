<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Utility\Security;

final class ResultExportToken
{
    public static function create(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function signature(
        string $token,
        int $schoolId,
        int $registrationId,
        int $conventionSeasonId,
        int $expires,
        string $zipName,
        string $exportDirectory,
    ): string {
        return hash_hmac(
            'sha256',
            implode('|', [$token, $schoolId, $registrationId, $conventionSeasonId, $expires, $zipName, $exportDirectory]),
            Security::getSalt(),
        );
    }

    public static function isValid(
        string $token,
        array $metadata,
        int $schoolId,
        int $registrationId,
        int $conventionSeasonId,
    ): bool {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)
            || (int)($metadata['school_id'] ?? 0) !== $schoolId
            || (int)($metadata['registration_id'] ?? 0) !== $registrationId
            || (int)($metadata['conventionseason_id'] ?? 0) !== $conventionSeasonId
            || (int)($metadata['expires'] ?? 0) <= time()
            || !self::isSafeZipName((string)($metadata['zip_name'] ?? ''))) {
            return false;
        }

        $signature = (string)($metadata['signature'] ?? '');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return false;
        }

        return hash_equals(
            self::signature(
                $token,
                $schoolId,
                $registrationId,
                $conventionSeasonId,
                (int)$metadata['expires'],
                (string)$metadata['zip_name'],
                (string)($metadata['export_dir'] ?? ''),
            ),
            $signature,
        );
    }

    public static function isSafeZipName(string $zipName): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9-]*\.zip\z/i', $zipName) === 1;
    }
}
