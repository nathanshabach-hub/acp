<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src file: 'unsafe-inline'; font-src file:; img-src data:">
    <title>Judges Evaluations</title>
    <?php foreach (['bootstrap.min.css', 'style_front.css', 'responsive.css', 'forms.css'] as $stylesheet): ?>
        <link rel="stylesheet" href="<?= h('file://' . WWW_ROOT . 'css/front/' . $stylesheet) ?>">
    <?php endforeach; ?>
</head>
<body>
    <?= $this->fetch('content') ?>
</body>
</html>
