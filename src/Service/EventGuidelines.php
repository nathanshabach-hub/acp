<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
use RuntimeException;

final class EventGuidelines
{
    private static ?array $catalog = null;

    /**
     * @return list<array{title: string, pdf: string, pages: list<int>, text: string, notice?: string}>
     */
    public static function forEvent(string $eventNumber): array
    {
        if (!ctype_digit($eventNumber)) {
            throw new \InvalidArgumentException('A numeric event number is required for guidelines.');
        }

        if (self::$catalog === null) {
            $json = file_get_contents(CONFIG . 'event_guidelines.json');
            if ($json === false) {
                throw new RuntimeException('Unable to read the event guideline catalog.');
            }
            self::$catalog = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        $number = (string)(int)$eventNumber;
        if (!isset(self::$catalog['events'][$number])) {
            Log::warning('No guideline mapping is available for event ' . $number . '.');
            return [];
        }

        $sections = [];
        foreach (self::$catalog['events'][$number] as $key) {
            if (!isset(self::$catalog['sections'][$key])) {
                throw new RuntimeException('Missing guideline section: ' . $key);
            }
            $sections[] = self::$catalog['sections'][$key];
        }
        if (isset(self::$catalog['event_notices'][$number])) {
            $sections[0]['notice'] = self::$catalog['event_notices'][$number];
        }

        return $sections;
    }
}
