<?php if (\Cake\Core\Configure::read('Environment.name') === 'demo') { ?>
<?php
$demoPayments = \Cake\Core\Configure::read('Payments.mode') === 'sandbox' ? 'PAYPAL SANDBOX' : 'PAYMENTS DISABLED';
$demoEmail = \Cake\Core\Configure::read('Email.mode') === 'smtp_test' ? 'TEST EMAILS ONLY' : 'EMAIL CAPTURE ONLY';
?>
<div role="status" aria-label="Demo environment"
     style="position:fixed;bottom:12px;right:12px;z-index:10000;padding:10px 16px;background:#713f12;color:#fff;border:2px solid #fff;border-radius:6px;font:700 14px Arial,sans-serif">
    DEMO - TEST DATA - <?= h($demoPayments) ?> - <?= h($demoEmail) ?>
</div>
<?php } ?>
