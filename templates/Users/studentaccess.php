<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="dashboard-form">
                <h2 class="mb-2">Student Portal Access</h2>
                <p class="mb-4">Help this student create a password or recover access to their account.</p>
                <?= $this->Flash->render() ?>
                <div class="row g-3 border rounded-3 p-3 mb-4 bg-light">
                    <div class="col-md-4">
                        <div class="small mb-1">Student</div>
                        <strong><?= h(trim($student->first_name . ' ' . $student->last_name)) ?></strong>
                    </div>
                    <div class="col-md-4">
                        <div class="small mb-1">Student ID</div>
                        <strong class="font-monospace fs-5"><?= h($access['login_id'] ?? 'Not assigned') ?></strong>
                        <div class="small">Used every time the student signs in.</div>
                    </div>
                    <div class="col-md-4">
                        <div class="small mb-1">Login history</div>
                        <strong><?= !empty($access['first_login']) ? 'Has signed in' : 'Not yet signed in' ?></strong>
                        <div class="small"><?= !empty($access['password_hash']) ? 'A password has been created.' : 'A password has not been created yet.' ?></div>
                    </div>
                </div>
                <?php if (!empty($issued)): ?>
                    <div class="alert alert-info mb-4" role="status">
                        <h3 class="mb-3">Code ready: give these details privately to the student</h3>
                        <p>Student ID: <strong class="font-monospace"><?= h($access['login_id']) ?></strong></p>
                        <p>Single-use setup code: <strong><?= h($issued['code']) ?></strong></p>
                        <p class="mb-0"><strong>Copy the code now.</strong> It is shown only on this response, works once, and expires in seven days. Do not share it with anyone other than this student.</p>
                    </div>
                <?php endif; ?>
                <h3 class="mb-3">How it works</h3>
                <ol class="ps-4 mb-4">
                    <li class="mb-2"><strong>Step 1 - Admin:</strong> issue a setup code below and privately give the student their ID and code.</li>
                    <li class="mb-2"><strong>Step 2 - Student:</strong> open <?= $this->Html->link('Student Password Setup', ['action' => 'studentsetup']) ?>, enter both details, and choose a password.</li>
                    <li><strong>Step 3 - Student:</strong> return to Login, select <strong>Student</strong>, and sign in with their Student ID and new password.</li>
                </ol>
                <?php if ((int)$student->status === 1 && (int)$student->activation_status === 1): ?>
                    <div class="border-top pt-4">
                    <h3><?= !empty($access['password_hash']) ? 'Reset password access' : 'Create the student\'s first password' ?></h3>
                    <p>The code lets the student choose their own password. It is <strong>not</strong> their login password.</p>
                    <ul class="ps-4 mb-3">
                        <li>A new code cancels any previously issued setup code.</li>
                        <li>Codes expire after seven days and can only be used once.</li>
                        <?php if (!empty($access['password_hash'])): ?>
                            <li>The current password still works until the new code is used. Using it replaces the password and signs out older sessions.</li>
                        <?php endif; ?>
                    </ul>
                    <?= $this->Form->create(null) ?>
                    <div class="form-check mb-3">
                        <input id="confirm-student-code" class="form-check-input" type="checkbox" name="confirm_issue" value="1" required>
                        <label class="form-check-label" for="confirm-student-code">I confirm this code is for <?= h(trim($student->first_name . ' ' . $student->last_name)) ?> and will give it to them privately.</label>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= !empty($issued) ? 'Issue a replacement code' : 'Issue setup code' ?></button>
                    <?= $this->Form->end() ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">This student cannot receive a setup code or sign in while inactive, archived or unverified. Only active, verified students are eligible.</div>
                <?php endif; ?>
                <div class="border-top mt-4 pt-3">
                    <?= $this->Html->link('Back to Global Students List', ['action' => 'students'], ['class' => 'btn btn-secondary']) ?>
                </div>
            </div>
        </main>
    </div>
</div>
