<?php if ($localTest): ?>
[LOCAL TEST] This message is from the local test environment.

<?php endif; ?>
<?php if ($notification): ?>
Dear Events Team,

A password reset was requested for <?= $accountType ?> account(s).
Account email: <?= $accountEmail ?><?= "\n" ?>
Account IDs: <?= implode(', ', $accountIds) ?><?= "\n" ?>
No administrator action is required. Reset links were sent separately to the account holder.
<?php else: ?>
Dear <?= $recipientName ?: 'convention portal account holder' ?>,

A password reset was requested for your convention portal account(s).
Open the link for the account you want to reset:

<?php foreach ($links as $link): ?>
<?= $link['label'] ?><?= "\n" ?>
<?= $link['url'] ?><?= "\n\n" ?>
<?php endforeach; ?>
Each link expires in 30 minutes and can be used once. Each link changes only the labelled account.
Recently requested accounts may be omitted; use their earlier email or wait one minute and request again.
If you did not request this, ignore this email. Your password will remain unchanged.

Do not forward this email or share its reset links.
<?php endif; ?>
