<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Place Certificate :: <?= h($arrCertData['convention_name']) ?> - <?= h($arrCertData['season_year']) ?> For <?= h($arrCertData['student_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Pinyon+Script&display=swap" rel="stylesheet">
    <link href="<?= h(HTTP_PATH . '/css/front/certificate.css?v=' . hash_file('sha256', WWW_ROOT . 'css/front/certificate.css')) ?>" rel="stylesheet">
    <style>
        @page {
            size: A4 landscape;
            margin: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            background: #fff;
        }
    </style>
</head>
<body>
    <?= $this->element('Conventionregistrations/certificate', ['certificateData' => $arrCertData]) ?>
    <script>
        window.addEventListener('load', async function () {
            await document.fonts.ready;
            window.print();
        });
    </script>
</body>
</html>
