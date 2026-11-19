<?php foreach ($placeCertData as $placecertIndex => $placecert): ?>
    <?= $this->element('Conventionregistrations/certificate', [
        'certificateData' => array_merge($arrCertData, $placecert),
    ]) ?>
    <?php if ($placecertIndex < count($placeCertData) - 1): ?>
        <div class="page-break"></div>
    <?php endif; ?>
<?php endforeach; ?>
