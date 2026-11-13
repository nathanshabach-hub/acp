<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Controller\Admin\SchedulingreportsController;
use PHPUnit\Framework\TestCase;

class SmallProgramGroupingTest extends TestCase
{
    private function group(array $names): array
    {
        $controller = (new \ReflectionClass(SchedulingreportsController::class))->newInstanceWithoutConstructor();

        return (function () use ($names) {
            return $this->groupSmallProgramAgeDivisions($names);
        })->call($controller);
    }

    public function testGroupsRequestedMusicFamiliesWithYoungerDivisionsFirst(): void
    {
        foreach (['Male', 'Female', 'Mixed', 'Tambourine'] as $family) {
            foreach (['Duet', 'Trio', 'Quartet'] as $size) {
                $base = $family.' '.$size;
                $this->assertSame([$base.' U16 & OPEN'], $this->group([
                    $base.' OPEN', $base.' U16', $base.' OPEN',
                ]));
            }
        }
    }

    public function testGroupsTableTennisDivisionsNumericallyWithoutCombiningGenders(): void
    {
        $this->assertSame([
            'Table Tennis - Doubles (Female) U14, U17, OPEN',
            'Table Tennis - Doubles (Male) U14, U17, OPEN',
        ], $this->group([
            'Table Tennis - Doubles (Female) OPEN',
            'Table Tennis - Doubles (Male) U17',
            'Table Tennis - Doubles (Female) U17',
            'Table Tennis - Doubles (Female) U14',
            'Table Tennis - Doubles (Male) OPEN',
            'Table Tennis - Doubles (Male) U14',
        ]));
    }

    public function testPreservesUnrelatedEventsSingleDivisionsAndFamilyBoundaries(): void
    {
        $this->assertSame([
            'Female Trio U16 & OPEN',
            'Male Trio U16',
            'Mixed Trio OPEN',
            'Tambourine Trio U16',
            'Piano Solo OPEN',
            'Piano Solo U16',
            'Custom announcement',
        ], $this->group([
            'Female Trio OPEN',
            'Male Trio U16',
            'Female Trio U16',
            'Mixed Trio OPEN',
            'Tambourine Trio U16',
            'Piano Solo OPEN',
            'Piano Solo U16',
            'Custom announcement',
        ]));
        $this->assertSame([], $this->group([]));
    }
}
