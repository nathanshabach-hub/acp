<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Controller\Admin\TransactionsController;
use App\Service\TransactionInvoices;
use App\Utility\LegacyEmail;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Message;
use Cake\Mailer\Transport\DebugTransport;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run invoice fixtures locally only.');
}
\Cake\Routing\Router::reload();
(require dirname(__DIR__, 2) . '/config/routes.php')(\Cake\Routing\Router::createRouteBuilder('/'));
$db = ConnectionManager::get('default');
$service = new TransactionInvoices($db);
if (!$service->available()) {
    throw new RuntimeException('Apply invoice migration 013 first.');
}
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$reject = static function (callable $call, string $type, string $message) use ($check): void {
    try {
        $call();
    } catch (Throwable $error) {
        if (!$error instanceof $type) {
            throw $error;
        }
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};
$session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
$request = static function (string $action, string $method = 'GET', array $data = [], int $adminId = 1) use ($session): ServerRequest {
    $session->write('admin_id', $adminId);
    $request = new ServerRequest([
        'session' => $session, 'environment' => ['REQUEST_METHOD' => $method],
        'params' => ['prefix' => 'Admin', 'controller' => 'Transactions', 'action' => $action, 'plugin' => null, '_ext' => null],
        'post' => $data,
    ]);
    \Cake\Routing\Router::setRequest($request);
    return $request;
};
$db->enableSavePoints();
$db->begin();
$temp = tempnam(sys_get_temp_dir(), 'acp-invoice-');
try {
    $source = $db->execute("SELECT t.* FROM transactions t JOIN users u ON u.id=t.user_id
        JOIN conventions c ON c.id=t.convention_id WHERE u.email_address<>'' ORDER BY t.id DESC LIMIT 1")->fetch('assoc');
    if (!$source) {
        throw new RuntimeException('An existing school transaction is required as a fixture source.');
    }
    unset($source['id']);
    $source['slug'] = 'invoice-fixture-' . bin2hex(random_bytes(10));
    $source['status'] = 3;
    $source['final_amount_paid'] = '66.13';
    $source['total_amount'] = '66.13';
    $source['total_discount_applied'] = '4.02';
    $source['transaction_id_received'] = null;
    $db->execute('INSERT INTO transactions (`' . implode('`,`', array_keys($source)) . '`) VALUES (' .
        implode(',', array_fill(0, count($source), '?')) . ')', array_values($source));
    $id = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    foreach ([['20.10', '18.09', '2.01'], ['20.10', '18.09', '2.01'], ['10.05', '10.05', '0.00']] as [$gross, $net, $discount]) {
        $db->execute("INSERT INTO transactionstudents (transaction_id,paid_amount,final_paid_amount,amount_discount_applied,status,student_id) VALUES (?,?,?,?,3,(SELECT id FROM users WHERE user_type='Student' LIMIT 1))",
            [$id, $gross, $net, $discount]);
    }
    for ($i = 0; $i < 2; $i++) {
        $db->execute("INSERT INTO transactionteachers (transaction_id,paid_amount,status,teacher_id) VALUES (?,9.95,3,(SELECT id FROM users WHERE user_type='Teacher_Parent' LIMIT 1))", [$id]);
    }
    $slug = $source['slug'];
    $transaction = $service->transaction($slug);
    $charges = $service->charges($transaction);
    $check($charges['total'] === 6613 && $charges['student_gross'] === 5025 && $charges['supervisor_gross'] === 1990 && $charges['discount'] === 402,
        'Amounts reconcile exactly in integer cents');
    $check($charges['student_qty'] === 3 && $charges['supervisor_qty'] === 2 && $charges['discount_qty'] === 2
        && count($charges['student_rates']) === 2, 'Quantities, discounted students and mixed unit rates are exact');
    $reject(fn() => $service->generate($slug, 0, 'Fixture bank'), DomainException::class, 'Invoice generation requires an admin actor');
    foreach (['', str_repeat('x', 701)] as $bank) {
        $reject(fn() => $service->generate($slug, 1, $bank), DomainException::class, 'Bank details required and bounded');
    }
    $reject(fn() => $service->generate($slug, 1, str_repeat("One line\n", 65)), DomainException::class, 'Over-height bank text rejected before committing');
    $check($service->find($id) === null, 'Failed rendering rolls back the invoice');
    $db->execute('UPDATE transactions SET final_amount_paid=99 WHERE id=?', [$id]);
    $reject(fn() => $service->generate($slug, 1, 'Fixture bank'), DomainException::class, 'Mismatched header and line item totals block issuance');
    $db->execute('UPDATE transactions SET final_amount_paid=66.13 WHERE id=?', [$id]);
    $db->execute('UPDATE transactionstudents SET amount_discount_applied=0 WHERE transaction_id=? AND paid_amount=20.10', [$id]);
    $reject(fn() => $service->generate($slug, 1, 'Fixture bank'), DomainException::class, 'Inconsistent saved discount blocks issuance');
    $db->execute('UPDATE transactionstudents SET amount_discount_applied=2.01 WHERE transaction_id=? AND paid_amount=20.10', [$id]);
    foreach ([0, 4] as $status) {
        $db->execute('UPDATE transactions SET status=? WHERE id=?', [$status, $id]);
        $reject(fn() => $service->generate($slug, 1, 'Fixture bank'), DomainException::class, 'Failed or cancelled transactions cannot be invoiced');
    }
    $db->execute('UPDATE transactions SET status=3 WHERE id=?', [$id]);
    $bank = "Test Bank\nAccount: Invoice Fixture\nBSB: 000-000\nAccount: 00000000";
    $invoice = $service->generate($slug, 1, $bank);
    $check((bool)preg_match('/^ACP-\d{4}-\d{6,}$/', $invoice['number']), 'Invoice uses automatic unique formatted number');
    $check($invoice['issued'] === date('Y-m-d') && $invoice['due'] === (new DateTimeImmutable('today'))->modify('+14 days')->format('Y-m-d'),
        'Invoice issued today with an exact 14-day term');
    $check($invoice['data']['bank'] === $bank && (int)$invoice['created_by'] === 1, 'Invoice persists bank details and issuing admin');
    $repeat = $service->generate($slug, 1, 'Different details');
    $check($repeat['number'] === $invoice['number'] && $repeat['data']['bank'] === $bank,
        'Repeated generate reuses the saved invoice without replacing billing details');
    $check((int)$db->execute('SELECT status FROM transactions WHERE id=?', [$id])->fetchColumn(0) === 3,
        'Invoice generation does not confirm payment');
    $pdf = $service->pdf($invoice, false);
    $check(str_starts_with($pdf, '%PDF-'), 'Invoice renders a real PDF');
    file_put_contents($temp, $pdf);
    $process = proc_open(['pdftotext', '-layout', $temp, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to inspect generated invoice with pdftotext.');
    }
    $text = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($process) === 0, 'Generated PDF text is readable: ' . $errors);
    foreach ([$invoice['number'], '66.13 AUD', '50.25', '19.90', '-4.02', 'Varies', 'Test Bank', 'Amount due: 66.13', $invoice['data']['customer_code']] as $value) {
        $check(str_contains($text, $value), 'PDF contains required value: ' . $value);
    }
    $check(substr_count($text, "\f") === 1, 'Official invoice fits on one A4 page');
    if (($argv[1] ?? '') === '--preview' && isset($argv[2])) {
        file_put_contents($argv[2], $pdf);
    }
    $debugMail = new LegacyEmail();
    $debugMail->setTransport(new DebugTransport());
    $check(!$service->send($slug, 1, $debugMail), 'Debug transport does not claim email delivery');
    $check($service->find($id)['emailed_at'] === null, 'Debug transport does not set sent timestamp');
    $attachments = $debugMail->getAttachments();
    $check(isset($attachments[$invoice['number'] . '.pdf']) && $attachments[$invoice['number'] . '.pdf']['mimetype'] === 'application/pdf'
        && str_starts_with(base64_decode($attachments[$invoice['number'] . '.pdf']['data']), '%PDF-'), 'Email attaches generated PDF with correct name and MIME type');
    $failedTransport = new class extends AbstractTransport {
        public function send(Message $message): array
        {
            throw new RuntimeException('Intentional invoice transport failure');
        }
    };
    $failedMail = new LegacyEmail();
    $failedMail->setTransport($failedTransport);
    $reject(fn() => $service->send($slug, 1, $failedMail), RuntimeException::class, 'Mail delivery failure is surfaced');
    $check($service->find($id)['emailed_at'] === null, 'Failed delivery does not mark invoice as sent');
    $memoryTransport = new class extends AbstractTransport {
        public ?Message $captured = null;
        public function send(Message $message): array
        {
            $this->captured = $message;
            return ['headers' => '', 'message' => ''];
        }
    };
    $memoryMail = new LegacyEmail();
    $memoryMail->setTransport($memoryTransport);
    $check($service->send($slug, 1, $memoryMail), 'Memory-only delivery path completes');
    $check($service->find($id)['emailed_at'] !== null, 'Successful delivery records timestamp');
    $schoolId = (int)$transaction['user_id'];
    $notice = ['school_id' => $schoolId, 'kind' => 'email', 'subject' => 'Convention invoice ' . $invoice['number']];
    $check($service->forSchoolNotification($schoolId, $notice)['number'] === $invoice['number'],
        'Existing invoice email subjects resolve to owned sent invoices');
    $check($service->forSchoolNotification($schoolId + 1, $notice) === null, 'Foreign notification owner denied');
    $foreign = $notice;
    $foreign['school_id'] = $schoolId + 1;
    $check($service->forSchoolNotification($schoolId + 1, $foreign) === null, 'Forged subject cannot expose another school invoice');
    $db->execute("INSERT INTO school_notifications (school_id,event_key,kind,subject,sender,body,created)
        VALUES (?,?,'email',?,'Portal','Invoice sent',NOW())",
        [$schoolId, 'invoice-download-test-' . bin2hex(random_bytes(6)), $notice['subject']]);
    $noticeId = (string)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    $session->write(['user_id' => $schoolId, 'user_type' => 'School']);
    $schoolRequest = new ServerRequest([
        'session' => $session, 'environment' => ['REQUEST_METHOD' => 'GET'],
        'params' => ['controller' => 'Users', 'action' => 'notificationinvoice'],
    ]);
    $schoolPdf = (new \App\Controller\UsersController($schoolRequest))->notificationinvoice($noticeId);
    $check(str_starts_with((string)$schoolPdf->getBody(), '%PDF-')
        && str_starts_with($schoolPdf->getHeaderLine('Content-Disposition'), 'inline;'), 'School can view owned invoice inline');
    $schoolPdf = (new \App\Controller\UsersController($schoolRequest->withQueryParams(['download' => '1'])))->notificationinvoice($noticeId);
    $check(str_contains($schoolPdf->getHeaderLine('Content-Disposition'), 'attachment;')
        && str_contains($schoolPdf->getHeaderLine('Cache-Control'), 'no-store'), 'School can download private PDF attachment');
    $otherSchool = (int)$db->execute("SELECT id FROM users WHERE user_type='School' AND status=1 AND activation_status=1 AND id<>? LIMIT 1", [$schoolId])->fetchColumn(0);
    $session->write(['user_id' => $otherSchool, 'user_type' => 'School']);
    $reject(fn() => (new \App\Controller\UsersController($schoolRequest))->notificationinvoice($noticeId),
        \Cake\Http\Exception\NotFoundException::class, 'Foreign school PDF route denied');
    $session->write(['user_id' => $schoolId, 'user_type' => 'Teacher_Parent']);
    $reject(fn() => (new \App\Controller\UsersController($schoolRequest))->notificationinvoice($noticeId),
        \Cake\Http\Exception\ForbiddenException::class, 'Non-school PDF access denied');
    $session->write(['user_id' => $schoolId, 'user_type' => 'School']);
    $db->execute('UPDATE transaction_invoices SET emailed_at=NULL WHERE id=?', [$invoice['id']]);
    $check($service->forSchoolNotification($schoolId, $notice) === null, 'Unsent invoice cannot be exposed by subject');
    $db->execute('UPDATE transaction_invoices SET emailed_at=NOW() WHERE id=?', [$invoice['id']]);
    $check(array_keys($memoryTransport->captured->getTo()) === [$invoice['data']['email']], 'Invoice sent only to saved school address');
    $check(str_contains($memoryTransport->captured->getBodyHtml(), $invoice['number']), 'Invoice email includes the invoice number');
    $changed = $invoice['data'];
    $changed['email'] = 'changed-recipient@example.invalid';
    $db->execute('UPDATE transaction_invoices SET snapshot=? WHERE id=?', [json_encode($changed, JSON_THROW_ON_ERROR), $invoice['id']]);
    $reject(fn() => $service->send($slug, 1, $memoryMail), DomainException::class, 'Changed school billing recipient blocks accidental stale-address delivery');
    $db->execute('UPDATE transaction_invoices SET snapshot=? WHERE id=?', [$invoice['snapshot'], $invoice['id']]);
    $reject(fn() => $service->send($slug, 0, $memoryMail), DomainException::class, 'Invoice sending requires admin actor');
    $db->execute('UPDATE transactions SET status=1 WHERE id=?', [$id]);
    $paid = $service->pdf($invoice, true);
    file_put_contents($temp, $paid);
    $process = proc_open(['pdftotext', $temp, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $paidText = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($process) === 0 && str_contains($paidText, 'PAID - Amount due: 0.00 AUD'), 'Confirmed invoice shows paid and zero outstanding');
    $check($service->find($id)['number'] === $invoice['number'], 'Payment confirmation does not renumber the invoice');
    foreach (['invoice', 'invoicepdf'] as $action) {
        $reject(fn() => (new TransactionsController($request($action, 'GET', [], 0)))->{$action}($slug),
            \Cake\Http\Exception\ForbiddenException::class, 'Invoice routes reject non-admin access');
    }
    $controller = new TransactionsController($request('invoice'));
    $controller->invoice($slug);
    $check(str_contains($controller->getResponse()->getHeaderLine('Location'), '/viewdetails/')
        && str_contains($controller->getResponse()->getHeaderLine('Location'), 'invoice=open#generate-invoice'),
        'Legacy invoice GET opens the invoice accordion on Transaction Details');
    $controller = new TransactionsController($request('viewdetails'));
    $controller->viewdetails($slug);
    $view = new \App\View\AppView($controller->getRequest()->withAttribute('csrfToken', str_repeat('a', 64)));
    $view->disableAutoLayout();
    $view->set($controller->viewBuilder()->getVars());
    $view->set($controller->viewBuilder()->getVar('invoiceData'));
    $html = $view->element('Admin/Transactions/invoice');
    $check(str_contains($html, $invoice['number']) && str_contains($html, '_csrfToken') && str_contains($html, 'confirm_send'),
        'Invoice view renders stable number and confirmed CSRF-protected email action');
    $check(str_contains($html, '/invoice/' . $slug), 'Embedded email form posts to invoice action, not payment confirmation');
    $view->set($controller->viewBuilder()->getVars());
    $html = $view->render('Admin/Transactions/viewdetails');
    $check(str_contains($html, '<summary>Generate Invoice</summary>') && str_contains($html, $invoice['number']),
        'Confirmed transaction retains invoice accordion and saved download/email controls');
    $check(str_contains($controller->getResponse()->getHeaderLine('Cache-Control'), 'no-store'), 'Invoice administration is not cached');
    $download = (new TransactionsController($request('invoicepdf')))->invoicepdf($slug);
    $check(str_starts_with((string)$download->getBody(), '%PDF-') && str_contains($download->getHeaderLine('Content-Disposition'), $invoice['number']),
        'Admin download returns actual PDF with invoice filename');
    $check(str_contains($download->getHeaderLine('Cache-Control'), 'no-store'), 'PDF downloads are private and not cached');
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
        {
            return new \Cake\Http\Response();
        }
    };
    $reject(fn() => (new \App\Middleware\ScopedPublicationCsrfMiddleware())->process($request('invoice', 'POST'), $handler),
        \Cake\Http\Exception\InvalidCsrfTokenException::class, 'Forged invoice POST is rejected by middleware');
    $reject(fn() => (new TransactionsController($request('invoicepdf', 'POST')))->invoicepdf($slug),
        \Cake\Http\Exception\MethodNotAllowedException::class, 'PDF download is GET-only');
    $reject(fn() => (new TransactionsController($request('invoice', 'POST', ['operation' => 'invalid'])))->invoice($slug),
        \Cake\Http\Exception\BadRequestException::class, 'Unknown invoice operations rejected');
    $beforeCount = (int)$db->execute('SELECT COUNT(*) FROM transaction_invoices')->fetchColumn(0);
    (new TransactionsController($request('invoice', 'POST', ['operation' => 'generate', 'bank_details' => 'Fixture bank'])))->invoice($slug);
    $check((int)$db->execute('SELECT COUNT(*) FROM transaction_invoices')->fetchColumn(0) === $beforeCount,
        'Generate POST without explicit confirmation cannot issue invoices');
    $beforeSent = $service->find($id)['emailed_at'];
    (new TransactionsController($request('invoice', 'POST', ['operation' => 'send'])))->invoice($slug);
    $check($service->find($id)['emailed_at'] === $beforeSent, 'Email POST requires explicit send confirmation');
    $source['slug'] .= '-second';
    $source['final_amount_paid'] = '0.00';
    $source['status'] = 2;
    $db->execute('INSERT INTO transactions (`' . implode('`,`', array_keys($source)) . '`) VALUES (' .
        implode(',', array_fill(0, count($source), '?')) . ')', array_values($source));
    $secondId = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    $reject(fn() => $service->generate($source['slug'], 1, $bank), DomainException::class, 'Empty transaction cannot produce an invoice');
    $db->execute("INSERT INTO transactionstudents (transaction_id,paid_amount,final_paid_amount,amount_discount_applied,status,student_id) VALUES (?,0,0,0,2,(SELECT id FROM users WHERE user_type='Student' LIMIT 1))", [$secondId]);
    $newController = new TransactionsController($request('viewdetails'));
    $newController->viewdetails($source['slug']);
    $view = new \App\View\AppView($newController->getRequest()->withAttribute('csrfToken', str_repeat('b', 64)));
    $view->disableAutoLayout();
    $view->set($newController->viewBuilder()->getVar('invoiceData'));
    $html = $view->element('Admin/Transactions/invoice');
    $check(str_contains($html, 'bank_details') && str_contains($html, 'confirm_generate') && str_contains($html, '_csrfToken'),
        'New invoice form contains bank entry, explicit confirmation and CSRF');
    $check(str_contains($html, '/invoice/' . $source['slug']), 'Embedded generation form uses invoice POST action');
    $view->set($newController->viewBuilder()->getVars());
    $view->set('transactions', \Cake\ORM\TableRegistry::getTableLocator()->get('Transactions')->newEmptyEntity());
    $html = $view->render('Admin/Transactions/viewdetails');
    $check(strpos($html, '<summary>Payment Review</summary>') < strpos($html, '<summary>Generate Invoice</summary>')
        && str_contains($html, 'bank_details'), 'Generate Invoice accordion follows Payment Review with the full generator');
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $check($xpath->query('//form//form')->length === 0, 'Invoice and payment forms are not nested');
    $newController = new TransactionsController($request('invoice', 'POST', ['operation' => 'generate', 'bank_details' => $bank, 'confirm_generate' => '1']));
    $newController->invoice($source['slug']);
    $second = $service->find($secondId);
    $check($newController->getResponse()->getStatusCode() === 302 && $second !== null, 'Confirmed admin generation POST issues and redirects successfully');
    $check(str_contains($newController->getResponse()->getHeaderLine('Location'), '/viewdetails/')
        && str_contains($newController->getResponse()->getHeaderLine('Location'), 'invoice=open#generate-invoice'),
        'Generation returns to expanded invoice accordion rather than a separate page');
    $check($second['number'] !== $invoice['number'] && $second['data']['total'] === 0, 'Different transactions receive unique numbers including free registrations');
    $check((int)$db->execute('SELECT status FROM transactions WHERE id=?', [$secondId])->fetchColumn(0) === 2, 'Pending status is unchanged by controller generation');
    $check($second['emailed_at'] === null, 'Generating does not automatically email the school');
    (new \App\Service\TransactionCancellation($db))->cancel($source['slug'], 1, 'Remove unpaid invoice fixture');
    $check($service->find($secondId) === null, 'Unpaid cancellation also cascades invoice deletion');
    (new \App\Service\TransactionCancellation($db))->deleteConfirmed($slug, 1, 'Remove fixture invoice payment');
    $check($service->find($id) === null, 'Permanent transaction deletion also removes its private invoice snapshot');
    $reject(fn() => $service->send($slug, 1, $memoryMail), \Cake\Http\Exception\NotFoundException::class, 'Deleted invoice cannot be sent');
    $reject(fn() => (new TransactionsController($request('invoicepdf')))->invoicepdf($slug),
        \Cake\Http\Exception\NotFoundException::class, 'Deleted transaction invoice cannot be downloaded');
} finally {
    $db->rollback(true);
    if (is_file($temp)) {
        unlink($temp);
    }
}
echo "PASS: $checks transaction invoice checks. All fixtures rolled back; no external email sent.\n";
