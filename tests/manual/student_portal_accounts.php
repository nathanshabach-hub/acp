<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Deployment\Environment;
use App\Service\StudentPortalAccounts;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Client;
use Cake\ORM\TableRegistry;
use Cake\Utility\Security;

if (Environment::isHosted()) {
    throw new RuntimeException('Run this fixture test locally only.');
}
$db = ConnectionManager::get('default');
$portal = new StudentPortalAccounts($db);
if (($argv[1] ?? '') === '--consume-code') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    try {
        $portal->setup($input['id'], $input['code'], $input['password'], $input['password']);
        exit(0);
    } catch (DomainException) {
        exit(2);
    }
}
$users = TableRegistry::getTableLocator()->get('Users');
$fixtureIds = [];
$loginIds = [];
$clients = [];
$count = 0;
$token = bin2hex(random_bytes(8));
$password = 'Student-test-7K4M9R';
$check = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $count++;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try {
        $call();
    } catch (DomainException) {
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};
$clone = static function (string $type, array $overrides = []) use ($db, $users, &$fixtureIds, $token, $password): object {
    $row = $db->execute('SELECT * FROM users WHERE user_type = ? LIMIT 1', [$type])->fetch('assoc');
    if (!$row) {
        throw new RuntimeException('Missing local fixture source for ' . $type);
    }
    unset($row['id']);
    $suffix = $token . '-' . count($fixtureIds);
    $row = array_replace($row, [
        'slug' => 'portal-test-' . $suffix,
        'first_name' => 'Portal Test',
        'middle_name' => '',
        'last_name' => 'Fixture',
        'email_address' => 'portal-' . $suffix . '@example.invalid',
        'phone' => '',
        'phone2' => '',
        'customer_code' => 'TEST-' . $suffix,
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'status' => 1,
        'activation_status' => 1,
        'created' => date('Y-m-d H:i:s'),
        'modified' => date('Y-m-d H:i:s'),
    ], $overrides);
    $entity = $users->newEntity($row, ['validate' => false]);
    try {
        $users->saveOrFail($entity);
    } finally {
        // users is MyISAM: an afterSave failure can still leave this fixture persisted.
        $saved = $db->execute('SELECT id FROM users WHERE slug = ?', [$row['slug']])->fetchColumn(0);
        if ($saved) {
            $fixtureIds[] = (int)$saved;
        }
    }
    return $entity;
};
$csrf = static function ($response): string {
    if (!preg_match('/name="_csrfToken" value="([^"]+)"/', $response->getStringBody(), $matches)) {
        throw new RuntimeException('Expected a CSRF-protected form.');
    }
    return html_entity_decode($matches[1], ENT_QUOTES);
};
$base = 'http://127.0.0.1:8000';
$newClient = static function () use (&$clients): Client {
    $client = new Client(['redirect' => false, 'timeout' => 30]);
    $clients[] = $client;
    return $client;
};
try {
    $schoolA = $clone('School', ['school_id' => 0]);
    $schoolB = $clone('School', ['school_id' => 0]);
    $a = $clone('Student', ['school_id' => $schoolA->id]);
    $b = $clone('Student', ['school_id' => $schoolA->id]);
    $foreign = $clone('Student', ['school_id' => $schoolB->id]);
    $teacher = $clone('Teacher_Parent', ['school_id' => $schoolA->id]);
    foreach ([$a, $b, $foreign] as $student) {
        $row = $portal->account((int)$student->id);
        $check($row !== null, 'Every ORM-created student must have an ID.');
        $check((bool)preg_match('/\A[2-9A-HJ-NP-Z]{6}\z/', $row['login_id']), 'ID must be six unambiguous random characters.');
        $check(!$row['password_hash'] && !$row['setup_hash'] && !$row['first_login'], 'Creation must not create credentials or mark first login.');
        $check($student->customer_code === $db->execute('SELECT customer_code FROM users WHERE id = ?', [$student->id])->fetchColumn(0), 'Billing code must be unchanged.');
        $loginIds[] = $row['login_id'];
    }
    $check(count(array_unique($loginIds)) === 3, 'Student IDs must be unique.');
    $indexes = $db->execute("SHOW INDEX FROM student_portal_accounts WHERE Key_name = 'student_login_id'")->fetchAll('assoc');
    $check(count($indexes) === 1 && (int)$indexes[0]['Non_unique'] === 0, 'ID uniqueness must be enforced by the database.');
    $check($portal->ensure((int)$a->id)['login_id'] === $loginIds[0], 'ID allocation must be idempotent.');
    $check((int)$db->execute('SELECT COUNT(*) FROM conventionregistrationstudents WHERE student_id IN (?,?,?)', [$a->id, $b->id, $foreign->id])->fetchColumn(0) === 0, 'Fixture students must have no convention registration.');
    $reject(fn() => $portal->issue((int)$a->id, (int)$schoolB->id), 'Foreign schools must not issue codes.');
    $issued = $portal->issue((int)$a->id, (int)$schoolA->id);
    $stored = $portal->account((int)$a->id);
    $check(strlen($issued['code']) === 16 && $stored['setup_hash'] === hash('sha256', $issued['code']), 'Only a hash of the separate setup code may be stored.');
    $check(!$stored['first_login'], 'Code issuance must not mark first login.');
    $reject(fn() => $portal->issue((int)$a->id, (int)$schoolA->id), 'Code issuance must be throttled.');
    $reject(fn() => $portal->setup($loginIds[0], '', $password, $password), 'ID alone must never allow setup.');
    $reject(fn() => $portal->setup($loginIds[0], $issued['code'], 'short', 'short'), 'Short passwords must fail.');
    $reject(fn() => $portal->setup($loginIds[0], $issued['code'], $password, 'different'), 'Password confirmation must match.');
    $reject(fn() => $portal->setup($loginIds[0], $issued['code'], str_repeat('x', 73), str_repeat('x', 73)), 'Bcrypt byte limit must be enforced.');
    $reject(fn() => $portal->setup($loginIds[0], $issued['code'], "abcdef\0x", "abcdef\0x"), 'NUL passwords must fail explicitly.');
    $db->execute('UPDATE student_portal_accounts SET setup_expires = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE student_id = ?', [$a->id]);
    $reject(fn() => $portal->setup($loginIds[0], $issued['code'], $password, $password), 'Expired codes must fail.');
    $db->execute('UPDATE student_portal_accounts SET setup_issued = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE student_id = ?', [$a->id]);
    $rotated = $portal->issue((int)$a->id, (int)$schoolA->id);
    $reject(fn() => $portal->setup($loginIds[0], $issued['code'], $password, $password), 'Rotated codes must fail.');
    $portal->setup(strtolower($loginIds[0]), strtolower($rotated['code']), $password, $password);
    $stored = $portal->account((int)$a->id);
    $check(password_verify($password, $stored['password_hash']) && !$stored['setup_hash'], 'Setup must save a password hash and consume the code.');
    $check(!$stored['first_login'], 'Password setup alone must leave the Account cross.');
    $reject(fn() => $portal->setup($loginIds[0], $rotated['code'], $password, $password), 'A consumed code must not work twice.');
    $reject(fn() => $portal->authenticate($loginIds[0], 'Fixt'), 'Surname prefixes must not authenticate.');
    $reject(fn() => $portal->authenticate((string)$a->customer_code, $password), 'Legacy customer codes must not authenticate.');
    foreach ([0, 2, 3] as $status) {
        $db->execute('UPDATE users SET status = ? WHERE id = ?', [$status, $a->id]);
        $reject(fn() => $portal->authenticate($loginIds[0], $password), 'Inactive/archived/rejected students must not authenticate.');
        $reject(fn() => $portal->issue((int)$a->id, (int)$schoolA->id), 'Inactive/archived/rejected students must not receive codes.');
    }
    $db->execute('UPDATE users SET status = 1, activation_status = 0 WHERE id = ?', [$a->id]);
    $reject(fn() => $portal->authenticate($loginIds[0], $password), 'Unverified students must not authenticate.');
    $db->execute('UPDATE users SET activation_status = 1 WHERE id = ?', [$a->id]);
    $db->execute('UPDATE users SET status = 0 WHERE id = ?', [$schoolA->id]);
    $reject(fn() => $portal->authenticate($loginIds[0], $password), 'An inactive school must block student authentication.');
    $db->execute('UPDATE users SET status = 1 WHERE id = ?', [$schoolA->id]);
    $auth = $portal->authenticate($loginIds[0], $password);
    $check((bool)$portal->account((int)$a->id)['first_login'], 'First successful authentication must set the Account tick.');
    $check($portal->sessionValid((int)$a->id, (int)$auth['credential_version']), 'New student sessions must be valid.');
    $reject(fn() => $portal->changePassword((int)$a->id, 'wrong', $password . '2', $password . '2'), 'Changing a password must require the old password.');
    $version = $portal->changePassword((int)$a->id, $password, $password . '2', $password . '2');
    $check(!$portal->sessionValid((int)$a->id, (int)$auth['credential_version']), 'Password changes must revoke older sessions.');
    $check($portal->sessionValid((int)$a->id, $version), 'The password-changing session must be valid at its new version.');
    $reject(fn() => $portal->authenticate($loginIds[0], $password), 'Old passwords must stop working.');
    $check((int)$portal->authenticate($loginIds[0], $password . '2')['student_id'] === (int)$a->id, 'New passwords must work.');
    $db->execute('UPDATE student_portal_accounts SET setup_issued = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE student_id = ?', [$a->id]);
    $recovery = $portal->issue((int)$a->id, (int)$schoolA->id);
    $check($portal->sessionValid((int)$a->id, $version), 'Issuing a recovery code must not change the password yet.');
    $portal->setup($loginIds[0], $recovery['code'], $password, $password);
    $check(!$portal->sessionValid((int)$a->id, $version), 'Redeeming a recovery code must revoke old sessions.');
    for ($i = 0; $i < 10; $i++) {
        $check($portal->allowAttempt('fixture-' . $token, 'T' . $token), 'First ten account attempts should be allowed.');
    }
    $check(!$portal->allowAttempt('fixture-' . $token, 'T' . $token), 'The eleventh account attempt must be denied.');
    for ($i = 0; $i < 30; $i++) {
        $check($portal->allowAttempt('ip-fixture-' . $token, $token . '-' . $i), 'First thirty IP attempts should be allowed.');
    }
    $check(!$portal->allowAttempt('ip-fixture-' . $token, $token . '-31'), 'The thirty-first IP attempt must be denied.');
    $raceCode = $portal->issue((int)$foreign->id, (int)$schoolB->id);
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--consume-code'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch the concurrent setup test.');
        }
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        fwrite($pipes[0], json_encode(['id' => $loginIds[2], 'code' => $raceCode['code'], 'password' => $password], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $results[] = proc_close($process);
    }
    sort($results);
    $check($results === [0, 2], 'Exactly one concurrent attempt may consume a setup code.');
    $check(!$portal->account((int)$foreign->id)['first_login'], 'Concurrent setup must not mark first login.');

    $schoolClient = $newClient();
    $loginPage = $schoolClient->get($base . '/users/login');
    $check($loginPage->getStatusCode() === 200, 'Local server must be running.');
    $check($schoolClient->post($base . '/users/login', ['Users' => ['user_type' => 'School', 'email_address' => $schoolA->email_address, 'password' => $password]])->getStatusCode() === 403, 'Login must reject missing CSRF tokens.');
    $response = $schoolClient->post($base . '/users/login', ['_csrfToken' => $csrf($loginPage), 'Users' => ['user_type' => 'School', 'email_address' => $schoolA->email_address, 'password' => $password]]);
    $check($response->getStatusCode() === 302, 'Existing adult password login must still work.');
    $list = $schoolClient->get($base . '/users/students');
    $html = $list->getStringBody();
    $check($list->getStatusCode() === 200 && str_contains($html, '<th>Account</th>'), 'School list must render the Account column.');
    $check(str_contains($html, $loginIds[0]) && str_contains($html, $loginIds[1]) && !str_contains($html, $loginIds[2]), 'School lists must show only their own IDs.');
    $check(str_contains($html, '&#10004;') && str_contains($html, '&#10008;'), 'List must show the correct tick and cross.');
    $check($schoolClient->get($base . '/users/studentaccess/' . $foreign->slug)->getStatusCode() === 404, 'Foreign school access must be denied over HTTP.');
    $accessPage = $schoolClient->get($base . '/users/studentaccess/' . $b->slug);
    $check($accessPage->getStatusCode() === 200 && str_contains($accessPage->getHeaderLine('Cache-Control'), 'no-store'), 'School issuance page must never be cached.');
    $check($schoolClient->post($base . '/users/studentaccess/' . $b->slug, ['confirm_issue' => '1'])->getStatusCode() === 403, 'Issuance must reject missing CSRF.');
    $response = $schoolClient->post($base . '/users/studentaccess/' . $b->slug, ['_csrfToken' => $csrf($accessPage), 'confirm_issue' => '1']);
    $check($response->getStatusCode() === 200 && (bool)preg_match('/Single-use setup code: <strong>([A-Z2-9]{16})<\/strong>/', $response->getStringBody(), $matches), 'School must be able to issue a setup code.');
    $webCode = $matches[1];
    $reload = $schoolClient->get($base . '/users/studentaccess/' . $b->slug);
    $check(!str_contains($reload->getStringBody(), $webCode), 'Raw code must not survive a fresh GET.');
    $studentClient = $newClient();
    $setupPage = $studentClient->get($base . '/users/studentsetup');
    $check($setupPage->getStatusCode() === 200 && str_contains($setupPage->getHeaderLine('Cache-Control'), 'no-store'), 'Public setup must be available and not cacheable.');
    $check($studentClient->post($base . '/users/studentsetup', ['Student' => ['login_id' => $loginIds[1], 'code' => $webCode, 'password' => $password, 'confirmation' => $password]])->getStatusCode() === 403, 'Setup must reject missing CSRF.');
    $response = $studentClient->post($base . '/users/studentsetup', ['_csrfToken' => $csrf($setupPage), 'Student' => ['login_id' => $loginIds[1], 'code' => $webCode, 'password' => $password, 'confirmation' => $password]]);
    $check($response->getStatusCode() === 302 && !$portal->account((int)$b->id)['first_login'], 'HTTP setup must save without marking first login.');
    $loginPage = $studentClient->get($base . '/users/login');
    $response = $studentClient->post($base . '/users/login', ['_csrfToken' => $csrf($loginPage), 'Users' => ['user_type' => 'student', 'email_address' => $b->email_address, 'password' => $password]]);
    $check($response->getStatusCode() === 200 && !$portal->account((int)$b->id)['first_login'], 'Noncanonical account types must not route students through legacy password authentication.');
    $response = $studentClient->post($base . '/users/login', ['_csrfToken' => $csrf($loginPage), 'Users' => ['user_type' => 'Student', 'student_code' => $loginIds[1], 'password' => 'Fixt', 'student_last4' => 'Fixt']]);
    $check($response->getStatusCode() === 200 && !$portal->account((int)$b->id)['first_login'], 'Legacy surname login must fail over HTTP.');
    $response = $studentClient->post($base . '/users/login', ['_csrfToken' => $csrf($response), 'Users' => ['user_type' => 'Student', 'student_code' => strtolower($loginIds[1]), 'password' => $password]]);
    $check($response->getStatusCode() === 302 && (bool)$portal->account((int)$b->id)['first_login'], 'Student ID/password login must succeed and mark first login.');
    $check($studentClient->get($base . '/users/dashboard')->getStatusCode() === 200, 'A Global List-only student must have a working dashboard.');
    $check($studentClient->get($base . '/users/students')->getStatusCode() === 403, 'Students must not read school student lists.');
    $profile = $studentClient->get($base . '/users/editprofile');
    $check($profile->getStatusCode() === 200 && str_contains($profile->getStringBody(), $loginIds[1]) && str_contains($profile->getStringBody(), '/users/changepassword'), 'Profile must show authoritative ID and a change-password link.');
    $response = $studentClient->post($base . '/users/editprofile', ['_csrfToken' => $csrf($profile), 'Users' => ['user_type' => 'School', 'school_id' => $schoolB->id]]);
    $check($response->getStatusCode() === 400, 'Student profile must reject identity changes.');
    $check($studentClient->get($base . '/users/studentaccess/' . $b->slug)->getStatusCode() === 403, 'Students must not issue their own codes.');
    $change = $studentClient->get($base . '/users/changepassword');
    $response = $studentClient->post($base . '/users/changepassword', ['_csrfToken' => $csrf($change), 'Users' => ['old_password' => $password, 'new_password' => $password . '2', 'confirm_password' => $password . '2']]);
    $check($response->getStatusCode() === 302 && password_verify($password . '2', $portal->account((int)$b->id)['password_hash']), 'HTTP password change must update authoritative credentials.');
    $check($studentClient->get($base . '/users/editprofile')->getStatusCode() === 200, 'Password-changing session must remain signed in.');
    $check($studentClient->get($base . '/users/logintotest/' . $a->id)->getStatusCode() === 403, 'Passwordless test login must be unavailable.');
    $db->execute('UPDATE users SET status = 2 WHERE id = ?', [$b->id]);
    $response = $studentClient->get($base . '/users/editprofile');
    $check($response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), '/users/login'), 'Archiving must invalidate an existing student session.');
    $teacherClient = $newClient();
    $loginPage = $teacherClient->get($base . '/users/login');
    $response = $teacherClient->post($base . '/users/login', ['_csrfToken' => $csrf($loginPage), 'Users' => ['user_type' => 'Teacher_Parent', 'email_address' => $teacher->email_address, 'password' => $password]]);
    $check($response->getStatusCode() === 302, 'Supervisor login must still work.');
    $check($teacherClient->get($base . '/users/studentaccess/' . $a->slug)->getStatusCode() === 403, 'Supervisors must not issue codes.');
    $supervisorList = $teacherClient->get($base . '/users/students');
    $check($supervisorList->getStatusCode() === 200 && !str_contains($supervisorList->getStringBody(), '/users/studentaccess/'), 'Supervisors may read only their school list without issuance controls.');
    $bulkIds = [];
    for ($i = 0; $i < 101; $i++) {
        $student = $clone('Student', ['school_id' => $schoolA->id, 'first_name' => 'Bulk ' . $i, 'password' => '']);
        $bulkIds[] = (int)$student->id;
    }
    $archived = $clone('Student', ['school_id' => $schoolA->id, 'status' => 2]);
    $inactive = $clone('Student', ['school_id' => $schoolA->id, 'status' => 0]);
    $unverified = $clone('Student', ['school_id' => $schoolA->id, 'activation_status' => 0]);
    $reject(fn() => $portal->issueBulk((int)$schoolA->id, [$bulkIds[0], (int)$foreign->id], false), 'Foreign-school selections must reject the entire bulk request.');
    foreach ([$archived, $inactive, $unverified] as $invalid) {
        $reject(fn() => $portal->issueBulk((int)$schoolA->id, [$bulkIds[0], (int)$invalid->id], false), 'Ineligible selections must reject the entire bulk request.');
    }
    $check($portal->account($bulkIds[0])['setup_hash'] === null, 'Rejected selections must not issue any codes.');
    $firstCode = $portal->issue($bulkIds[0], (int)$schoolA->id);
    $db->execute('UPDATE student_portal_accounts SET setup_issued = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE student_id = ?', [$bulkIds[0]]);
    $secondCode = $portal->issue($bulkIds[1], (int)$schoolA->id);
    $before = $portal->account($bulkIds[0]);
    $reject(fn() => $portal->issueBulk((int)$schoolA->id, [$bulkIds[1], $bulkIds[0]], false), 'Cooldown on a later locked student must roll back the entire batch.');
    $check($portal->account($bulkIds[0]) === $before, 'Rollback must preserve the earlier student code and timestamps.');
    $db->execute('UPDATE student_portal_accounts SET setup_issued = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE student_id = ?', [$bulkIds[1]]);
    $pending = $portal->bulkStudents((int)$schoolA->id, [], true);
    $check(count($pending) === 101, 'All-without-password mode must include more than 100 students and exclude existing passwords and ineligible accounts.');
    $check($schoolClient->get($base . '/users/bulkstudentaccess')->getStatusCode() === 405, 'Bulk issuance must not be accessible by GET.');
    $check($schoolClient->post($base . '/users/bulkstudentaccess', ['mode' => 'without_password'])->getStatusCode() === 403, 'Bulk preview must reject missing CSRF.');
    $bulkList = $schoolClient->get($base . '/users/students');
    $check(str_contains($bulkList->getStringBody(), 'Setup codes for selected') && str_contains($bulkList->getStringBody(), 'Set up all active students without a password'), 'School list must offer selected and all-unenrolled actions.');
    $bulkToken = $csrf($bulkList);
    $review = $schoolClient->post($base . '/users/bulkstudentaccess', ['_csrfToken' => $bulkToken, 'mode' => 'without_password']);
    $check($review->getStatusCode() === 200 && str_contains($review->getStringBody(), '101 students included.'), 'Bulk preview must include all eligible pages.');
    $check($portal->account($bulkIds[2])['setup_hash'] === null, 'Preview must not issue codes.');
    $teacherToken = $csrf($teacherClient->get($base . '/users/login'));
    $check($teacherClient->post($base . '/users/bulkstudentaccess', ['_csrfToken' => $teacherToken, 'mode' => 'without_password', 'confirm_issue' => '1'])->getStatusCode() === 403, 'Supervisors must not issue bulk codes.');
    $bulkResult = $schoolClient->post($base . '/users/bulkstudentaccess', ['_csrfToken' => $csrf($review), 'mode' => 'without_password', 'confirm_issue' => '1']);
    $check($bulkResult->getStatusCode() === 200 && str_contains($bulkResult->getStringBody(), '101 individual codes issued.'), 'Confirmed all-unenrolled action must issue over 100 codes.');
    $check(str_contains($bulkResult->getHeaderLine('Cache-Control'), 'no-store') && $bulkResult->getHeaderLine('Referrer-Policy') === 'no-referrer', 'Bulk code response must be private and not cacheable.');
    $check(str_contains($bulkResult->getStringBody(), 'Print list') && str_contains($bulkResult->getStringBody(), 'Download CSV'), 'Issued report must offer print and CSV without reissuing codes.');
    preg_match_all('/<td class="font-monospace">([A-Z2-9]{6})<\/td>\s*<td class="font-monospace">([A-Z2-9]{16})<\/td>/', $bulkResult->getStringBody(), $issuedRows, PREG_SET_ORDER);
    $check(count($issuedRows) === 101, 'Bulk output must include exactly 101 ID/code pairs.');
    $check(count(array_unique(array_column($issuedRows, 2))) === 101, 'Every bulk code must be distinct.');
    foreach ($bulkIds as $index => $id) {
        $account = $portal->account($id);
        $check($issuedRows[$index][1] === $account['login_id'] && hash('sha256', $issuedRows[$index][2]) === $account['setup_hash'], 'Each output ID/code pair must match its own stored hash.');
        $check($account['first_login'] === null && $account['password_hash'] === null, 'Bulk issuance must not create passwords or mark first login.');
    }
    $reject(fn() => $portal->setup($firstCode['login_id'], $firstCode['code'], $password, $password), 'Bulk replacement must invalidate a prior setup code.');
    $portal->setup($issuedRows[2][1], $issuedRows[2][2], $password, $password);
    $check(count($portal->bulkStudents((int)$schoolA->id, [], true)) === 100, 'All-without-password must exclude a student after setup, even before first login.');
    $db->execute('UPDATE student_portal_accounts SET setup_issued = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE student_id = ?', [$a->id]);
    $selectedReview = $schoolClient->post($base . '/users/bulkstudentaccess', ['_csrfToken' => $bulkToken, 'mode' => 'selected', 'student_ids' => [(string)$a->id]]);
    $check($selectedReview->getStatusCode() === 200 && str_contains($selectedReview->getStringBody(), 'already have a password'), 'Selected mode must explicitly warn about password resets.');
    $oldPasswordHash = $portal->account((int)$a->id)['password_hash'];
    $selectedResult = $schoolClient->post($base . '/users/bulkstudentaccess', ['_csrfToken' => $csrf($selectedReview), 'mode' => 'selected', 'student_ids' => [(string)$a->id, (string)$a->id], 'confirm_issue' => '1']);
    $check($selectedResult->getStatusCode() === 200 && str_contains($selectedResult->getStringBody(), '1 individual codes issued.'), 'Selected mode must issue only unique selected students.');
    $check($portal->account((int)$a->id)['password_hash'] === $oldPasswordHash, 'Bulk password reset issuance must preserve the current password until redemption.');
    $denied = $schoolClient->post($base . '/users/bulkstudentaccess', ['_csrfToken' => $bulkToken, 'mode' => 'selected', 'student_ids' => [(string)$foreign->id], 'confirm_issue' => '1']);
    $check($denied->getStatusCode() === 302, 'Foreign-school bulk HTTP requests must be rejected.');
    $check($schoolClient->post($base . '/users/bulkstudentaccess', ['_csrfToken' => $bulkToken, 'mode' => 'selected', 'student_ids' => ['bad-id']])->getStatusCode() === 400, 'Malformed bulk IDs must be rejected.');
    $targetId = $bulkIds[3];
    $target = $users->get($targetId);
    $targetBefore = $portal->account($targetId);
    $lastLoginBefore = $db->execute('SELECT last_login FROM users WHERE id = ?', [$targetId])->fetchColumn(0);
    $check($schoolClient->get($base . '/users/openstudentportal')->getStatusCode() === 405, 'Opening a student portal must require POST.');
    $check($schoolClient->post($base . '/users/openstudentportal', ['student_slug' => $target->slug])->getStatusCode() === 403, 'Opening a student portal must require CSRF.');
    $check($schoolClient->post($base . '/users/openstudentportal', ['_csrfToken' => $bulkToken, 'student_slug' => $foreign->slug])->getStatusCode() === 404, 'Schools must not open another school student portal.');
    foreach ([$archived, $inactive, $unverified] as $invalid) {
        $check($schoolClient->post($base . '/users/openstudentportal', ['_csrfToken' => $bulkToken, 'student_slug' => $invalid->slug])->getStatusCode() === 404, 'School access must reject archived, inactive and unverified students.');
    }
    $check($teacherClient->post($base . '/users/openstudentportal', ['_csrfToken' => $teacherToken, 'student_slug' => $target->slug])->getStatusCode() === 403, 'Supervisors must not open student portals.');
    $check($portal->schoolAccessValid($targetId, (int)$schoolA->id) && !$portal->schoolAccessValid($targetId, (int)$schoolB->id), 'School sessions must validate current school ownership.');
    $open = $schoolClient->post($base . '/users/openstudentportal', ['_csrfToken' => $bulkToken, 'student_slug' => $target->slug]);
    $check($open->getStatusCode() === 302 && str_contains($open->getHeaderLine('Location'), '/users/dashboard'), 'An active school may open an unenrolled student portal.');
    $dashboard = $schoolClient->get($base . '/users/dashboard');
    $check($dashboard->getStatusCode() === 200 && str_contains($dashboard->getStringBody(), 'Viewing as student:') && str_contains($dashboard->getStringBody(), 'Return to school'), 'Student dashboard must show the viewing banner and return action.');
    $check(str_contains($dashboard->getHeaderLine('Cache-Control'), 'no-store'), 'School student-view pages must not be cached.');
    $viewToken = $csrf($dashboard);
    $viewProfile = $schoolClient->get($base . '/users/editprofile');
    $check($viewProfile->getStatusCode() === 200 && !str_contains($viewProfile->getStringBody(), 'href="/users/changepassword"'), 'Password links must be hidden in student view.');
    $profileSave = $schoolClient->post($base . '/users/editprofile', ['_csrfToken' => $csrf($viewProfile), 'Users' => ['first_name' => 'Updated by school']]);
    $check($profileSave->getStatusCode() === 302 && $users->get($targetId)->first_name === 'Updated by school', 'School viewing must permit real student profile edits.');
    foreach (['changepassword', 'studentsetup', 'forgotpassword', 'resetpassword', 'login', 'registration', 'registerprevdetails', 'logintotest'] as $action) {
        $check($schoolClient->get($base . '/users/' . $action)->getStatusCode() === 403, 'School student-view must block credential route ' . $action);
    }
    $blockedChange = $schoolClient->post($base . '/users/changepassword', ['_csrfToken' => $viewToken, 'Users' => ['old_password' => $password, 'new_password' => $password . '3', 'confirm_password' => $password . '3']]);
    $check($blockedChange->getStatusCode() === 403, 'School student-view must block direct password-changing POSTs.');
    $check($schoolClient->post($base . '/users/editprofile', ['_csrfToken' => $viewToken, 'Users' => ['password' => 'forged']])->getStatusCode() === 400, 'School student-view cannot write credentials through Edit Profile.');
    $check($schoolClient->post($base . '/users/openstudentportal', ['_csrfToken' => $viewToken, 'student_slug' => $a->slug])->getStatusCode() === 403, 'Nested school student-view sessions must be rejected.');
    $check($portal->account($targetId) === $targetBefore, 'School access must never change student credentials, setup code, first_login or credential version.');
    $check($db->execute('SELECT last_login FROM users WHERE id = ?', [$targetId])->fetchColumn(0) === $lastLoginBefore, 'School access must not update student last_login.');
    $check($schoolClient->get($base . '/users/returntoschool')->getStatusCode() === 405, 'Returning to school must require POST.');
    $check($schoolClient->post($base . '/users/returntoschool')->getStatusCode() === 403, 'Returning to school must require CSRF.');
    $db->execute('UPDATE users SET status = 2 WHERE id = ?', [$targetId]);
    $returned = $schoolClient->post($base . '/users/returntoschool', ['_csrfToken' => $viewToken]);
    $check($returned->getStatusCode() === 302 && str_contains($returned->getHeaderLine('Location'), '/users/students'), 'School must be able to return even if the viewed student was archived.');
    $restoredList = $schoolClient->get($base . '/users/students');
    $check($restoredList->getStatusCode() === 200 && !str_contains($restoredList->getStringBody(), 'Viewing as student:'), 'Return must restore the school role and remove viewing context.');
    $check($schoolClient->post($base . '/users/returntoschool', ['_csrfToken' => $csrf($restoredList)])->getStatusCode() === 403, 'Return cannot be invoked without an active school viewing context.');
    $db->execute('UPDATE users SET status = 1 WHERE id = ?', [$targetId]);
    $open = $schoolClient->post($base . '/users/openstudentportal', ['_csrfToken' => $csrf($restoredList), 'student_slug' => $target->slug]);
    $check($open->getStatusCode() === 302, 'Restored school may open another viewing session.');
    $db->execute('UPDATE users SET school_id = ? WHERE id = ?', [$schoolB->id, $targetId]);
    $invalidated = $schoolClient->get($base . '/users/dashboard');
    $check($invalidated->getStatusCode() === 302 && str_contains($invalidated->getHeaderLine('Location'), '/users/login'), 'Changing student school must invalidate existing school viewing access.');
    $db->execute('UPDATE users SET school_id = ? WHERE id = ?', [$schoolA->id, $targetId]);
    echo "PASS: {$count} student portal checks, including real local HTTP and CSRF flows.\n";
} finally {
    foreach ($clients as $client) {
        $client->get($base . '/users/logout');
    }
    foreach ($fixtureIds as $id) {
        $db->execute('DELETE FROM student_portal_accounts WHERE student_id = ?', [$id]);
        $db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }
    $subjects = ['ip:fixture-' . $token, 'ip:ip-fixture-' . $token, 'id:T' . strtoupper($token), 'id:' . strtoupper($token) . '-31'];
    foreach ($loginIds as $id) {
        $subjects[] = 'id:' . $id;
    }
    for ($i = 0; $i < 30; $i++) {
        $subjects[] = 'id:' . strtoupper($token) . '-' . $i;
    }
    foreach ($subjects as $subject) {
        $db->execute('DELETE FROM student_portal_rate_limits WHERE subject = ?', [hash_hmac('sha256', 'student-portal:' . $subject, Security::getSalt())]);
    }
}
