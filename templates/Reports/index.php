<?= $this->Html->css('front/reports.css?v=20261112b') ?>
<div class="container-fluid p-0"><div class="row">
    <?= $this->element('user_left_menu') ?>
    <main id="portal-main" class="col-md-9 ms-sm-auto col-lg-10 px-md-4 portal-main" tabindex="-1">
        <div class="portal-page-heading">
            <div><p class="portal-eyebrow">Your workspace / Reports</p><h1>Student Performance Reports</h1></div>
        </div>
        <section class="portal-panel">
            <form method="get" action="<?= h($this->Url->build('/reports')) ?>" class="report-search">
                <label for="report-search">Student name or student number</label>
                <input id="report-search" class="form-control" name="q" maxlength="100" value="<?= h($listing['search']) ?>">
                <button class="btn btn-primary" type="submit">Search</button>
                <?= $this->Html->link('Clear', '/reports', ['class' => 'btn btn-outline-secondary']) ?>
            </form>
            <p><?= (int)$listing['total'] ?> student(s) with convention registrations.</p>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <caption>Student convention participation</caption>
                    <thead><tr><th scope="col">Student</th><th scope="col">Conventions entered</th><th scope="col">Event entries</th><th scope="col">Report</th></tr></thead>
                    <tbody>
                    <?php foreach ($listing['students'] as $student) {
                        $name = trim(implode(' ', array_filter([$student['first_name'], $student['middle_name'], $student['last_name']])));
                    ?>
                        <tr>
                            <td><?= h($name) ?></td>
                            <td><?= (int)$student['conventions'] ?></td><td><?= (int)$student['events'] ?></td>
                            <td><?= $this->Html->link('Preview', ['controller' => 'Reports', 'action' => 'student', 'studentId' => $student['id']], ['class' => 'btn btn-sm btn-outline-primary', 'aria-label' => 'Preview report for ' . $name]) ?></td>
                        </tr>
                    <?php } ?>
                    <?php if (!$listing['students']) { ?><tr><td colspan="4">No matching registered students.</td></tr><?php } ?>
                    </tbody>
                </table>
            </div>
            <nav aria-label="Report pages" class="report-pagination">
                <?php if ($listing['page'] > 1) {
                    echo $this->Html->link('Previous', ['controller' => 'Reports', 'action' => 'index', '?' => ['q' => $listing['search'], 'page' => $listing['page'] - 1, 'limit' => $listing['limit']]], ['class' => 'btn btn-outline-primary']);
                } ?>
                <span>Page <?= (int)$listing['page'] ?> of <?= (int)$listing['pages'] ?></span>
                <?php if ($listing['page'] < $listing['pages']) {
                    echo $this->Html->link('Next', ['controller' => 'Reports', 'action' => 'index', '?' => ['q' => $listing['search'], 'page' => $listing['page'] + 1, 'limit' => $listing['limit']]], ['class' => 'btn btn-outline-primary']);
                } ?>
            </nav>
        </section>
    </main>
</div></div>
