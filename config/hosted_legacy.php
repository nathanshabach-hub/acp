<?php
declare(strict_types=1);

$deployment = \App\Deployment\Environment::settings();
define('SITE_TITLE', 'Accelerate');
define('ADMIN_TITLE', SITE_TITLE . ' :: ');
define('TAG_LINE', '');
define('TITLE_FOR_PAGES', ' :: ' . SITE_TITLE);
define('HTTP_PATH', $deployment['publicUrl']);
define('BASE_PATH', ROOT);
define('HTTP_IMAGE', HTTP_PATH . '/img');
define('HEADERS_FROM_NAME', 'Accelerate Educational Ministries');
define('HEADERS_FROM_EMAIL', $deployment['sender'] ?? 'noreply@example.invalid');
define('MAIL_FROM', HEADERS_FROM_EMAIL);
define('HEADERS_CC', $deployment['copyRecipient'] ?? HEADERS_FROM_EMAIL);
define('ACCOUNTS_TEAM_ANOTHER_EMAIL', HEADERS_CC);
define('SITEKEY', $deployment['captcha']['siteKey'] ?? '');
define('SECRETKEY', $deployment['captcha']['secretKey'] ?? '');
define('PAYPAL_MODE', match ($deployment['payments']) {
    'live' => 'Live',
    'sandbox' => 'Sandbox',
    default => 'Disabled',
});
define('CURR', 'AUD');

// Preserve the legacy business dictionaries without loading local credentials.
global $yesNoDD, $genderDD, $birthYearDD, $conventionTypeDD, $eventTypeDD, $eventUploadTypeDD;
global $eventGroupNameDD, $eventGenderDD, $loginUserTypes, $priceStructureCR, $paymentStatus;
global $romanNumbers, $resultPoints, $eventKindID, $resultPositions, $weekDays, $eventJudgeType;
$yesNoDD = ['1' => 'Yes', '0' => 'No'];
$genderDD = ['Male' => 'Male', 'Female' => 'Female'];
$birthYearDD = array_combine(range((int)date('Y') - 20, (int)date('Y') - 8), range((int)date('Y') - 20, (int)date('Y') - 8));
$conventionTypeDD = ['0' => 'In Person Convention (0)', '1' => 'Online Convention (1)', '3' => 'Small Convention (3)'];
$eventTypeDD = ['0' => 'In Person Only Events (0)', '1' => 'Variable Upload Events (1)', '2' => 'Always Upload Events (2)'];
$eventUploadTypeDD = ['Nil' => 'Nil', 'Document ' => 'Document', 'Image' => 'Image', 'Video' => 'Video', 'Video/Audio' => 'Video/Audio'];
$eventGroupNameDD = ['1' => 'U14', '2' => 'U16', '3' => 'U17', '4' => 'Open'];
$eventGenderDD = ['F' => 'F', 'M' => 'M'];
$loginUserTypes = ['School' => 'School', 'Teacher_Parent' => 'Supervisor', 'Student' => 'Student', 'Judge' => 'Judge'];
$priceStructureCR = [
    'judge_registration' => 'Judges Registration',
    'full_registration' => 'Full Registration', 'scripture_only_registration' => 'Scripture only registration',
    'student_registration_fees' => 'Student registration', 'non_competitor_registration_fees' => 'Non-competitor registration',
    'non_affiliate_registration_fees' => 'Non-affiliate registration',
];
$paymentStatus = ['0' => 'Failed', '1' => 'Confirmed', '2' => 'Pending', '3' => 'Invoiced', '4' => 'Cancelled'];
$romanNumbers = array_combine(range(1, 10), ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X']);
$resultPoints = [1 => 12, 2 => 10, 3 => 8, 4 => 6, 5 => 4, 6 => 2];
foreach ([
    'SCHOOLS_CSV' => 'csv_files', 'EVENTS_HEART' => 'events_heart',
    'EVENTS_SUBMISSION_DOCUMENT' => 'events_submissions', 'JUDGING_REFERENCE_PDF' => 'judgingformpdf',
] as $name => $directory) {
    define('UPLOAD_' . $name . '_PATH', $deployment['storageRoot'] . '/uploads/files/' . $directory . '/');
    define('DISPLAY_' . $name . '_PATH', HTTP_PATH . '/files/' . $directory . '/');
}
$eventKindID = ['Elimination' => 'Elimination', 'Sequential' => 'Sequential', 'Judged' => 'Judged',
    'Pre-Judged' => 'Pre-Judged', 'Group/Heats' => 'Group/Heats'];
$resultPositions = [1 => '1<sup>st</sup>', 2 => '2<sup>nd</sup>', 3 => '3<sup>rd</sup>',
    4 => '4<sup>th</sup>', 5 => '5<sup>th</sup>', 6 => '6<sup>th</sup>'];
$weekDays = array_combine(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
$eventJudgeType = ['general' => 'General', 'times' => 'Times', 'distances' => 'Distances', 'scores' => 'Scores'];
