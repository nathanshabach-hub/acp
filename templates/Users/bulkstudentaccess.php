<?php $isIssued = !empty($issued); ?>
<style>
@media print {
    .user-portal .portal-header, .user-portal .portal-sidebar, .user-portal .portal-footer,
    .user-portal .portal-skip-link, .user-portal .bulk-setup-controls { display: none !important; }
    .user-portal, .user-portal #portal-content { background: #fff !important; margin: 0 !important; padding: 0 !important; }
    .user-portal #portal-main { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }
    .user-portal .bulk-setup-report { border: 0; padding: 0; }
    .user-portal .bulk-setup-table { overflow: visible; }
    .bulk-setup-report tr { break-inside: avoid; }
    .bulk-setup-report thead { display: table-header-group; }
}
</style>
<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="dashboard-form bulk-setup-report">
                <h2><?= $isIssued ? 'Student Setup Codes' : 'Review Bulk Student Setup' ?></h2>
                <?= $this->Flash->render() ?>
                <p><strong><?= h($userDetails->first_name) ?></strong></p>
                <?php if ($isIssued): ?>
                    <p><strong><?= count($issued) ?> individual codes issued.</strong> Existing Student IDs are unchanged.</p>
                    <div class="alert alert-warning bulk-setup-controls">
                        <strong>Save this list now.</strong> Codes are shown only in this response and cannot be retrieved later. Do not refresh or resubmit this page to download or print.
                        Give each student only their own ID and code. Keep the full list private and delete the CSV when distribution is complete.
                    </div>
                    <p>Each code works once and expires seven days after issuance. Students open Student Password Setup, enter their ID and code, and choose their own password. They then select Student on Login and sign in with their ID and password.</p>
                    <p>Student Password Setup: <?= h($this->Url->build(['action' => 'studentsetup'], ['fullBase' => true])) ?></p>
                    <div class="d-flex flex-wrap gap-2 mb-3 bulk-setup-controls">
                        <button type="button" class="btn btn-primary" id="print-student-codes">Print list</button>
                        <button type="button" class="btn btn-secondary" id="download-student-codes">Download CSV</button>
                        <?= $this->Html->link('Back to Global Students List', ['action' => 'students'], ['class' => 'btn btn-secondary']) ?>
                    </div>
                    <div class="table-responsive bulk-setup-table">
                        <table class="table table-bordered" id="student-setup-codes">
                            <thead><tr><th>Student</th><th>Student ID</th><th>Single-use setup code</th><th>Expires (server time)</th></tr></thead>
                            <tbody>
                                <?php foreach ($issued as $row): ?>
                                    <tr>
                                        <td><?= h($row['name']) ?></td>
                                        <td class="font-monospace"><?= h($row['login_id']) ?></td>
                                        <td class="font-monospace"><?= h($row['code']) ?></td>
                                        <td><?= h($row['expires']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <script>
                    document.getElementById('print-student-codes').addEventListener('click', function () {
                        window.print();
                    });
                    document.getElementById('download-student-codes').addEventListener('click', function () {
                        var rows = Array.from(document.querySelectorAll('#student-setup-codes tr')).map(function (row) {
                            return Array.from(row.cells).map(function (cell) {
                                var value = cell.textContent.trim();
                                if (/^[=+\-@]/.test(value)) value = "'" + value;
                                return '"' + value.replace(/"/g, '""') + '"';
                            }).join(',');
                        });
                        var url = URL.createObjectURL(new Blob(['\ufeff' + rows.join('\r\n') + '\r\n'], {type: 'text/csv;charset=utf-8'}));
                        var link = document.createElement('a');
                        link.href = url;
                        link.download = 'student-setup-codes.csv';
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
                    });
                    </script>
                <?php else: ?>
                    <p><strong><?= count($students) ?> students included.</strong> No codes have been issued yet.</p>
                    <?php if ($mode === 'without_password'): ?>
                        <p>This action includes <strong>all active, verified students without a password</strong> in your school, across all pages. Eligibility is checked again when you confirm.</p>
                    <?php else: ?>
                        <p>This action includes only the students you selected.</p>
                        <?php $resetCount = count(array_filter($students, static fn($row) => (bool)$row['has_password'])); ?>
                        <?php if ($resetCount > 0): ?>
                            <div class="alert alert-warning"><?= $resetCount ?> selected student(s) already have a password. Their current passwords keep working until they use their new codes; using a code replaces the password and signs out older sessions.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <ul>
                        <li>Each student keeps their existing ID and gets a different single-use code.</li>
                        <li>New codes cancel previously issued codes and expire after seven days.</li>
                        <li>The next page provides a printable list and a CSV download. Give each student only their own details.</li>
                        <li>If any selected student is ineligible or received a code within the last minute, no codes are changed.</li>
                    </ul>
                    <?= $this->Form->create(null) ?>
                    <input type="hidden" name="mode" value="<?= h($mode) ?>">
                    <?php if ($mode === 'selected'): ?>
                        <?php foreach ($students as $student): ?>
                            <input type="hidden" name="student_ids[]" value="<?= (int)$student['id'] ?>">
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="confirm-bulk-codes" name="confirm_issue" value="1" required>
                        <label for="confirm-bulk-codes" class="form-check-label">I will keep the list private and give each student only their own ID and setup code.</label>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <button type="submit" class="btn btn-primary">Issue individual setup codes</button>
                        <?= $this->Html->link('Cancel', ['action' => 'students'], ['class' => 'btn btn-secondary']) ?>
                    </div>
                    <?= $this->Form->end() ?>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead><tr><th>Student</th><th>Student ID</th><th>Password</th></tr></thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><?= h(trim($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name'])) ?></td>
                                        <td class="font-monospace"><?= h($student['login_id'] ?? 'Will be assigned') ?></td>
                                        <td><?= $student['has_password'] ? 'Already created (reset code)' : 'Not yet created' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
