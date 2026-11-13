<?php
declare(strict_types=1);

namespace App\Service;

final class ResultPackageAccess
{
    public static function schoolOwnsRegistration(
        int $sessionUserId,
        string $sessionUserType,
        ?int $activeSchoolId,
        ?int $registrationOwnerId,
    ): bool {
        return $sessionUserId > 0
            && $sessionUserType === 'School'
            && $activeSchoolId === $sessionUserId
            && $registrationOwnerId === $sessionUserId;
    }

    public static function registrationIsPublished(mixed $registration): bool
    {
        if (!is_object($registration) || !method_exists($registration, 'get')) {
            return false;
        }

        $season = $registration->get('Conventionseasons');
        $convention = $registration->get('Conventions');
        if (!is_object($season) || !method_exists($season, 'get')
            || !is_object($convention) || !method_exists($convention, 'get')) {
            return false;
        }

        $registrationFields = ['id', 'conventionseason_id', 'convention_id', 'season_id', 'season_year'];
        foreach ($registrationFields as $field) {
            if (!is_numeric($registration->get($field))) {
                return false;
            }
        }
        $seasonFields = ['id', 'convention_id', 'season_id', 'season_year'];
        foreach ($seasonFields as $field) {
            if (!is_numeric($season->get($field))) {
                return false;
            }
        }
        if (!is_numeric($convention->get('id'))) {
            return false;
        }

        return (int)$registration->get('id') > 0
            && (int)$registration->get('conventionseason_id') === (int)$season->get('id')
            && (int)$registration->get('convention_id') === (int)$season->get('convention_id')
            && (int)$registration->get('convention_id') === (int)$convention->get('id')
            && (int)$registration->get('season_id') === (int)$season->get('season_id')
            && (int)$registration->get('season_year') === (int)$season->get('season_year')
            && ($season->get('results_release') === 1 || $season->get('results_release') === '1');
    }
}
