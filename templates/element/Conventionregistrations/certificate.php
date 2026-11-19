<?php
$headerPath = 'img/front/certificates/participation_certificate/participation_certificate_header_new.png';
$headerImg = HTTP_PATH . '/' . $headerPath . '?v=' . hash_file('sha256', WWW_ROOT . $headerPath);
$footerImg = HTTP_PATH . '/img/front/certificates/participation_certificate/participation_certificate_footer_new.png';
$signatureImg = HTTP_PATH . '/img/front/certificates/participation_certificate/participation_certificate_signature.png';
$positionNumber = isset($certificateData['position']) ? (int)$certificateData['position'] : null;
$positionSuffix = match ($positionNumber) {
    1 => 'st',
    2 => 'nd',
    3 => 'rd',
    default => 'th',
};
?>
<section class="certificate" aria-label="Convention certificate">
    <img class="certificate-art certificate-header" src="<?= h($headerImg) ?>" alt="">
    <img class="certificate-art certificate-footer" src="<?= h($footerImg) ?>" alt="">

    <section class="certificate-content" aria-label="Award certificate">
        <p class="issuer">Accelerate Educational Ministries</p>
        <p class="affiliation">in affiliation with</p>
        <h1 class="organization"><i>Accelerate Christian Education <sup>&reg;</sup></i></h1>
        <p class="award-intro">takes pleasure in presenting this Award to</p>
        <h2 class="recipient"><?= h($certificateData['student_name']) ?></h2>
        <span class="of-school">of</span>
        <h2 class="school"><?= h($certificateData['school_name']) ?></h2>
        <?php if ($positionNumber !== null): ?>
            <p class="placement">For placing <b><?= $positionNumber ?><sup><?= h($positionSuffix) ?></sup></b> in <b><?= h($certificateData['event_name']) ?></b> at</p>
        <?php else: ?>
            <p class="placement">For participation in</p>
        <?php endif; ?>
        <h2 class="convention"><?= h($certificateData['convention_name']) ?> <?= h($certificateData['season_year']) ?></h2>
    </section>

    <div class="certificate-signature">
        <img src="<?= h($signatureImg) ?>" alt="">
        <strong>Slabbert Pretorius</strong>
        <span>MANAGING DIRECTOR</span>
        <span>Southern Cross Educational Enterprises Ltd.</span>
    </div>

    <p class="certificate-quote">And whatsoever you do, do it heartily, as to the<br>Lord, and not unto men&quot; Colossians 3:23</p>
</section>
