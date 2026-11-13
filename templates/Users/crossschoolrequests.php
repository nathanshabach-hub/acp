<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main id="portal-main" class="col-md-9 ms-sm-auto col-lg-10 px-md-4 portal-main" tabindex="-1">
            <div class="ersu_message"><?= $this->Flash->render() ?></div>
            <div class="portal-page-heading"><div><p class="portal-eyebrow">Your workspace</p><h1>Sponsorship Requests</h1></div></div>
            <?php if (!$available): ?>
                <div class="alert alert-warning">Cross-school requests are unavailable until the database migration is applied. Please contact the events team.</div>
            <?php else: ?>
                <div class="alert alert-warning">
                    These requests are pending only. School approval and supervisor acceptance are not available.
                    Students do not yet have an accepted sponsor, and supervisor capacity is not reserved.
                </div>
                <?php if (!$requests): ?>
                    <p>There are no pending sponsorship requests.</p>
                <?php endif; ?>
                <?php foreach ($requests as $entry): ?>
                    <?php $isOrigin = (int)$entry['origin_school_id'] === (int)$school->id; ?>
                    <section class="card mb-3" data-request-id="<?= (int)$entry['id'] ?>"><div class="card-body">
                        <h2 class="h5"><?= h($entry['convention_name'] ?: 'Convention unavailable') ?> <?= h($entry['season_year']) ?></h2>
                        <p><strong>Status:</strong> Pending school approval (approval unavailable)</p>
                        <?php if (!(int)$entry['sponsor_available']): ?>
                            <p class="text-danger"><strong>Attention:</strong> This request's student, school, supervisor or convention registration is no longer available or eligible. The originating school should cancel this request.</p>
                        <?php endif; ?>
                        <p><strong>From:</strong> <?= h($entry['origin_name'] ?: 'School unavailable') ?> &nbsp; <strong>To:</strong> <?= h($entry['sponsor_name'] ?: 'School unavailable') ?></p>
                        <p><strong>Proposed supervisor:</strong> <?= h(trim($entry['supervisor_first'] . ' ' . $entry['supervisor_last']) ?: 'Supervisor unavailable') ?></p>
                        <?php if ($isOrigin): ?>
                            <p><strong>Student:</strong> <?= h(trim($entry['student_first'] . ' ' . $entry['student_last']) ?: 'Student unavailable') ?></p>
                            <?= $this->Form->postLink('Cancel request', ['action' => 'cancelcrossschoolrequest', $entry['id']], [
                                'class' => 'btn btn-secondary',
                                'confirm' => 'Cancel this request? The student will remain registered without an assigned supervisor.',
                            ]) ?>
                        <?php else: ?>
                            <p>Student details will be available when secure school approval is implemented.</p>
                        <?php endif; ?>
                    </div></section>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</div>
