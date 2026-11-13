<?php
use App\Service\EventGuidelines;
use App\Service\GuidelineTextFormatter;

$guidelineSections = EventGuidelines::forEvent((string)$eventD->event_id_number);
$guidelineTitle = preg_replace('/\s+(?:OPEN|U16)$/i', '', (string)$eventD->event_name) . ' Guidelines';
?>
<style>
.judging-form-page .event-guidelines-text {
    overflow-wrap: anywhere;
    line-height: 1.75;
    font-size: 15px;
    max-width: 78ch;
    color: #233747;
}
.judging-form-page .event-guidelines-text p {
    margin: 0 0 1em;
}
.judging-form-page .event-guidelines-text h5 {
    font-size: 15px;
    font-weight: 700;
    margin: 1.5em 0 .65em;
    line-height: 1.5;
}
.judging-form-page .event-guidelines-text ol,
.judging-form-page .event-guidelines-text ul {
    padding-left: 1.75em;
    margin: 0 0 1em;
}
.judging-form-page .event-guidelines-text li {
    padding-left: .35em;
    margin-bottom: .65em;
}
.judging-form-page .event-guidelines-text ol > li {
    list-style-type: decimal;
}
.judging-form-page .event-guidelines-text ul > li {
    list-style-type: disc;
}
.judging-form-page .event-guidelines-section h4,
.judging-form-page .event-guidelines-section summary {
    font-size: 17px;
    font-weight: 600;
    line-height: 1.5;
}
.judging-form-page .event-guidelines-section summary {
    cursor: pointer;
    color: #087f78;
}
.judging-form-page .event-guidelines-section details[open] summary {
    margin-bottom: 16px;
}
.judging-form-page .event-guidelines-section + .event-guidelines-section {
    border-top: 1px solid #e2e8ec;
    margin-top: 20px;
    padding-top: 20px;
}
.judging-form-page .event-guidelines-source {
    display: block;
    margin-top: 12px;
    font-size: 12px;
    overflow-wrap: anywhere;
}
</style>
<div class="accordion mb-3" id="eventGuidelines">
    <div class="accordion-item">
        <h3 class="accordion-header" id="eventGuidelinesHeading">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                data-bs-target="#eventGuidelinesContent" aria-expanded="false"
                aria-controls="eventGuidelinesContent">
                <?= h($guidelineTitle) ?>
            </button>
        </h3>
        <div id="eventGuidelinesContent" class="accordion-collapse collapse"
            aria-labelledby="eventGuidelinesHeading">
            <div class="accordion-body">
                <?php if (!$guidelineSections) { ?>
                    <div class="alert alert-warning" role="alert">
                        Guidelines have not yet been mapped for this event. Please contact the Events Team for the correct guidelines.
                    </div>
                <?php } ?>
                <?php
                // Keep event requirements and checklists ahead of lengthy shared rules.
                usort($guidelineSections, static fn(array $a, array $b): int =>
                    (int)str_contains(strtolower($a['title']), 'general guidelines')
                    <=> (int)str_contains(strtolower($b['title']), 'general guidelines'));
                foreach ($guidelineSections as $section) {
                    $isGeneral = str_contains(strtolower($section['title']), 'general guidelines');
                ?>
                    <section class="event-guidelines-section">
                        <?php if (!empty($section['notice'])) { ?>
                            <div class="alert alert-warning" role="alert"><?= h($section['notice']) ?></div>
                        <?php } ?>
                        <?php if ($isGeneral) { ?>
                            <details>
                                <summary><?= h($section['title']) ?></summary>
                        <?php } else { ?>
                            <h4><?= h($section['title']) ?></h4>
                        <?php } ?>
                        <div class="event-guidelines-text">
                            <?php
                            $listType = null;
                            foreach (GuidelineTextFormatter::blocks($section['text'], $section['title']) as $block) {
                                $isList = in_array($block['type'], ['ordered', 'bullet', 'checklist'], true);
                                if ($listType !== null && (!$isList || $listType !== $block['type'])) {
                                    echo $listType === 'ordered' ? '</ol>' : '</ul>';
                                    $listType = null;
                                }
                                if ($isList) {
                                    if ($listType === null) {
                                        echo $block['type'] === 'ordered' ? '<ol>' : '<ul'
                                            . ($block['type'] === 'checklist' ? ' class="event-guidelines-checklist"' : '') . '>';
                                        $listType = $block['type'];
                                    }
                                    echo '<li' . (isset($block['number']) ? ' value="' . (int)$block['number'] . '"' : '') . '>'
                                        . h($block['text']) . '</li>';
                                } else {
                                    $tag = $block['type'] === 'heading' ? 'h5' : 'p';
                                    echo '<' . $tag . '>' . h($block['text']) . '</' . $tag . '>';
                                }
                            }
                            if ($listType !== null) {
                                echo $listType === 'ordered' ? '</ol>' : '</ul>';
                            }
                            ?>
                        </div>
                        <?= $this->Html->link(
                            'Source PDF: ' . $section['pdf'] . ' (pages ' . implode(', ', $section['pages']) . ')',
                            '/files/guidelines/' . $section['pdf'] . '#page=' . $section['pages'][0],
                            ['target' => '_blank', 'rel' => 'noopener', 'class' => 'event-guidelines-source']
                        ) ?>
                        <?php if ($isGeneral) { ?></details><?php } ?>
                    </section>
                <?php } ?>
                <?= $this->Html->link(
                    'General convention guidelines (PDF)',
                    '/files/guidelines/01-Intro-2024.pdf',
                    ['target' => '_blank', 'rel' => 'noopener', 'class' => 'event-guidelines-source']
                ) ?>
            </div>
        </div>
    </div>
</div>
