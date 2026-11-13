<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\GuidelineBreachPolicy;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

final class GuidelineBreachPolicyTest extends TestCase
{
    public function testPendingAndRejectedBreachesRemainEligible(): void
    {
        foreach ([0, 1, 3] as $status) {
            $submission = new Entity(['guideline_breach' => $status, 'guideline_breach_level' => 'major']);
            $this->assertTrue(GuidelineBreachPolicy::canJudge($submission));
            $this->assertTrue(GuidelineBreachPolicy::canPlace($submission));
            $this->assertFalse(GuidelineBreachPolicy::isExcludedFromResults($submission));
        }
    }

    public function testAcceptedMinorCanBeJudgedAndScoredButCannotPlace(): void
    {
        $submission = new Entity(['guideline_breach' => 2, 'guideline_breach_level' => 'minor']);

        $this->assertTrue(GuidelineBreachPolicy::canJudge($submission));
        $this->assertFalse(GuidelineBreachPolicy::canPlace($submission));
        $this->assertFalse(GuidelineBreachPolicy::isExcludedFromResults($submission));
        $this->assertFalse(GuidelineBreachPolicy::shouldRemoveFromDisplay($submission));
    }

    public function testAcceptedMajorAndExtremeCannotBeJudgedOrPlaced(): void
    {
        foreach (['major', 'extreme', null] as $level) {
            $submission = new Entity(['guideline_breach' => 2, 'guideline_breach_level' => $level]);
            $this->assertFalse(GuidelineBreachPolicy::canJudge($submission));
            $this->assertFalse(GuidelineBreachPolicy::canPlace($submission));
            $this->assertTrue(GuidelineBreachPolicy::isExcludedFromResults($submission));
        }
    }

    public function testExtremeItemsAreRemovedOnlyAfterTeamSelectsRemoval(): void
    {
        $submission = new Entity([
            'guideline_breach' => 2,
            'guideline_breach_level' => 'extreme',
            'guideline_breach_display_removed' => 1,
        ]);
        $this->assertTrue(GuidelineBreachPolicy::shouldRemoveFromDisplay($submission));

        $submission->set('guideline_breach_display_removed', 0);
        $this->assertFalse(GuidelineBreachPolicy::shouldRemoveFromDisplay($submission));
    }

    public function testOnlyConfiguredLevelsAreAccepted(): void
    {
        $this->assertSame(['minor', 'major', 'extreme'], GuidelineBreachPolicy::levels());
        $this->assertTrue(GuidelineBreachPolicy::validLevel('major'));
        $this->assertFalse(GuidelineBreachPolicy::validLevel('critical'));
    }
}
