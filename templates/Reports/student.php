<?php
$student = $report['student'];
$totals = $report['totals'];
$name = trim(implode(' ', array_filter([$student['first_name'], $student['middle_name'], $student['last_name']])));
$placeLabels = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', 6 => '6th'];
$chart = ['labels' => [], 'events' => [], 'placements' => array_fill(1, 6, [])];
foreach ($report['conventions'] as $convention) {
    $chart['labels'][] = $convention['name'] . ' ' . $convention['year'];
    $chart['events'][] = $convention['events'];
    foreach ($placeLabels as $place => $label) {
        $chart['placements'][$place][] = !$convention['released'] || $convention['conflicts']
            ? null : $convention['placements'][$place];
    }
}
?>
<?= $this->Html->css('front/reports.css?v=20261112b') ?>
<div class="container-fluid p-0"><div class="row">
    <?= $this->element('user_left_menu') ?>
    <main id="portal-main" class="col-md-9 ms-sm-auto col-lg-10 px-md-4 portal-main" tabindex="-1">
        <div class="portal-page-heading">
            <div><p class="portal-eyebrow">Your workspace / Reports / Student preview</p><h1><?= h($name) ?></h1><p>Student #<?= (int)$student['id'] ?></p></div>
            <?= $this->Html->link('Back to Reports', '/reports', ['class' => 'btn btn-outline-primary']) ?>
        </div>
        <p>This report shows the student's convention history. The 1st to 6th place totals count only officially published awards, including individual awards, group awards and awards issued automatically.</p>
        <div class="report-cards">
            <div class="portal-panel"><strong><?= (int)$totals['conventions'] ?></strong><span>Conventions entered</span></div>
            <div class="portal-panel"><strong><?= (int)$totals['events'] ?></strong><span>Event entries</span></div>
            <?php foreach ($placeLabels as $place => $label) { ?>
                <div class="portal-panel"><strong><?= (int)$totals['placements'][$place] ?></strong><span><?= h($label) ?> place (published)</span></div>
            <?php } ?>
        </div>
        <?php if (array_filter($report['conventions'], static fn($c) => !$c['released'])) { ?>
            <p class="alert alert-info">Some convention results are not released. Their placements are excluded from totals and shown as gaps on the graph, not zero.</p>
        <?php } ?>
        <?php if ($totals['conflicts']) { ?>
            <p class="alert alert-warning" role="status"><?= (int)$totals['conflicts'] ?> event result(s) need review because saved placements disagree. Conflicting awards are excluded; placement totals are incomplete. No results have been changed.</p>
        <?php } ?>
        <?php if ($totals['awards_without_entries']) { ?>
            <p class="alert alert-warning"><?= (int)$totals['awards_without_entries'] ?> published award(s) have no matching event-entry record. Saved awards are included, but event-entry totals have not been increased.</p>
        <?php } ?>
        <p>Published automatic awards included: <?= (int)$totals['automatic_awards'] ?>. They are labelled in the result details below.</p>
        <p>Combined-school awards require verified student membership and an approved request. Incomplete or ambiguous historical links are excluded.</p>
        <section class="portal-panel" aria-labelledby="performance-chart-title">
            <h2 id="performance-chart-title">Performance over conventions</h2>
            <div id="report-series" class="report-series" aria-label="Graph series"></div>
            <div class="report-chart"><canvas id="performance-chart" role="img" aria-label="Line graph of event entries and published placements. Detailed values are in the convention history table."></canvas></div>
            <noscript><p>The interactive graph requires JavaScript. All results are available in the table below.</p></noscript>
            <p id="report-chart-error" class="alert alert-warning" hidden>The graph could not load. Use the convention history table below.</p>
        </section>
        <section class="portal-panel">
            <h2>Convention history</h2>
            <div class="table-responsive">
                <table class="table table-striped">
                    <caption>Event entries and published placements by convention</caption>
                    <thead><tr><th scope="col">Convention</th><th scope="col">Events</th><?php foreach ($placeLabels as $label) { ?><th scope="col"><?= h($label) ?></th><?php } ?><th scope="col">Result status</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['conventions'] as $convention) { ?>
                        <tr>
                            <th scope="row"><?= h($convention['name']) ?> <?= (int)$convention['year'] ?></th>
                            <td><?= (int)$convention['events'] ?></td>
                            <?php foreach ($placeLabels as $place => $label) { ?><td><?= $convention['released'] ? (int)$convention['placements'][$place] : '&mdash;' ?></td><?php } ?>
                            <td><?= !$convention['released'] ? 'Results not released' : ($convention['conflicts'] ? 'Needs review; counts incomplete' : 'Published') ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php foreach ($report['conventions'] as $convention) {
                if (!$convention['released'] || !$convention['awards']) { continue; }
            ?>
                <details class="report-awards">
                    <summary><?= h($convention['name']) ?> <?= (int)$convention['year'] ?>: published award details</summary>
                    <ul><?php foreach ($convention['awards'] as $award) { ?>
                        <li><?= h($award['event']) ?> &mdash; <?= h($placeLabels[$award['position']]) ?><?= $award['automatic'] ? ' (automatic award)' : '' ?></li>
                    <?php } ?></ul>
                </details>
            <?php } ?>
        </section>
    </main>
</div></div>
<script id="report-chart-data" type="application/json"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<?= $this->Html->script('vendor/chartjs-4.4.8/chart.umd.js') ?>
<?= $this->Html->script('front/student-reports.js') ?>
