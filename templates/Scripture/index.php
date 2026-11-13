<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="ersu_message"><?= $this->Flash->render() ?></div>
            <div class="portal-page-heading"><h1>Scripture</h1></div>
            <section class="portal-panel">
                <h2><?= h($context['convention_name']) ?> (<?= h($context['season_year']) ?>)</h2>
                <p>Upload your own Scripture award submission and select the books you memorised.</p>
                <?php if ($unavailableReason !== null): ?>
                    <p role="status"><?= h($unavailableReason) ?></p>
                <?php endif; ?>
                <?php if (!$awards): ?>
                    <p>No Scripture awards are available for this convention.</p>
                <?php elseif ($unavailableReason === null): ?>
                    <a href="#scripture-upload" class="btn btn-primary cr-scripture-upload-trigger" data-bs-toggle="modal" data-bs-target="#scripture-upload">Upload submission</a>
                    <noscript>
                        <style>.cr-scripture-upload-trigger { display: none; }</style>
                        <p>Choose an award to upload your submission:</p>
                        <ul>
                            <?php foreach ($awards as $award): ?>
                                <li><?= $this->Html->link($award['display_name'], ['action' => 'submit', $award['id']]) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </noscript>
                <?php endif; ?>
            </section>
            <?php if ($unavailableReason === null && $awards): ?>
                <div class="modal fade cr-pr-detail-modal cr-scripture-modal" id="scripture-upload" tabindex="-1" aria-labelledby="scripture-title" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h2 class="modal-title" id="scripture-title">Upload Scripture submission</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="cr-pr-upload-toolbar">
                                    <button type="button" class="btn btn-outline-secondary btn-sm cr-pr-upload-back" data-bs-dismiss="modal">Back to Scripture</button>
                                </div>
                                <div class="dashboard-form cr-scripture-upload">
                                    <div class="form-group">
                                        <label for="scripture-award">Choose Scripture award</label>
                                        <select id="scripture-award" class="form-control">
                                            <option value="">Select an award</option>
                                            <?php foreach ($awards as $award): ?>
                                                <option value="<?= (int)$award['id'] ?>"><?= h($award['display_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php foreach ($awards as $award): ?>
                                    <div class="cr-scripture-award-form" data-award-id="<?= (int)$award['id'] ?>" hidden>
                                        <?= $this->Html->link('Open in new tab', ['action' => 'submit', $award['id']], ['class' => 'cr-pr-upload-open-new', 'target' => '_blank', 'rel' => 'noopener']) ?>
                                        <?= $this->element('scripture_submission_form', ['award' => $award, 'books' => $award['books'], 'context' => $context, 'inModal' => true]) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    (function () {
                        var modal = document.getElementById('scripture-upload');
                        var select = document.getElementById('scripture-award');
                        var forms = modal.querySelectorAll('.cr-scripture-award-form');
                        select.addEventListener('change', function () {
                            forms.forEach(function (form) {
                                form.hidden = form.dataset.awardId !== select.value;
                            });
                        });
                    })();
                </script>
            <?php endif; ?>
            <?php if ($submissions): ?>
                <section class="portal-panel mt-3">
                    <h2>Your submissions</h2>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead><tr><th>Award</th><th>File</th><th>Submitted</th></tr></thead>
                            <tbody>
                            <?php foreach ($submissions as $submission): ?>
                                <tr><td><?= h($submission['display_name']) ?></td><td><?= $this->Html->link($submission['original_filename'], ['action' => 'download', $submission['id']]) ?></td><td><?= h($submission['created']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>
