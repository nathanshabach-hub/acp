<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\ConventionregistrationsController;
use App\Service\JudgeRegistrationBilling;
use App\Service\TransactionCancellation;
use App\Service\TransactionInvoices;
use App\Scheduling\JudgeScheduleGate;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Message;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run judge billing fixtures locally only.');
}
\Cake\Routing\Router::reload();
(require dirname(__DIR__, 2) . '/config/routes.php')(\Cake\Routing\Router::createRouteBuilder('/'));
$db = ConnectionManager::get('default');
$billing = new JudgeRegistrationBilling($db);
$invoices = new TransactionInvoices($db);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try {
        $call();
    } catch (DomainException $error) {
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};
foreach (['conventions', 'conventionseasons', 'conventionseasonevents', 'conventionregistrations', 'transactions', 'transactionjudges', 'schedulings', 'schedulingtimings'] as $table) {
    $check($db->execute('SHOW TABLE STATUS WHERE Name=?', [$table])->fetch('assoc')['Engine'] === 'InnoDB', 'Fixture storage is transactional: ' . $table);
}
$clone = static function (string $table, array $row, array $changes) use ($db): int {
    unset($row['id']);
    $row = array_replace($row, $changes);
    $db->execute("INSERT INTO $table (`" . implode('`,`', array_keys($row)) . '`) VALUES (' .
        implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
    return (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
};
$session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
$request = static function (string $action, string $method = 'GET', array $data = [], string $controller = 'Conventionregistrations', ?string $prefix = null) use ($session): ServerRequest {
    $request = new ServerRequest([
        'session' => $session, 'environment' => ['REQUEST_METHOD' => $method], 'post' => $data,
        'params' => ['controller' => $controller, 'action' => $action, 'prefix' => $prefix, 'plugin' => null, '_ext' => null],
    ]);
    \Cake\Routing\Router::setRequest($request);
    return $request;
};
$db->enableSavePoints();
$db->begin();
$temp = tempnam(sys_get_temp_dir(), 'acp-judge-invoice-');
try {
    $judge = $db->execute("SELECT * FROM users WHERE user_type='Judge' AND COALESCE(school_id,0)=0
        AND status=1 AND activation_status=1 AND last_name<>'' LIMIT 1")->fetch('assoc');
    $supervisor = $db->execute("SELECT * FROM users WHERE user_type='Teacher_Parent' AND status=1 AND activation_status=1 LIMIT 1")->fetch('assoc');
    if (!$judge || !$supervisor) {
        throw new RuntimeException('Existing verified judge and supervisor accounts are required; users are never modified by fixtures.');
    }
    $judgeId = (int)$judge['id'];
    $source = $db->execute('SELECT cs.* FROM conventionseasons cs JOIN conventions c ON c.id=cs.convention_id AND c.status=1
        JOIN conventionseasonevents e ON e.conventionseasons_id=cs.id LIMIT 1')->fetch('assoc');
    $convention = $db->execute('SELECT * FROM conventions WHERE id=?', [$source['convention_id']])->fetch('assoc');
    $slug = 'judge-fee-fixture-' . bin2hex(random_bytes(8));
    $conventionId = $clone('conventions', $convention, ['slug' => $slug, 'name' => 'Judge Fee Fixture']);
    $contextId = $clone('conventionseasons', $source, ['slug' => $slug, 'convention_id' => $conventionId, 'judge_registration_fees' => '25.50', 'judge_schedule_state' => null]);
    $event = $db->execute('SELECT * FROM conventionseasonevents WHERE conventionseasons_id=? LIMIT 1', [$source['id']])->fetch('assoc');
    $clone('conventionseasonevents', $event, ['conventionseasons_id' => $contextId]);
    $seasonId = (int)$source['season_id'];
    $eventIds = [(int)$event['event_id']];
    $check(JudgeRegistrationBilling::requiresFee($judge), 'Standalone judges require the new fee');
    $check(!JudgeRegistrationBilling::requiresFee($supervisor), 'Supervisors acting as judges are exempt');
    $check(!JudgeRegistrationBilling::requiresFee(array_replace($judge, ['school_id' => 10])), 'School-linked judge accounts are exempt');
    foreach (['-1', '12.001', '1e2', 'NaN', [], 12.3, '100000000.00'] as $invalid) {
        $reject(fn() => JudgeRegistrationBilling::amount($invalid), 'Invalid fee rejected');
    }
    $table = \Cake\ORM\TableRegistry::getTableLocator()->get('Conventionseasons');
    foreach (['-1', '2.001', '1e2'] as $invalid) {
        $check($table->newEntity(['judge_registration_fees' => $invalid])->hasErrors(), 'Admin fee input validated');
    }
    $session->write('admin_id', 1);
    $admin = new \App\Controller\Admin\ConventionsController($request('changeprices', 'POST',
        ['Conventionseasons' => ['judge_registration_fees' => '30.25']], 'Conventions', 'Admin'));
    $admin->changeprices($slug, $slug);
    $check((string)$db->execute('SELECT judge_registration_fees FROM conventionseasons WHERE id=?', [$contextId])->fetchColumn(0) === '30.25', 'Admin nested form saves the exact fee');
    $invalidAdmin = new \App\Controller\Admin\ConventionsController($request('changeprices', 'POST',
        ['Conventionseasons' => ['judge_registration_fees' => '-1']], 'Conventions', 'Admin'));
    $invalidAdmin->changeprices($slug, $slug);
    $check($invalidAdmin->viewBuilder()->getVar('conventionseasons')->hasErrors()
        && (string)$db->execute('SELECT judge_registration_fees FROM conventionseasons WHERE id=?', [$contextId])->fetchColumn(0) === '30.25',
        'Invalid admin price is reported without changing the saved fee');
    $entity = $table->get($contextId);
    $table->patchEntity($entity, ['judge_registration_fees' => '']);
    $table->saveOrFail($entity);
    $check($db->execute('SELECT judge_registration_fees FROM conventionseasons WHERE id=?', [$contextId])->fetch('assoc')['judge_registration_fees'] === null, 'Blank admin price is unset, not silently free');
    $db->execute('UPDATE conventionseasons SET judge_registration_fees=25.50 WHERE id=?', [$contextId]);
    $session->write(['user_id' => $judgeId, 'user_type' => 'Judge']);
    $priceController = new ConventionregistrationsController($request('judgepricestructure'));
    $priceController->judgepricestructure($slug, (string)$seasonId);
    $view = new \App\View\AppView($priceController->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $view->disableAutoLayout();
    $view->set($priceController->viewBuilder()->getVars());
    $html = $view->render('Conventionregistrations/judgepricestructure');
    $check(str_contains($html, '25.50') && str_contains($html, 'select_judge_fee') && str_contains($html, '_csrfToken'), 'Price selection page shows the exact fee, required choice and CSRF token');
    if (($argv[1] ?? '') === '--preview' && isset($argv[2])) {
        $view->set('userDetails', new \Cake\ORM\Entity(['id' => 0, 'user_type' => 'Judge', 'first_name' => 'Synthetic', 'last_name' => 'Judge', 'school_id' => 0]));
        $preview = $view->render('Conventionregistrations/judgepricestructure');
        file_put_contents($argv[2], '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/css/front/bootstrap.min.css"><link rel="stylesheet" href="/css/front/style_front.css"><link rel="stylesheet" href="/css/front/responsive.css"><link rel="stylesheet" href="/css/front/forms.css"><link rel="stylesheet" href="/css/front/portal.css"></head><body class="user-portal"><div id="portal-content">' . $preview . '</div></body></html>');
    }
    $controller = new ConventionregistrationsController($request('judgesregisterconvention'));
    $response = $controller->judgesregisterconvention($slug, (string)$seasonId);
    $check(str_contains($response->getHeaderLine('Location'), '/judgepricestructure/'), 'Direct event link cannot bypass fee selection');
    $reject(fn() => $billing->submit($judgeId, $slug, $seasonId, $eventIds), 'Service also blocks fee bypass');
    $reject(fn() => $billing->selectPrice((int)$supervisor['id'], $slug, $seasonId, '25.50'), 'Exempt supervisors cannot be billed as standalone judges');
    $reject(fn() => $billing->selectPrice($judgeId, $slug, $seasonId, '0'), 'Forged posted price is rejected');
    $priceController = new ConventionregistrationsController($request('judgepricestructure', 'POST', ['select_judge_fee' => '1', 'judge_fee' => '25.50']));
    $response = $priceController->judgepricestructure($slug, (string)$seasonId);
    $check($response->getStatusCode() === 303 && str_contains($response->getHeaderLine('Location'), '/judgesregisterconvention/'), 'Saving selected fee redirects to judging events');
    $registration = $billing->registration($judgeId, $contextId);
    $repeat = $billing->selectPrice($judgeId, $slug, $seasonId, '25.50');
    $check($registration['id'] === $repeat['id'] && $registration['judge_registration_fee'] === '25.50', 'Repeated fee selection uses one owned registration');
    $savedPricePage = new ConventionregistrationsController($request('judgepricestructure'));
    $savedPricePage->judgepricestructure($slug, (string)$seasonId);
    $savedPriceView = new \App\View\AppView($savedPricePage->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $savedPriceView->disableAutoLayout();
    $savedPriceView->set($savedPricePage->viewBuilder()->getVars());
    $savedPriceHtml = $savedPriceView->render('Conventionregistrations/judgepricestructure');
    $check(!str_contains($savedPriceHtml, 'Continue with saved fee') && str_contains($savedPriceHtml, 'Save and choose events'),
        'Returning judges see only the fee selection action when a price is configured');
    $db->execute('UPDATE conventionseasons SET judge_registration_fees=NULL WHERE id=?', [$contextId]);
    $unsetPricePage = new ConventionregistrationsController($request('judgepricestructure'));
    $unsetPricePage->judgepricestructure($slug, (string)$seasonId);
    $unsetPriceView = new \App\View\AppView($unsetPricePage->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $unsetPriceView->disableAutoLayout();
    $unsetPriceView->set($unsetPricePage->viewBuilder()->getVars());
    $unsetPriceHtml = $unsetPriceView->render('Conventionregistrations/judgepricestructure');
    $check(str_contains($unsetPriceHtml, 'Choose events') && str_contains($unsetPriceHtml, '/judgesregisterconvention/'),
        'A judge with a saved fee can still choose events if the current fee is unset');
    $db->execute('UPDATE conventionseasons SET judge_registration_fees=25.50 WHERE id=?', [$contextId]);
    $preEvent = $db->execute("SELECT ce.* FROM conventionseasonevents ce
        JOIN events e ON e.id=ce.event_id JOIN divisions d ON d.id=e.division_id
        WHERE d.name='Photography' AND e.id<>? LIMIT 1", [$eventIds[0]])->fetch('assoc');
    if (!$preEvent) {
        throw new RuntimeException('An existing photography event is required for the accordion fixture.');
    }
    $clone('conventionseasonevents', $preEvent, ['conventionseasons_id' => $contextId]);
    $eventPage = new ConventionregistrationsController($request('judgesregisterconvention'));
    $eventPage->judgesregisterconvention($slug, (string)$seasonId);
    $eventView = new \App\View\AppView($eventPage->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $eventView->disableAutoLayout();
    $eventView->set($eventPage->viewBuilder()->getVars());
    $eventHtml = $eventView->render('Conventionregistrations/judgesregisterconvention');
    $preEventId = (int)$preEvent['event_id'];
    $preSection = explode('<details class="jr-accordion"', $eventHtml)[1];
    $interestInput = 'name="Conventionregistrations[interest_event_ids][]" value="' . $preEventId . '"';
    $check(str_contains($preSection, 'Pre-Judged Events') && str_contains($preSection, $interestInput),
        'Photography division event is rendered in Pre-Judged Events');
    $check(str_contains($eventHtml, 'In-Person Events') && substr_count($eventHtml, $interestInput) === 1,
        'Both accordions render and the pre-judged event appears in exactly one category');
    $check(str_contains($eventHtml, 'In-Person Events (1) - Locked')
        && substr_count($eventHtml, '<details class="jr-accordion"') === 1,
        'In-person events cannot be opened before a completed schedule');
    $check(\App\Utility\JudgingEventCategories::isPreJudged('Academic', 'BOOK REPORT OPEN')
        && !\App\Utility\JudgingEventCategories::isPreJudged('Athletics', 'Chess Open'),
        'Registration uses the admin pre-judging category rules, including named exceptions');
    $check($billing->transaction((int)$registration['id']) === null, 'Price selection alone does not create an invoice request');
    $gate = new JudgeScheduleGate($db);
    $check(!$gate->isOpen($contextId), 'An existing convention without a completed run remains locked');
    $inPersonEventId = $eventIds[0];
    $check($gate->lockedSelections($contextId, [$preEventId], (string)$inPersonEventId) === [$preEventId, $inPersonEventId],
        'Saving pre-judged selections preserves earlier in-person selections while locked');
    $reject(fn() => $gate->lockedSelections($contextId, [$inPersonEventId], null), 'New in-person selections are rejected while locked');
    $forged = new ConventionregistrationsController($request('judgesregisterconvention', 'POST', ['Conventionregistrations' => ['judges_event_ids' => [$inPersonEventId]]]));
    $forgedResponse = $forged->judgesregisterconvention($slug, (string)$seasonId);
    $check(str_contains($forgedResponse->getHeaderLine('Location'), '/judgesregisterconvention/')
        && $billing->transaction((int)$registration['id']) === null,
        'The judge POST cannot bypass the in-person lock or create an invoice');
    $token = $gate->start($contextId);
    $gate->advance($contextId, $token, 0, 1);
    $gate->enter($contextId, $token, 2);
    $gate->advance($contextId, $token, 2, 6);
    $check(!$gate->isOpen($contextId), 'An interrupted or out-of-order scheduling run remains locked');
    $token = $gate->start($contextId);
    for ($stage = 1; $stage <= 6; $stage++) {
        $gate->advance($contextId, $token, $stage - 1, $stage);
    }
    $check(!$gate->isOpen($contextId), 'A completed run with no scheduled events remains locked');
    $scheduling = $db->execute('SELECT * FROM schedulings LIMIT 1')->fetch('assoc');
    $timing = $db->execute('SELECT * FROM schedulingtimings WHERE is_bye=0 LIMIT 1')->fetch('assoc');
    if (!$scheduling || !$timing) {
        throw new RuntimeException('Scheduling configuration and timing fixtures are required.');
    }
    $clone('schedulings', $scheduling, [
        'slug' => $slug, 'conventionseasons_id' => $contextId, 'convention_id' => $conventionId,
        'season_id' => $seasonId, 'season_year' => $source['season_year'], 'start_date' => '2026-01-05',
        'first_day' => 'Monday', 'number_of_days' => 1, 'normal_starting_time' => '09:00:00',
        'normal_finish_time' => '17:00:00', 'lunch_time_start' => '12:00:00', 'lunch_time_end' => '13:00:00',
        'starting_different_time_first_day_yes_no' => 0, 'judging_breaks_yes_no' => 0, 'sports_day_yes_no' => 0,
    ]);
    $timingId = $clone('schedulingtimings', $timing, [
        'conventionseasons_id' => $contextId, 'convention_id' => $conventionId,
        'season_id' => $seasonId, 'season_year' => $source['season_year'], 'schedule_category' => 4,
        'event_id' => $inPersonEventId, 'user_type' => 'Student', 'user_id' => $judgeId,
        'user_id_opponent' => 0, 'group_name' => null, 'group_name_user_ids' => null,
        'group_name_opponent' => null, 'group_name_opponent_user_ids' => null,
        'schtimeautoid1' => null, 'schtimeautoid2' => null, 'is_bye' => 0,
        'sch_date_time' => '2026-01-05 09:00:00', 'day' => 'Monday',
        'start_time' => '09:00:00', 'finish_time' => '09:10:00',
    ]);
    $check($gate->isOpen($contextId), 'A complete conflict-free schedule unlocks in-person events');
    $openPage = new ConventionregistrationsController($request('judgesregisterconvention'));
    $openPage->judgesregisterconvention($slug, (string)$seasonId);
    $openView = new \App\View\AppView($openPage->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $openView->disableAutoLayout();
    $openView->set($openPage->viewBuilder()->getVars());
    $openHtml = $openView->render('Conventionregistrations/judgesregisterconvention');
    $check(substr_count($openHtml, '<details class="jr-accordion"') === 2
        && !str_contains($openHtml, 'In-Person Events (1) - Locked'),
        'The in-person accordion is expandable when the schedule is ready');
    $db->execute('UPDATE schedulingtimings SET start_time=NULL WHERE id=?', [$timingId]);
    $check(!$gate->isOpen($contextId), 'Unscheduled bookings relock in-person events');
    $db->execute("UPDATE schedulingtimings SET start_time='09:00:00' WHERE id=?", [$timingId]);
    $secondTimingId = $clone('schedulingtimings', $db->execute('SELECT * FROM schedulingtimings WHERE id=?', [$timingId])->fetch('assoc'), ['event_id' => $preEventId]);
    $check(!$gate->isOpen($contextId), 'New conflicts relock in-person events after a completed run');
    $db->execute('DELETE FROM schedulingtimings WHERE id=?', [$secondTimingId]);
    $check($gate->isOpen($contextId), 'Resolving conflicts unlocks in-person events again');
    $gate->clear($contextId);
    $check(!$gate->isOpen($contextId), 'Clearing schedules locks in-person events again');
    $eventIds = [$preEventId];
    foreach ([[], ['invalid'], [0], [99999999]] as $invalid) {
        $reject(fn() => $billing->submit($judgeId, $slug, $seasonId, $invalid), 'Empty or invalid events rejected');
    }
    $db->execute('UPDATE conventionseasons SET judge_registration_fees=40.00 WHERE id=?', [$contextId]);
    $reject(fn() => $billing->selectPrice($judgeId, $slug, $seasonId, '25.50'), 'Stale displayed price cannot be accepted');
    $transaction = $billing->submit($judgeId, $slug, $seasonId, $eventIds);
    $check($transaction['final_amount_paid'] === 25.5 || $transaction['final_amount_paid'] === '25.50', 'Event submission honours the selected price snapshot');
    $check((int)$transaction['status'] === 3 && (int)$transaction['user_id'] === $judgeId, 'Invoice request is pending and owned by the judge');
    $check($billing->submit($judgeId, $slug, $seasonId, $eventIds)['id'] === $transaction['id'], 'Repeat event save does not duplicate invoice requests');
    $db->execute('UPDATE conventionregistrations SET judges_event_ids=? WHERE id=?', [(string)$inPersonEventId, $registration['id']]);
    $eventController = new ConventionregistrationsController($request('judgesregisterconvention', 'POST', ['Conventionregistrations' => ['judges_event_ids' => $eventIds]]));
    $response = $eventController->judgesregisterconvention($slug, (string)$seasonId);
    $check($response->getStatusCode() === 303 && str_contains($response->getHeaderLine('Location'), '/myregistrations'), 'Event submission controller completes without duplicate billing');
    $savedIds = array_map('intval', explode(',', $billing->registration($judgeId, $contextId)['judges_event_ids']));
    $check(in_array($preEventId, $savedIds, true) && in_array($inPersonEventId, $savedIds, true),
        'A locked-page judge save keeps previously selected in-person events');
    $supervisorRegId = $clone('conventionregistrations', $billing->registration($judgeId, $contextId), [
        'slug' => $slug . '-supervisor', 'user_id' => (int)$supervisor['id'], 'judges_event_ids' => (string)$inPersonEventId,
    ]);
    $session->write(['user_id' => (int)$supervisor['id'], 'user_type' => 'Teacher_Parent']);
    (new ConventionregistrationsController($request('judgesregisterconvention', 'POST', ['Conventionregistrations' => ['judges_event_ids' => [$preEventId]]])))->judgesregisterconvention($slug, (string)$seasonId);
    $supervisorIds = array_map('intval', explode(',', $db->execute('SELECT judges_event_ids FROM conventionregistrations WHERE id=?', [$supervisorRegId])->fetchColumn(0)));
    $check(in_array($preEventId, $supervisorIds, true) && in_array($inPersonEventId, $supervisorIds, true),
        'A locked-page supervisor save also preserves earlier in-person selections');
    $db->execute('DELETE FROM conventionregistrations WHERE id=?', [$supervisorRegId]);
    $session->write(['user_id' => $judgeId, 'user_type' => 'Judge']);
    $billing->selectPrice($judgeId, $slug, $seasonId, '40.00');
    $check($billing->registration($judgeId, $contextId)['judge_registration_fee'] === '25.50', 'Submitted registration fee is immutable');
    $transaction = $invoices->transaction($transaction['slug']);
    $charges = $invoices->charges($transaction);
    $check($charges['judge_qty'] === 1 && $charges['judge_gross'] === 2550 && $charges['student_qty'] === 0 && $charges['supervisor_qty'] === 0, 'Invoice uses a dedicated judge line, never a student or supervisor charge');
    $check($transaction['school_name'] === trim($judge['first_name'] . ' ' . $judge['last_name']), 'Judge invoice uses full billing name');
    $adminTransaction = new \App\Controller\Admin\TransactionsController($request('viewdetails', 'GET', [], 'Transactions', 'Admin'));
    $adminTransaction->viewdetails($transaction['slug']);
    $adminView = new \App\View\AppView($adminTransaction->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $adminView->disableAutoLayout();
    $adminView->set($adminTransaction->viewBuilder()->getVar('invoiceData'));
    $adminHtml = $adminView->element('Admin/Transactions/invoice');
    $check(str_contains($adminHtml, 'Judges Registration') && str_contains($adminHtml, '25.50') && !str_contains($adminHtml, '<td>Student Registration</td>'), 'Admin can review judge-only invoice charges in transaction details');
    $invoice = $invoices->generate($transaction['slug'], 1, "Fixture Bank\nAccount: 00000000");
    $check($invoice['data']['total'] === 2550 && $invoices->find((int)$transaction['id'])['number'] === $invoice['number'], 'Admin generates one saved judge invoice');
    file_put_contents($temp, $invoices->pdf($invoice, false));
    $process = proc_open(['pdftotext', '-layout', $temp, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $text = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($process) === 0 && str_contains($text, 'Judges Registration') && str_contains($text, '25.50 AUD'), 'Judge PDF has the correct fee label and total');
    $check(substr_count($text, "\f") === 1, 'Judge invoice remains one A4 page');
    $transport = new class extends AbstractTransport {
        public ?Message $captured = null;
        public function send(Message $message): array
        {
            $this->captured = $message;
            return ['headers' => '', 'message' => ''];
        }
    };
    $mail = new \App\Utility\LegacyEmail();
    $mail->setTransport($transport);
    $check($invoices->send($transaction['slug'], 1, $mail), 'Admin invoice send succeeds using memory-only transport');
    $check(array_keys($transport->captured->getTo()) === [$judge['email_address']], 'Invoice addressed only to the registered judge email');
    $db->execute("INSERT INTO school_notifications (school_id,event_key,kind,subject,sender,body,created)
        VALUES (?,?,'email',?,'Portal','Judge invoice fixture',NOW())",
        [$judgeId, $slug, 'Convention invoice ' . $invoice['number']]);
    $noticeId = (string)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    $pdfResponse = (new \App\Controller\UsersController($request('notificationinvoice', 'GET', [], 'Users')))->notificationinvoice($noticeId);
    $check(str_starts_with((string)$pdfResponse->getBody(), '%PDF-') && str_contains($pdfResponse->getHeaderLine('Cache-Control'), 'no-store'), 'Judge can privately open their sent invoice from Notifications');
    $foreignJudge = (int)$db->execute("SELECT id FROM users WHERE user_type='Judge' AND status=1 AND activation_status=1 AND id<>? LIMIT 1", [$judgeId])->fetchColumn(0);
    $session->write('user_id', $foreignJudge);
    try {
        (new \App\Controller\UsersController($request('notificationinvoice', 'GET', [], 'Users')))->notificationinvoice($noticeId);
        throw new RuntimeException('Foreign judge invoice was exposed');
    } catch (\Cake\Http\Exception\NotFoundException $error) {
        $check(true, 'Another judge cannot open the invoice');
    }
    $session->write('user_id', $judgeId);
    $cancellation = new TransactionCancellation($db);
    $cancellation->cancel($transaction['slug'], 1, 'Judge fee fixture');
    $check($billing->transaction((int)$registration['id']) === null && $invoices->find((int)$transaction['id']) === null, 'Cancellation removes judge billing line and invoice snapshot');
    $replacement = $billing->submit($judgeId, $slug, $seasonId, $eventIds);
    $check($replacement['id'] !== $transaction['id'], 'Resubmission after cancellation creates a fresh invoice request');
    $check($cancellation->confirm((int)$replacement['id'], 'fixture-reference', 'fixture payment'), 'Judge payment can be confirmed');
    $check((int)$db->execute('SELECT status FROM transactionjudges WHERE transaction_id=?', [$replacement['id']])->fetchColumn(0) === 1, 'Payment confirmation updates judge line');
    $check((int)$db->execute('SELECT status FROM conventionregistrations WHERE id=?', [$registration['id']])->fetchColumn(0) === 2, 'Payment confirmation does not approve judge eligibility');
    $check($billing->submit($judgeId, $slug, $seasonId, $eventIds)['id'] === $replacement['id'], 'Paid judge registrations cannot be double-billed');
    $reject(fn() => $cancellation->cancel($replacement['slug'], 1, 'fixture'), 'Paid judge transaction cannot use unpaid cancellation');
    $cancellation->deleteConfirmed($replacement['slug'], 1, 'Fixture confirmed deletion');
    $db->execute('UPDATE conventionseasons SET judge_registration_fees=0 WHERE id=?', [$contextId]);
    $billing->selectPrice($judgeId, $slug, $seasonId, '0');
    $free = $billing->submit($judgeId, $slug, $seasonId, $eventIds);
    $check($invoices->charges($invoices->transaction($free['slug']))['total'] === 0, 'Explicit zero-fee registration remains auditable and invoiceable');
    $db->execute('UPDATE conventionseasons SET judge_registration_fees=NULL WHERE id=?', [$contextId]);
    $reject(fn() => $billing->selectPrice($judgeId, $slug, $seasonId, '0'), 'Unconfigured price does not silently become free');
    $session->write(['user_id' => (int)$supervisor['id'], 'user_type' => 'Teacher_Parent']);
    $supervisorController = new ConventionregistrationsController($request('judgepricestructure'));
    $response = $supervisorController->judgepricestructure($slug, (string)$seasonId);
    $check(str_contains($response->getHeaderLine('Location'), '/judgesregisterconvention/')
        && $billing->registration((int)$supervisor['id'], $contextId) === null, 'Supervisor judging bypasses fee selection without creating a registration charge');
    $schoolId = (int)$db->execute("SELECT id FROM users WHERE user_type='School' AND status=1 AND activation_status=1 LIMIT 1")->fetchColumn(0);
    $session->write(['user_id' => $schoolId, 'user_type' => 'School']);
    try {
        (new ConventionregistrationsController($request('judgesregisterconvention')))->judgesregisterconvention($slug, (string)$seasonId);
        throw new RuntimeException('School account entered standalone judge registration');
    } catch (\Cake\Http\Exception\RedirectException $error) {
        $check(str_contains($error->getMessage(), '/users/dashboard') && $billing->registration($schoolId, $contextId) === null,
            'School accounts cannot bypass standalone judge billing via the legacy event route');
    }
    $middleware = new \App\Middleware\ScopedPublicationCsrfMiddleware();
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return new \Cake\Http\Response();
        }
    };
    foreach ([['judgepricestructure', 'Conventionregistrations', null], ['judgesregisterconvention', 'Conventionregistrations', null], ['changeprices', 'Conventions', 'Admin'], ['addseason', 'Conventions', 'Admin']] as [$action, $controller, $prefix]) {
        try {
            $middleware->process($request($action, 'POST', [], $controller, $prefix), $handler);
            throw new RuntimeException('Missing CSRF accepted: ' . $action);
        } catch (\Cake\Http\Exception\InvalidCsrfTokenException $error) {
            $check(true, 'Missing CSRF rejected: ' . $action);
        }
    }
    echo "PASS: $checks judge registration fee checks. Fixtures rolled back; no external email sent.\n";
} finally {
    $db->rollback(true);
    unlink($temp);
}
