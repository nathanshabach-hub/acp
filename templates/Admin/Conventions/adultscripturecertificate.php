<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Scripture Award Certificate</title>
    <style>
        @page { size: A4 landscape; margin: 0; }
        body { margin: 0; font-family: Arial, sans-serif; text-align: center; }
        .header, .footer { width: 100%; display: block; }
        .content { padding: 0 3%; }
        .convention { text-align: right; font-weight: bold; margin-top: -80px; position: relative; }
        .name { font-size: 30px; font-style: italic; margin: 30px 0; }
        .books { font-size: 24px; font-style: italic; margin: 18px auto; max-width: 90%; }
        .verse { margin: 45px auto; font-size: 12px; font-weight: bold; font-style: italic; }
        .signature { width: 70px; }
    </style>
</head>
<body>
    <img class="header" src="<?= h(HTTP_PATH . '/img/front/certificates/' . $header) ?>" alt="">
    <div class="content">
        <p class="convention"><?= h($submission['convention_name']) ?></p>
        <p>Accelerate Educational Ministries takes pleasure in presenting this award to</p>
        <p class="name"><?= h(trim($submission['first_name'] . ' ' . $submission['middle_name'] . ' ' . $submission['last_name'])) ?></p>
        <p>for memorising the book of</p>
        <p class="books"><?= h(implode(', ', array_column($books, 'book_name'))) ?></p>
        <p class="verse">A word fitly spoken is like apples of gold<br>in pictures of silver<br>Proverbs 25:11</p>
        <img class="signature" src="<?= h(HTTP_PATH . '/img/front/certificates/signature.png') ?>" alt="">
        <p>Slabbert Pretorius<br><small>MANAGING DIRECTOR<br>Southern Cross Educational Enterprises Ltd.</small></p>
    </div>
    <img class="footer" src="<?= h(HTTP_PATH . '/img/front/certificates/' . $footer) ?>" alt="">
    <script>window.print();</script>
</body>
</html>
