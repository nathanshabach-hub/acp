<?php $deleteConfirmed = $deleteConfirmed ?? false; ?>
<style>
    .transaction-cancel-page .content-header { padding: 24px 24px 0; }
    .transaction-cancel-page .content-header h1 { color: #233e59; font-size: 26px; font-weight: 700; }
    .transaction-cancel-page .cancel-subtitle { color: #526579; margin: 8px 0 0; }
    .transaction-cancel-page .content { padding: 24px; }
    .transaction-cancel-page .cancel-card { background: #fff; border: 1px solid #dce5ed; border-radius: 12px; box-shadow: 0 5px 20px rgba(30, 55, 80, .06); overflow: hidden; }
    .transaction-cancel-page .cancel-summary { display: grid; grid-template-columns: 1fr 1fr auto; gap: 24px; padding: 24px; background: #f5f8fc; border-bottom: 1px solid #dce5ed; }
    .transaction-cancel-page .cancel-label { display: block; color: #526579; font-size: 12px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 6px; }
    .transaction-cancel-page .cancel-value { color: #233e59; font-size: 17px; font-weight: 600; overflow-wrap: anywhere; }
    .transaction-cancel-page .cancel-amount { font-size: 24px; white-space: nowrap; }
    .transaction-cancel-page .cancel-body { display: grid; grid-template-columns: minmax(0, 1fr) minmax(260px, .7fr); gap: 32px; padding: 28px; }
    .transaction-cancel-page h2 { font-size: 18px; font-weight: 700; color: #233e59; margin: 0 0 16px; }
    .transaction-cancel-page .cancel-help { color: #526579; font-size: 13px; margin: 8px 0 20px; }
    .transaction-cancel-page textarea.form-control { border: 1px solid #b8c9d9; border-radius: 8px; min-height: 130px; resize: vertical; }
    .transaction-cancel-page textarea:focus { border-color: #337ab7; box-shadow: 0 0 0 3px rgba(51, 122, 183, .12); }
    .transaction-cancel-page .cancel-confirm { display: flex; align-items: flex-start; gap: 12px; padding: 16px; border: 1px solid #dce5ed; border-radius: 8px; background: #f8fafc; font-weight: 400; cursor: pointer; margin: 0; }
    .transaction-cancel-page .cancel-confirm input { flex: 0 0 18px; width: 18px; height: 18px; margin: 2px 0 0; }
    .transaction-cancel-page .cancel-guide { padding: 22px; background: #fff9ee; border: 1px solid #efdbb4; border-radius: 10px; align-self: start; color: #654d20; }
    .transaction-cancel-page .cancel-guide h2 { color: #654d20; }
    .transaction-cancel-page .cancel-guide ul { padding-left: 20px; margin: 0; }
    .transaction-cancel-page .cancel-guide li { margin-bottom: 12px; line-height: 1.6; }
    .transaction-cancel-page .cancel-guide li:last-child { margin-bottom: 0; }
    .transaction-cancel-page .cancel-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 24px; }
    .transaction-cancel-page .cancel-actions .btn { padding: 11px 18px; border-radius: 7px; font-weight: 600; }
    .transaction-cancel-page .cancel-actions .btn-danger { background: #b83232; border-color: #b83232; color: #fff; }
    .transaction-cancel-page .cancel-actions .btn-danger:hover { background: #982929; border-color: #982929; }
    .transaction-cancel-page .cancel-flash { padding: 0 24px; }
    @media (max-width: 991px) {
        .transaction-cancel-page .cancel-body { grid-template-columns: minmax(0, 1fr); gap: 24px; }
        .transaction-cancel-page .cancel-summary { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 575px) {
        .transaction-cancel-page .content, .transaction-cancel-page .cancel-body, .transaction-cancel-page .cancel-summary { padding: 18px; }
        .transaction-cancel-page .cancel-summary { grid-template-columns: minmax(0, 1fr); gap: 16px; }
        .transaction-cancel-page .cancel-actions .btn { width: 100%; }
    }
    @media (max-width: 767px) {
        .content-wrapper.transaction-cancel-page { margin-left: 0 !important; }
    }
</style>
<div class="content-wrapper transaction-cancel-page">
    <section class="content-header">
        <h1><?= $deleteConfirmed ? 'Delete confirmed transaction' : 'Cancel unpaid request' ?></h1>
        <p class="cancel-subtitle"><?= $deleteConfirmed ? 'Permanently remove this payment record. This does not refund any money and allows the school to be billed again.' : 'Review the request below before cancelling it. Paid transactions cannot be cancelled.' ?></p>
    </section>
    <section class="content">
        <div class="cancel-flash"><?= $this->Flash->render() ?></div>
        <div class="cancel-card">
            <div class="cancel-summary" aria-label="Transaction summary">
                <div><span class="cancel-label">School</span><span class="cancel-value"><?= h($transaction->Users->first_name) ?></span></div>
                <div><span class="cancel-label">Convention</span><span class="cancel-value"><?= h($transaction->Conventions->name) ?> (<?= h($transaction->season_year) ?>)</span></div>
                <div><span class="cancel-label">Request amount</span><span class="cancel-value cancel-amount"><?= h(CURR) ?> <?= number_format((float)$transaction->final_amount_paid, 2) ?></span></div>
            </div>
            <div class="cancel-body">
                <div>
            <?php if ($deleteConfirmed ? (int)$transaction->status === 1 : (in_array((int)$transaction->status, [0, 2, 3], true) && trim((string)$transaction->transaction_id_received) === '')): ?>
                <h2><?= $deleteConfirmed ? 'Permanent deletion details' : 'Cancellation details' ?></h2>
                <?= $this->Form->create(null) ?>
                <?= $this->Form->control('reason', ['type' => 'textarea', 'label' => $deleteConfirmed ? 'Reason for deleting this confirmed transaction' : 'Reason for cancellation', 'required' => true, 'maxlength' => 500, 'class' => 'form-control', 'placeholder' => $deleteConfirmed ? 'Explain why this confirmed payment record must be removed.' : 'For example: The school submitted this request by mistake.', 'aria-describedby' => 'cancel-reason-help']) ?>
                <p class="cancel-help" id="cancel-reason-help">This reason will be recorded in the system log. Maximum 500 characters.</p>
                <?php if ($deleteConfirmed): ?>
                    <label class="cancel-confirm"><input type="checkbox" name="confirm_delete" value="1" required><span>I understand this <strong>confirmed payment record and its billing line items will be permanently deleted</strong>. No refund will be issued. These registrations can be billed again. <strong>This cannot be undone.</strong></span></label>
                <?php else: ?>
                    <label class="cancel-confirm"><input type="checkbox" name="confirm_cancel" value="1" required><span>I confirm that payment has <strong>not been received</strong> and this request should be <strong>permanently deleted</strong>. This cannot be undone.</span></label>
                <?php endif; ?>
                <div class="cancel-actions">
                    <?= $this->Html->link($deleteConfirmed ? 'Keep transaction / Go back' : 'Keep request / Go back', ['action' => 'index'], ['class' => 'btn btn-default']) ?>
                    <button type="submit" class="btn btn-danger"><?= $deleteConfirmed ? 'Permanently delete confirmed transaction' : 'Permanently remove request' ?></button>
                </div>
                <?= $this->Form->end() ?>
            <?php else: ?>
                <h2>Removal unavailable</h2>
                <p><?= $deleteConfirmed ? 'This transaction is no longer confirmed. Reload the transaction list to review its current status.' : 'This transaction is paid, already cancelled, or has a payment reference. It cannot be cancelled.' ?></p>
                <div class="cancel-actions"><?= $this->Html->link('Back to transactions', ['action' => 'index'], ['class' => 'btn btn-default']) ?></div>
            <?php endif; ?>
                </div>
                <aside class="cancel-guide">
                    <h2><?= $deleteConfirmed ? 'What happens when you delete?' : 'What happens when you cancel?' ?></h2>
                    <ul>
                        <li><?= $deleteConfirmed ? 'The confirmed transaction, payment reference and student/supervisor billing line items are permanently deleted. No payment record is retained in the transaction list. This cannot be undone.' : 'The unpaid request and its billing details are permanently deleted. No cancelled transaction history is kept. This cannot be undone.' ?></li>
                        <?php if ($deleteConfirmed): ?>
                            <li><strong>No money is refunded.</strong> Arrange any refund or accounting correction separately with accounts.</li>
                        <?php endif; ?>
                        <li>The school can include these registrations in a <strong>new payment or invoice request</strong>.</li>
                        <li><strong>Students and event choices stay registered.</strong> Only the payment record and billing line items are removed.</li>
                        <li>Emails already sent cannot be recalled. Contact accounts separately if an invoice has already been issued.</li>
                    </ul>
                </aside>
            </div>
        </div>
    </section>
</div>
