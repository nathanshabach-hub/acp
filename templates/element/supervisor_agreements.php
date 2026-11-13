<section class="portal-panel mb-4" aria-labelledby="supervisor-agreements-heading">
    <div class="card-body">
        <h2 id="supervisor-agreements-heading" class="h4">Supervisor agreements</h2>
        <?php if (!$agreementsAvailable): ?>
            <div class="alert alert-warning">Supervisor agreements are unavailable until migrations 012 and 014 are applied. Please contact the events team.</div>
        <?php elseif (!$supervisorAgreements): ?>
            <p class="mb-0">No students are currently assigned to you for an active convention registration.</p>
        <?php endif; ?>
        <?php foreach ($supervisorAgreements as $agreement): ?>
            <?php
            $accepted = !empty($agreement['accepted_at']);
            $snapshot = $accepted ? json_decode($agreement['snapshot'], true, 512, JSON_THROW_ON_ERROR) : null;
            $accountName = trim($userDetails->first_name) . ' ' . trim($userDetails->last_name);
            ?>
            <section id="supervisor-agreement-<?= (int)$agreement['id'] ?>" class="portal-panel mt-3 supervisor-agreement">
                <div class="card-body">
                    <div class="supervisor-agreement-header">
                        <h3 class="h5"><?= h($agreement['convention_name']) ?> <?= h($agreement['season_year']) ?></h3>
                        <span class="supervisor-agreement-status"><?= $accepted ? 'Accepted' : 'Your acceptance is required' ?></span>
                    </div>
                    <?php if (!$accepted): ?>
                        <p>Accept this agreement to continue with Student Event Registration, Student Grouping, Events of the Heart, View/Edit Event Submissions and Registration Checklist.</p>
                    <?php endif; ?>
                    <div class="supervisor-agreement-roster-heading">
                        <h4 id="agreement-students-<?= (int)$agreement['id'] ?>">Students I am sponsoring</h4>
                        <span><?= count($agreement['students']) ?> assigned</span>
                    </div>
                    <ul class="supervisor-agreement-students" aria-labelledby="agreement-students-<?= (int)$agreement['id'] ?>" role="list">
                        <?php foreach ($agreement['students'] as $student): ?>
                            <li><?= h(trim(trim($student['first_name']) . ' ' . trim($student['last_name']))) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p><?= $this->Html->link('Read the SPONSORS information in the Convention Guidelines',
                        'https://convention.accelerateministries.com.au/guidelines/',
                        ['target' => '_blank', 'rel' => 'noopener']) ?></p>
                    <ul class="supervisor-agreement-declarations">
                        <?php foreach ($snapshot['declarations'] ?? \App\Service\SupervisorAgreements::DECLARATIONS as $declaration): ?>
                            <li class="mb-3"><?= h($declaration) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($accepted): ?>
                        <p><strong>Signed by:</strong> <?= h($agreement['signature']) ?><br>
                            <strong>Accepted:</strong> <?= h($agreement['accepted_at']) ?> (Brisbane time)</p>
                        <p class="mb-0">Your acceptance remains valid for this convention, including changes to your assigned students.</p>
                        <details class="mt-3">
                            <summary>Students named when you signed</summary>
                            <ul class="supervisor-agreement-students" aria-label="Students named when you signed" role="list">
                                <?php foreach ($snapshot['students'] as $student): ?>
                                    <li><?= h(trim(trim($student['first_name']) . ' ' . trim($student['last_name']))) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php else: ?>
                        <?= $this->Form->create(null, [
                            'url' => ['controller' => 'users', 'action' => 'acceptsupervisoragreement', $agreement['id']],
                            'class' => 'supervisor-agreement-form',
                        ]) ?>
                        <?= $this->Form->hidden('roster_hash', ['value' => \App\Service\SupervisorAgreements::rosterHash($agreement['students'])]) ?>
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="agreement-consent-<?= (int)$agreement['id'] ?>"
                                name="agree" value="1" required data-supervisor-consent data-signature="<?= h($accountName) ?>">
                            <label class="form-check-label" for="agreement-consent-<?= (int)$agreement['id'] ?>">I accept all declarations above and agree to sign with my first and last name.</label>
                        </div>
                        <label class="form-label" for="agreement-signature-<?= (int)$agreement['id'] ?>">Signature</label>
                        <input type="text" class="form-control mb-3" id="agreement-signature-<?= (int)$agreement['id'] ?>"
                            data-supervisor-signature readonly aria-live="polite">
                        <noscript><p>Your signature will be <?= h($accountName) ?> when you accept.</p></noscript>
                        <p>Acceptance remains valid for this convention, including changes to your assigned students. Only you can accept while signed in to your supervisor account.</p>
                        <?= $this->Form->button('Accept supervisor agreement', ['class' => 'btn btn-primary']) ?>
                        <?= $this->Form->end() ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</section>
