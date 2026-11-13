<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Scheduling\KnockoutBracketPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KnockoutBracketPlannerTest extends TestCase
{
    public static function byeCountProvider(): array
    {
        return [
            [0, 0, 0], [1, 1, 0], [2, 0, 1], [3, 1, 1], [4, 0, 2], [5, 3, 1],
            [6, 2, 2], [7, 1, 3], [8, 0, 4], [9, 7, 1], [16, 0, 8], [17, 15, 1],
            [24, 8, 8], [95, 33, 31], [128, 0, 64], [129, 127, 1],
        ];
    }

    #[DataProvider('byeCountProvider')]
    public function testByeAndRoundOneMatchCounts(int $entrants, int $byes, int $matches): void
    {
        $this->assertSame($byes, KnockoutBracketPlanner::byeCount($entrants));
        $this->assertSame($matches, KnockoutBracketPlanner::roundOneMatchCount($entrants));
        $this->assertSame($entrants, $byes + 2 * $matches, 'every entrant is placed exactly once in round 1');
    }

    public function testEmptyAndSingleEntrantProduceNoLaterRounds(): void
    {
        $this->assertSame([], KnockoutBracketPlanner::planLaterRounds([], true));
        $this->assertSame([], KnockoutBracketPlanner::planLaterRounds([['id' => 1, 'bye' => true]], true));
    }

    public function testTwoEntrantsIsJustTheFinal(): void
    {
        $this->assertSame([], KnockoutBracketPlanner::planLaterRounds($this->roundOne(2), true));
    }

    public function testThreeEntrantsHaveNoThirdPlaceBecauseOneSemiIsABye(): void
    {
        $plan = KnockoutBracketPlanner::planLaterRounds($this->roundOne(3), true, $this->inOrder());
        $this->assertCount(1, $plan);
        $this->assertFalse($plan[0]['third_place']);
        $this->assertSame(2, $plan[0]['round']);
    }

    public function testFourEntrantsGetFinalAndThirdPlaceThenStop(): void
    {
        $plan = KnockoutBracketPlanner::planLaterRounds($this->roundOne(4), true, $this->inOrder());
        $this->assertCount(2, $plan);
        $this->assertSame([2, 2], array_column($plan, 'round'));
        $this->assertSame([false, true], array_column($plan, 'third_place'));
        $this->assertSame([$plan[0]['feeder1'], $plan[0]['feeder2']], [$plan[1]['feeder1'], $plan[1]['feeder2']]);
    }

    public function testCheckersOpenRegressionHasNoPhantomRounds(): void
    {
        // Season 26 "Checkers OPEN": 8 matches + 8 byes previously generated rounds 5..16.
        $plan = KnockoutBracketPlanner::planLaterRounds($this->roundOne(24), true);
        $this->assertSame(5, max(array_column($plan, 'round')));
        $this->assertCount(15 + 1, $plan);
    }

    public function testNonPowerOfTwoRoundOneCarriesTheOddRowForward(): void
    {
        $rows = [['id' => 1, 'bye' => false], ['id' => 2, 'bye' => false], ['id' => 3, 'bye' => false]];
        $plan = KnockoutBracketPlanner::planLaterRounds($rows, true, $this->inOrder());

        $byes = array_values(array_filter($plan, fn($p) => $p['bye']));
        $this->assertCount(1, $byes);
        $this->assertNull($byes[0]['feeder2']);
        $finals = array_values(array_filter($plan, fn($p) => !$p['bye'] && !$p['third_place']));
        $this->assertSame(3, end($finals)['round']);
        $this->assertCount(0, array_filter($plan, fn($p) => $p['third_place']), 'a bye semi-final has no loser');
    }

    public static function entrantCountProvider(): array
    {
        $cases = [];
        foreach (range(2, 130) as $n) {
            $cases["$n entrants, 3rd place"] = [$n, true];
        }
        foreach ([2, 3, 4, 5, 7, 8, 11, 33, 64, 65] as $n) {
            $cases["$n entrants, no 3rd place"] = [$n, false];
        }

        return $cases;
    }

    #[DataProvider('entrantCountProvider')]
    public function testBracketStructureIsValid(int $entrants, bool $withThirdPlace): void
    {
        $roundOne = $this->roundOne($entrants);
        $plan = KnockoutBracketPlanner::planLaterRounds($roundOne, $withThirdPlace);

        $bracketSize = KnockoutBracketPlanner::bracketSize($entrants);
        $roundCount = KnockoutBracketPlanner::roundCount($entrants);
        $this->assertCount($bracketSize / 2, $roundOne);

        $rounds = [];
        foreach ($roundOne as $row) {
            $rounds[$row['id']] = 1;
        }

        $advancing = [];
        $thirdPlace = [];
        foreach ($plan as $planned) {
            $this->assertFalse($planned['bye'], 'power-of-two round 1 never needs a pass-through bye');
            foreach ([$planned['feeder1'], $planned['feeder2']] as $feeder) {
                $this->assertArrayHasKey($feeder, $rounds, 'feeders are created before the match that depends on them');
                $this->assertSame($planned['round'] - 1, $rounds[$feeder], 'feeders come from the previous round');
            }
            if ($planned['third_place']) {
                $thirdPlace[] = $planned;
            } else {
                $advancing[] = $planned['feeder1'];
                $advancing[] = $planned['feeder2'];
            }
            $rounds[$planned['key']] = $planned['round'];
        }

        $advancingCounts = array_count_values(array_map('strval', $advancing));
        $this->assertSame([], array_filter($advancingCounts, fn($count) => $count !== 1), 'each winner advances exactly once');
        $this->assertCount($bracketSize - 2, $advancing, 'every row except the Final feeds exactly one match');

        $this->assertCount($bracketSize / 2 - 1 + count($thirdPlace), $plan);
        $this->assertSame($roundCount, $plan ? max(array_column($plan, 'round')) : 1, 'the Final is the last round');
        $finalRound = array_filter($plan, fn($p) => $p['round'] === $roundCount && !$p['third_place']);
        $this->assertCount($entrants > 2 ? 1 : 0, $finalRound);

        $semisAreRealMatches = $roundCount > 2 || ($roundCount === 2 && KnockoutBracketPlanner::byeCount($entrants) === 0);
        $this->assertCount($withThirdPlace && $semisAreRealMatches ? 1 : 0, $thirdPlace);
        foreach ($thirdPlace as $third) {
            $this->assertSame($roundCount, $third['round']);
            $this->assertSame(array_key_last($plan), array_search($third, $plan, true), '3rd place is planned after the Final');
        }
    }

    /**
     * Round 1 rows laid out like the controller: byes first, then matches.
     */
    private function roundOne(int $entrants): array
    {
        $rows = [];
        $id = 100;
        for ($i = 0; $i < KnockoutBracketPlanner::byeCount($entrants); $i++) {
            $rows[] = ['id' => $id++, 'bye' => true];
        }
        for ($i = 0; $i < KnockoutBracketPlanner::roundOneMatchCount($entrants); $i++) {
            $rows[] = ['id' => $id++, 'bye' => false];
        }

        return $rows;
    }

    private function inOrder(): callable
    {
        return static fn(array $feeders): array => $feeders;
    }
}
