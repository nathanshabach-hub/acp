<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main id="portal-main" class="col-md-9 ms-sm-auto col-lg-10 px-md-4 portal-main" tabindex="-1">
            <div class="ersu_message"><?= $this->Flash->render() ?></div>
            <div class="portal-page-heading"><h1>Judge Registration Price Structure</h1></div>
            <section class="portal-panel">
                <h2 class="h4"><?= h($judgePriceContext['convention_name']) ?> (<?= h($judgePriceContext['season_year']) ?>)</h2>
                <?php if ($judgePriceContext['judge_registration_fees'] === null): ?>
                    <div class="alert alert-warning">The Judges Registration fee has not been configured. Please contact the events team.</div>
                    <?php if ($judgeRegistration && $judgeRegistration['judge_registration_fee'] !== null): ?>
                        <?= $this->Html->link('Choose events', ['action' => 'judgesregisterconvention', $judgePriceContext['convention_slug'], $judgePriceContext['season_id']], ['class' => 'btn btn-primary']) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?= $this->Form->create(null) ?>
                    <label class="d-flex align-items-center gap-2 mb-3">
                        <input type="radio" name="select_judge_fee" value="1" required<?= $judgeRegistration && $judgeRegistration['judge_registration_fee'] !== null ? ' checked' : '' ?>>
                        <span>Judges Registration - <?= h(CURR) ?> <?= number_format((float)$judgePriceContext['judge_registration_fees'], 2) ?></span>
                    </label>
                    <?= $this->Form->hidden('judge_fee', ['value' => $judgePriceContext['judge_registration_fees']]) ?>
                    <p>Select the registration fee and save to choose your judging events. Submitting your events creates an invoice request for admin. Admin will generate and email your invoice; this does not charge a card or confirm payment.</p>
                    <?php if ($judgeRegistration && $judgeRegistration['judge_registration_fee'] !== null): ?>
                        <p>Your saved fee: <?= h(CURR) ?> <?= number_format((float)$judgeRegistration['judge_registration_fee'], 2) ?>. An existing invoice request keeps its original saved price.</p>
                    <?php endif; ?>
                    <?= $this->Form->button('Save and choose events', ['class' => 'btn btn-primary']) ?>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>
