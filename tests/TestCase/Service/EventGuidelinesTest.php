<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\EventGuidelines;
use Cake\TestSuite\TestCase;

final class EventGuidelinesTest extends TestCase
{
    public function testEveryPublishedEventHasReadableGuidelinesAndLocalSources(): void
    {
        $catalog = json_decode(file_get_contents(CONFIG . 'event_guidelines.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys($catalog['events']) as $number) {
            $sections = EventGuidelines::forEvent((string)$number);
            $this->assertNotEmpty($sections, 'Event ' . $number);
            foreach ($sections as $section) {
                $this->assertGreaterThan(40, strlen($section['text']));
                $this->assertStringNotContainsString("\u{FFFD}", $section['text']);
                $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0b-\x1f]/', $section['text']);
                $this->assertFileExists(WWW_ROOT . 'files/guidelines/' . $section['pdf']);
                $this->assertNotEmpty($section['pages']);
                $this->assertGreaterThan(0, min($section['pages']));
            }
        }
    }

    public function testLeadingZeroesDoNotChangeTheGuidelines(): void
    {
        $this->assertSame(EventGuidelines::forEvent('3'), EventGuidelines::forEvent('003'));
    }

    public function testSharedFormsStillResolveEventSpecificRules(): void
    {
        $this->assertSame('Oil', EventGuidelines::forEvent('500')[0]['title']);
        $this->assertSame('Watercolour', EventGuidelines::forEvent('501')[0]['title']);
        $this->assertStringStartsWith('Ensemble (5 minimum', EventGuidelines::forEvent('870')[0]['title']);
        $this->assertStringStartsWith('Choir', EventGuidelines::forEvent('871')[0]['title']);
        $this->assertSame('Bible Bowl', EventGuidelines::forEvent('1057')[0]['title']);
        $this->assertSame('Spelling rules and word lists', EventGuidelines::forEvent('003')[0]['title']);
        $this->assertStringStartsWith('Ensembles', EventGuidelines::forEvent('716')[0]['title']);
        $this->assertSame('Music Instrumental Arranging', EventGuidelines::forEvent('718')[0]['title']);
    }

    public function testRadioPlayAndChoirRetainTheirRulesAndChecklists(): void
    {
        $radio = implode("\n", array_column(EventGuidelines::forEvent('467'), 'text'));
        $this->assertStringContainsString('four (4) minutes minimum and eight (8) minutes', $radio);
        $this->assertStringContainsString('TALENT RELEASE FORM', $radio);
        $this->assertStringContainsString('Christian radio station', str_replace("\n", ' ', $radio));

        $choir = implode("\n", array_column(EventGuidelines::forEvent('871'), 'text'));
        $this->assertStringContainsString('16 minimum - 40 maximum', $choir);
        $this->assertStringContainsString('CHECKLIST FOR MUSIC VOCAL EVENTS', $choir);
    }

    public function testSourceAmbiguitiesAreExplicit(): void
    {
        $online = EventGuidelines::forEvent('764');
        $this->assertStringContainsString('no separate rules', $online[0]['notice']);
        $instrumental = EventGuidelines::forEvent('780');
        $this->assertStringContainsString('conflicting accompaniment', $instrumental[1]['notice']);
        $this->assertSame('09-Music-Instrumental-2026-Lou-edit.pdf', $instrumental[0]['pdf']);
    }

    public function testInvalidEventNumbersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventGuidelines::forEvent('not-an-event');
    }
}
