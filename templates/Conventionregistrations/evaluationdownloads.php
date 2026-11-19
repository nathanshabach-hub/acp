<?php
$available = array_filter($students, static fn(array $student): bool => (bool)$student['has_evaluations']);
$completed = array_filter($available, static fn(array $student): bool => $student['completed'] !== null);
$selectedCount = 0;
?>
<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="dashboard-form">
                <h2>Judges Evaluations</h2>
                <?= $this->Flash->render() ?>
                <p><?= h($registration->Conventions->name) ?> &mdash; <?= h($registration->season_year) ?></p>
                <p>Select up to 10 students. Each ZIP contains a named folder per student, with their evaluation forms in one PDF. Certificates and results summaries are not included.</p>
                <?php if ($storageError !== null): ?>
                    <div class="alert alert-warning" role="alert"><?= h($storageError) ?></div>
                <?php else: ?>
                    <p><strong><?= count($completed) ?> of <?= count($available) ?></strong> students with available evaluation forms downloaded.</p>
                    <div class="alert alert-danger" role="alert" data-evaluation-error hidden></div>
                    <p role="status" aria-live="polite" data-evaluation-status></p>
                    <div class="border rounded p-3 mb-3" data-evaluation-preview hidden>
                        <h3 class="h5">Live download progress</h3>
                        <p class="mb-2" role="status" aria-live="polite" data-evaluation-progress-text></p>
                        <progress class="w-100 mb-3" max="10" value="0" aria-label="Evaluation download progress" data-evaluation-progress></progress>
                        <ul class="list-group" data-evaluation-files></ul>
                    </div>
                    <?= $this->Form->create(null, [
                        'url' => ['action' => 'prepareevaluationdownload'],
                        'id' => 'evaluation-download-form',
                        'data-complete-url' => $this->Url->build(['action' => 'completeevaluationdownload']),
                    ]) ?>
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <button class="btn btn-primary" type="submit" data-download-selected>Download selected evaluations</button>
                        <button class="btn btn-secondary" type="button" data-select-next>Select next 10</button>
                        <button class="btn btn-secondary" type="button" data-clear-selection>Clear selection</button>
                        <span data-selection-count aria-live="polite"></span>
                    </div>
                    <button class="btn btn-primary mb-3" type="button" data-retry-completion hidden>Retry recording download completion</button>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead><tr><th scope="col">Select</th><th scope="col">Student</th><th scope="col">Download status</th></tr></thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <?php
                                    $name = trim(implode(' ', array_filter(array_map('trim', [
                                        (string)$student['first_name'], (string)$student['middle_name'], (string)$student['last_name'],
                                    ]), static fn(string $part): bool => $part !== '')));
                                    $checked = (bool)$student['has_evaluations'] && $student['completed'] === null && $selectedCount < 10;
                                    if ($checked) {
                                        $selectedCount++;
                                    }
                                    ?>
                                    <tr>
                                        <td><input class="form-check-input" type="checkbox" name="student_ids[]" value="<?= (int)$student['id'] ?>"
                                            aria-label="Select <?= h($name) ?>"
                                            data-student-name="<?= h($name) ?>"
                                            data-completed="<?= $student['completed'] !== null ? '1' : '0' ?>"
                                            <?= !(bool)$student['has_evaluations'] ? 'disabled data-unavailable="1"' : '' ?>
                                            <?= $checked ? 'checked' : '' ?>></td>
                                        <td><?= h($name) ?></td>
                                        <td>
                                            <?php if (!(bool)$student['has_evaluations']): ?>
                                                <span class="text-muted">No evaluation forms available</span>
                                            <?php elseif ($student['completed'] !== null): ?>
                                                <strong class="text-success">Download complete</strong><br>
                                                <small><?= h($student['completed']) ?></small>
                                            <?php else: ?>
                                                Not downloaded
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($students === []): ?>
                                    <tr><td colspan="3">No students are registered.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?= $this->Form->end() ?>
                    <noscript><div class="alert alert-warning">Enable JavaScript to download batches and record their completion.</div></noscript>
                    <script src="<?= h(HTTP_PATH . '/js/evaluation-downloads.js?v=' . hash_file('sha256', WWW_ROOT . 'js/evaluation-downloads.js')) ?>" defer></script>
                <?php endif; ?>
                <?= $this->Html->link('Back to students', ['action' => 'students'], ['class' => 'btn btn-secondary']) ?>
            </div>
        </main>
    </div>
</div>
