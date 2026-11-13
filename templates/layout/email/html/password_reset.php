<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($subject) ?></title>
</head>
<body style="margin:0; padding:16px; background-color:#f3f3f3;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:710px; margin:0 auto; background-color:#ffffff; font-family:Arial, Helvetica, sans-serif; color:#434343; font-size:14px; line-height:21px;">
        <tr>
            <td style="padding:16px; background-color:#1c2452;">
                <img src="<?= h($baseUrl . '/img/front/main-logo.png') ?>" width="160" alt="Accelerate Educational Ministries" style="display:block; border:0; max-width:100%; height:auto;">
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <?php if ($localTest): ?>
                    <p style="padding:12px; background-color:#fff3cd; color:#664d03;"><strong>[LOCAL TEST]</strong> This message is from the local test environment.</p>
                <?php endif; ?>
                <?= $this->fetch('content') ?>
                <p>Thanks,<br>Events Team<br>Southern Cross Educational Enterprises<br><a href="https://www.scee.edu.au" style="color:#002859;">www.scee.edu.au</a></p>
                <p>If you need any assistance, please email us at <a href="mailto:<?= h($contactEmail) ?>" style="color:#002859;"><?= h($contactEmail) ?></a>.</p>
                <p style="color:#002859; font-weight:bold;">Accelerate Educational Ministries team</p>
                <?php if (!$notification): ?>
                    <p style="border-top:1px solid #dddddd; padding-top:12px; font-size:12px;">Do not forward this email or share its reset links. Each link can change the password for its labelled account.</p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td style="padding:12px 16px; background-color:#1c2452; color:#ffffff; font-size:12px;">Copyright &copy; <?= date('Y') ?> Accelerate Educational Ministries. All Rights Reserved.</td>
        </tr>
    </table>
</body>
</html>
