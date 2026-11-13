<?php
declare(strict_types=1);

namespace App\Service;

final class GuidelineBreachPolicy
{
    public const LEVEL_MINOR = 'minor';
    public const LEVEL_MAJOR = 'major';
    public const LEVEL_EXTREME = 'extreme';

    public const STATUS_NONE = 0;
    public const STATUS_PENDING = 1;
    public const STATUS_ACCEPTED = 2;
    public const STATUS_REJECTED = 3;

    public static function levels(): array
    {
        return [self::LEVEL_MINOR, self::LEVEL_MAJOR, self::LEVEL_EXTREME];
    }

    public static function validLevel(mixed $level): bool
    {
        return in_array($level, self::levels(), true);
    }

    public static function isAccepted(object|array $submission): bool
    {
        return (int)self::value($submission, 'guideline_breach') === self::STATUS_ACCEPTED;
    }

    public static function canJudge(object|array $submission): bool
    {
        if (!self::isAccepted($submission)) {
            return true;
        }

        return self::value($submission, 'guideline_breach_level') === self::LEVEL_MINOR;
    }

    public static function canPlace(object|array $submission): bool
    {
        return !self::isAccepted($submission);
    }

    public static function isExcludedFromResults(object|array $submission): bool
    {
        return self::isAccepted($submission)
            && self::value($submission, 'guideline_breach_level') !== self::LEVEL_MINOR;
    }

    public static function shouldRemoveFromDisplay(object|array $submission): bool
    {
        return self::isAccepted($submission)
            && self::value($submission, 'guideline_breach_level') === self::LEVEL_EXTREME
            && (int)self::value($submission, 'guideline_breach_display_removed') === 1;
    }

    private static function value(object|array $submission, string $field): mixed
    {
        return is_array($submission)
            ? ($submission[$field] ?? null)
            : ($submission->{$field} ?? null);
    }
}
