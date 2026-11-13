<?php if ($notification): ?>
    <p><strong>Dear Events Team,</strong></p>
    <p>A password reset was requested for <?= h($accountType) ?> account(s).</p>
    <p>Account email: <?= h($accountEmail) ?><br>Account IDs: <?= h(implode(', ', $accountIds)) ?></p>
    <p>No administrator action is required. Reset links were sent separately to the account holder.</p>
<?php else: ?>
    <p><strong>Dear <?= h($recipientName ?: 'convention portal account holder') ?>,</strong></p>
    <p>A password reset was requested for your convention portal account(s).</p>
    <p>Choose the account you want to reset:</p>
    <?php foreach ($links as $link): ?>
        <p><strong><?= h($link['label']) ?></strong></p>
        <p><a href="<?= h($link['url']) ?>" style="display:inline-block; padding:10px 18px; background-color:#1c2452; color:#ffffff; text-decoration:none; font-weight:bold;">Reset password</a></p>
        <p style="font-size:12px;">Or copy and paste this link into your browser:<br><a href="<?= h($link['url']) ?>" style="color:#002859; overflow-wrap:anywhere; word-break:break-all;"><?= h($link['url']) ?></a></p>
    <?php endforeach; ?>
    <p>Each link expires in 30 minutes and can be used once. Each link changes only the labelled account.</p>
    <p>Recently requested accounts may be omitted; use their earlier email or wait one minute and request again.</p>
    <p>If you did not request this, ignore this email. Your password will remain unchanged.</p>
<?php endif; ?>
