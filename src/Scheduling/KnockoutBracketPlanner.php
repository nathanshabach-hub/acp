<?php
declare(strict_types=1);

namespace App\Scheduling;

/**
 * Pure single-elimination bracket maths shared by the category 2 (individual)
 * and category 3 (group) schedulers. It has no database access so it can be
 * unit tested; the controller persists the planned rows.
 */
final class KnockoutBracketPlanner
{
    public static function bracketSize(int $entrants): int
    {
        if ($entrants <= 1) {
            return max(0, $entrants);
        }

        $size = 1;
        while ($size < $entrants) {
            $size *= 2;
        }

        return $size;
    }

    /**
     * Round 1 byes needed to fill the bracket. A lone entrant gets a single bye
     * (walkover) so it still appears in the draw without a fake opponent.
     */
    public static function byeCount(int $entrants): int
    {
        if ($entrants <= 0) {
            return 0;
        }
        if ($entrants === 1) {
            return 1;
        }

        return self::bracketSize($entrants) - $entrants;
    }

    public static function roundOneMatchCount(int $entrants): int
    {
        return intdiv(max(0, $entrants - self::byeCount($entrants)), 2);
    }

    /**
     * Number of rounds including round 1 (the Final is the last round).
     */
    public static function roundCount(int $entrants): int
    {
        if ($entrants <= 1) {
            return max(0, $entrants);
        }

        return (int)round(log(self::bracketSize($entrants), 2));
    }

    /**
     * Plans every round after round 1, stopping after the Final.
     *
     * Each planned row references its feeders either by a round 1 row id (int)
     * or by the `key` of an earlier planned row (string). The 3rd-place match
     * is planned after the Final in the same round, and only when both
     * semi-finals are real matches (a bye semi-final has no loser).
     *
     * @param list<array{id:int, bye:bool}> $roundOneRows Round 1 rows (byes and matches).
     * @param bool $withThirdPlace Whether to add a 3rd-place playoff.
     * @param callable|null $orderFeeders fn(list<array> $feeders): list<array> - pairing order,
     *   defaults to a random shuffle (the existing random draw).
     * @return list<array{key:string, round:int, feeder1:int|string, feeder2:int|string|null, bye:bool, third_place:bool}>
     */
    public static function planLaterRounds(array $roundOneRows, bool $withThirdPlace, ?callable $orderFeeders = null): array
    {
        $orderFeeders ??= static function (array $feeders): array {
            shuffle($feeders);

            return $feeders;
        };

        $current = [];
        foreach ($roundOneRows as $row) {
            $current[] = ['ref' => (int)$row['id'], 'has_loser' => empty($row['bye'])];
        }

        $plan = [];
        $keyCounter = 0;
        $round = 1;
        while (count($current) > 1) {
            $isSemiFinalRound = count($current) === 2;
            $ordered = array_values($orderFeeders($current));
            $next = [];

            while (count($ordered) >= 2) {
                $first = array_shift($ordered);
                $second = array_shift($ordered);

                $key = 'p' . $keyCounter++;
                $plan[] = [
                    'key' => $key,
                    'round' => $round + 1,
                    'feeder1' => $first['ref'],
                    'feeder2' => $second['ref'],
                    'bye' => false,
                    'third_place' => false,
                ];
                $next[] = ['ref' => $key, 'has_loser' => true];

                if ($isSemiFinalRound && $withThirdPlace && $first['has_loser'] && $second['has_loser']) {
                    $plan[] = [
                        'key' => 'p' . $keyCounter++,
                        'round' => $round + 1,
                        'feeder1' => $first['ref'],
                        'feeder2' => $second['ref'],
                        'bye' => false,
                        'third_place' => true,
                    ];
                }
            }

            // Only reachable if round 1 is not a power of two; carry the odd row forward.
            if (count($ordered) === 1) {
                $odd = array_shift($ordered);
                $key = 'p' . $keyCounter++;
                $plan[] = [
                    'key' => $key,
                    'round' => $round + 1,
                    'feeder1' => $odd['ref'],
                    'feeder2' => null,
                    'bye' => true,
                    'third_place' => false,
                ];
                $next[] = ['ref' => $key, 'has_loser' => false];
            }

            $current = $next;
            $round++;
        }

        return $plan;
    }
}
