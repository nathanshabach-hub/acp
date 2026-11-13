<style>
.invoice-generator .invoice-panel { background: #fff; padding: 24px; border: 0; margin-bottom: 20px; }
.invoice-generator .invoice-summary { display: flex; flex-wrap: wrap; gap: 20px 40px; margin-bottom: 24px; }
.invoice-generator .invoice-summary strong { display: block; color: #233e59; margin-bottom: 5px; }
.invoice-generator .invoice-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 20px; }
.invoice-generator textarea { min-height: 150px; max-width: 700px; resize: vertical; }
.invoice-generator .invoice-confirm { display: flex; gap: 10px; align-items: flex-start; margin: 20px 0; font-weight: normal; }
.invoice-generator .invoice-confirm input { flex-shrink: 0; margin-top: 3px; }
.invoice-generator h2 { font-size: 20px; margin-top: 0; }
.invoice-generator .invoice-disclaimer { color: #526579; margin-top: 14px; }
@media (max-width:767px) { .content-wrapper.invoice-generator { margin-left: 0 !important; } .invoice-generator .invoice-panel { padding: 18px; } }
</style>
<div class="invoice-generator">
        <div class="invoice-panel">
            <div class="invoice-summary">
                <div><strong><?= $transaction['price_structure'] === 'judge_registration' ? 'Judge' : 'School' ?></strong><?= h($transaction['school_name']) ?></div>
                <div><strong>Convention</strong><?= h($transaction['convention_name']) ?> (<?= h($transaction['season_year']) ?>)</div>
                <div><strong>Payment status</strong><?= (int)$transaction['status'] === 1 ? 'Confirmed / Paid' : 'Awaiting payment' ?></div>
                <div><strong>Transaction amount</strong>AUD <?= number_format((float)$transaction['final_amount_paid'], 2) ?></div>
            </div>
            <?php if (!$available): ?>
                <div class="alert alert-warning">Apply database migration 013 to enable invoices. Please contact the system administrator.</div>
            <?php elseif ($invoice): ?>
                <h2>Invoice <?= h($invoice['number']) ?></h2>
                <p><strong>Issued:</strong> <?= h($invoice['issued']) ?> &nbsp; <strong>Due:</strong> <?= h($invoice['due']) ?></p>
                <p><strong>Issued to:</strong> <?= h($invoice['data']['school']) ?> — <?= h($invoice['data']['email']) ?></p>
                <p><strong>Customer code:</strong> <?= h($invoice['data']['customer_code']) ?></p>
                <p><strong>Total:</strong> AUD <?= number_format($invoice['data']['total'] / 100, 2) ?></p>
                <p><strong>Bank details on invoice:</strong><br><?= nl2br(h($invoice['data']['bank'])) ?></p>
                <div class="invoice-actions">
                    <?= $this->Html->link('Download invoice PDF', ['action' => 'invoicepdf', $transaction['slug']], ['class' => 'btn btn-primary']) ?>
                </div>
                <p class="invoice-disclaimer">The invoice number, billing details, bank details and dates are saved. Downloading again uses the same invoice. Confirmed payments display “Paid” with no amount due. Generating or emailing an invoice does not change payment status.</p>
                <hr>
                <h2><?= $invoice['emailed_at'] ? 'Send invoice again' : 'Email invoice to recipient' ?></h2>
                <?php if ($invoice['emailed_at']): ?>
                    <p>Last accepted for email delivery: <?= h($invoice['emailed_at']) ?> (Brisbane time).</p>
                <?php endif; ?>
                <?= $this->Form->create(null, ['url' => ['action' => 'invoice', $transaction['slug']]]) ?>
                <?= $this->Form->hidden('operation', ['value' => 'send']) ?>
                <label class="invoice-confirm"><input type="checkbox" name="confirm_send" value="1" required><span>I confirm this invoice PDF should be emailed to <strong><?= h($invoice['data']['email']) ?></strong><?= $invoice['emailed_at'] ? ' again' : '' ?>.</span></label>
                <?= $this->Form->button($invoice['emailed_at'] ? 'Resend invoice email' : 'Send invoice email', ['class' => 'btn btn-success']) ?>
                <?= $this->Form->end() ?>
            <?php elseif ($charges): ?>
                <h2>Review invoice charges</h2>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead><tr><th>Item</th><th>Quantity</th><th>Subtotal (AUD)</th></tr></thead>
                        <tbody>
                            <?php if (!empty($charges['judge_qty'])): ?>
                            <tr><td>Judges Registration</td><td><?= (int)$charges['judge_qty'] ?></td><td><?= number_format($charges['judge_gross'] / 100, 2) ?></td></tr>
                            <?php else: ?>
                            <tr><td>Student Registration</td><td><?= (int)$charges['student_qty'] ?></td><td><?= number_format($charges['student_gross'] / 100, 2) ?></td></tr>
                            <tr><td>Supervisor Registration</td><td><?= (int)$charges['supervisor_qty'] ?></td><td><?= number_format($charges['supervisor_gross'] / 100, 2) ?></td></tr>
                            <tr><td>Golden Discounts</td><td><?= (int)$charges['discount_qty'] ?></td><td>-<?= number_format($charges['discount'] / 100, 2) ?></td></tr>
                            <?php endif; ?>
                            <tr><th>Total</th><td></td><th><?= number_format($charges['total'] / 100, 2) ?></th></tr>
                        </tbody>
                    </table>
                </div>
                <p>Uses saved billing amounts, not current registration prices. No additional tax is added. The PDF uses the official Student Convention Invoice design.</p>
                <?= $this->Form->create(null, ['url' => ['action' => 'invoice', $transaction['slug']]]) ?>
                <?= $this->Form->hidden('operation', ['value' => 'generate']) ?>
                <?= $this->Form->control('bank_details', ['type' => 'textarea', 'label' => 'Bank details to print on this invoice', 'required' => true, 'maxlength' => 700, 'class' => 'form-control', 'value' => is_string($bankDetails) ? $bankDetails : '', 'placeholder' => "Bank name\nAccount name\nBSB\nAccount number", 'aria-describedby' => 'invoice-bank-help']) ?>
                <p id="invoice-bank-help" class="invoice-disclaimer">Enter only the receiving account details intended for the recipient. Never enter banking passwords. Check carefully: these details will be saved on the invoice.</p>
                <label class="invoice-confirm"><input type="checkbox" name="confirm_generate" value="1" required><span>I have checked the recipient, charges and bank details. Generate a uniquely numbered invoice dated today, due in 14 days. The saved invoice cannot be edited here.</span></label>
                <div class="invoice-actions">
                    <?= $this->Form->button('Generate invoice', ['class' => 'btn btn-primary']) ?>
                    <?= $this->Html->link('All transactions', ['action' => 'index'], ['class' => 'btn btn-default']) ?>
                </div>
                <?= $this->Form->end() ?>
            <?php endif; ?>
            <?php if (!$available || $invoice || !$charges): ?>
            <div class="invoice-actions">
                <?= $this->Html->link('All transactions', ['action' => 'index'], ['class' => 'btn btn-default']) ?>
            </div>
            <?php endif; ?>
        </div>
</div>
