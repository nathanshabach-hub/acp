<?php
declare(strict_types=1);

namespace App\Utility;

final class JudgingEventCategories
{
    public static function isPreJudged(string $division, string $event): bool
    {
        $normalize = static function (string $value): string {
            $value = str_replace('&', 'and', strtolower($value));
            return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $value));
        };
        return in_array($normalize($division), ['photography', 'design', 'technology', 'design and technology'], true)
            || in_array($normalize($event), [
                'poetry writing u16', 'poetry writing open', 'persuasive essay writing open',
                'short story writing open', 'short story writing u16', 'music vocal composition u16',
                'music vocal composition open', 'music vocal arranging u16', 'music vocal arranging open',
                'book report u16', 'book report open',
            ], true);
    }
}
