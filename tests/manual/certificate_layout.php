<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\View\AppView;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$data = [
    'student_name' => 'Student <Example>',
    'school_name' => 'School & Academy',
    'convention_name' => 'Student Convention',
    'season_year' => 2026,
];
$awards = [
    ['position' => 2, 'event_name' => 'Reading & Speaking'],
    ['position' => 5, 'event_name' => 'Spelling <U16>'],
];
$view = new AppView();
$view->set('arrCertData', $data);
$participation = $view->element('Judgeevaluations/participationcertificatepdf');
$check(str_contains($participation, 'For participation in') && !str_contains($participation, 'For placing'),
    'Participation keeps participation wording, not placement wording.');
$view->set('placeCertData', []);
$check(trim($view->element('Judgeevaluations/placecertificatepdf')) === '',
    'Students without awards do not acquire a place certificate.');
$view->set('placeCertData', $awards);
$package = $view->element('Judgeevaluations/placecertificatepdf');
$check(substr_count($package, 'class="certificate"') === 2,
    'Each award renders its own certificate.');
$check(substr_count($package, 'class="page-break"') === 1,
    'Only the gap between awards gets an explicit page break.');
$check(str_contains($package, '2<sup>nd</sup>') && str_contains($package, '5<sup>th</sup>'),
    'Each award retains its own correctly formatted ordinal.');
$check(str_contains($package, 'Reading &amp; Speaking') && str_contains($package, 'Spelling &lt;U16&gt;'),
    'Event names remain distinct and HTML-escaped.');
$check(str_contains($package, 'Student &lt;Example&gt;') && str_contains($package, 'School &amp; Academy'),
    'Student and school names are escaped.');
foreach ($awards as $award) {
    $awardData = array_merge($data, $award);
    $view->set('arrCertData', $awardData);
    $standalone = $view->render('Conventionregistrations/placecertificatepdf', false);
    $certificate = $view->element('Conventionregistrations/certificate', ['certificateData' => $awardData]);
    $check(str_contains($standalone, $certificate) && str_contains($package, $certificate),
        'Standalone and packaged awards use identical certificate markup.');
}
echo "PASS: $checks certificate rendering checks; no database changes.\n";
