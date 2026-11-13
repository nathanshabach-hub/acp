<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\GuidelineTextFormatter;
use Cake\TestSuite\TestCase;

final class GuidelineTextFormatterTest extends TestCase
{
    public function testPdfLinesBecomeParagraphsAndLists(): void
    {
        $blocks = GuidelineTextFormatter::blocks(
            "Choir\nTime limit: five minutes.\n\nA choir may have a conductor,\n\nhowever, the conductor is not counted.\n\n"
            . "RULES\n1.\nFirst rule spans\nmultiple lines.\n\n2. Second rule.\n\n"
            . "CHECKLIST\n□\nOne copy of the\narrangement.\n\n•\nBring a spare.",
            'Choir'
        );
        $this->assertSame([
            ['type' => 'paragraph', 'text' => 'Time limit: five minutes.'],
            ['type' => 'paragraph', 'text' => 'A choir may have a conductor, however, the conductor is not counted.'],
            ['type' => 'heading', 'text' => 'RULES'],
            ['type' => 'ordered', 'text' => 'First rule spans multiple lines.', 'number' => 1],
            ['type' => 'ordered', 'text' => 'Second rule.', 'number' => 2],
            ['type' => 'heading', 'text' => 'CHECKLIST'],
            ['type' => 'checklist', 'text' => 'One copy of the arrangement.'],
            ['type' => 'bullet', 'text' => 'Bring a spare.'],
        ], $blocks);
    }

    public function testEntitiesAreDecodedAsTextAndNumbersArePreserved(): void
    {
        $this->assertSame([
            ['type' => 'heading', 'text' => 'MUSIC & PAPERWORK'],
            ['type' => 'ordered', 'text' => '<script>alert(1)</script>', 'number' => 35],
        ], GuidelineTextFormatter::blocks("MUSIC &amp; PAPERWORK\n35. &lt;script&gt;alert(1)&lt;/script&gt;"));
    }
}
