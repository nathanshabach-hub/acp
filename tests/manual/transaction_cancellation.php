<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';
\Cake\Routing\Router::reload();
(require dirname(__DIR__, 2) . '/config/routes.php')(\Cake\Routing\Router::createRouteBuilder('/'));

use App\Service\TransactionCancellation;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Http\Session;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run cancellation fixtures locally only.');
}
$db = ConnectionManager::get('default');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$reject = static function (callable $call, string $message, string $type = DomainException::class) use ($check): void {
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
$db->enableSavePoints();
$db->begin();
try {
    $source = $db->execute('SELECT * FROM transactions ORDER BY id DESC LIMIT 1')->fetch('assoc');
    if (!$source) {
        throw new RuntimeException('An existing transaction is required as a fixture source.');
    }
    unset($source['id']);
    $source['slug'] = 'cancellation-test-' . bin2hex(random_bytes(12));
    $source['status'] = 3;
    $source['transaction_id_received'] = null;
    $source['transaction_data'] = 'Original invoice notes';
    $db->execute('INSERT INTO transactions (`' . implode('`,`', array_keys($source)) . '`) VALUES (' .
        implode(',', array_fill(0, count($source), '?')) . ')', array_values($source));
    $id = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    foreach (['transactionstudents', 'transactionteachers'] as $table) {
        $db->execute("INSERT INTO $table (transaction_id,conventionregistration_id,status) VALUES (?,?,3)", [$id, $source['conventionregistration_id']]);
    }
    $service = new TransactionCancellation($db);
    $reject(fn() => $service->deleteConfirmed($source['slug'], 1, 'Wrong action'), 'Confirmed deletion rejects unpaid requests');
    $reject(fn() => $service->cancel($source['slug'], 1, ''), 'Reason is required');
    $reject(fn() => $service->cancel($source['slug'], 1, str_repeat('x', 501)), 'Reason length enforced');
    foreach ([1] as $status) {
        $db->execute('UPDATE transactions SET status=? WHERE id=?', [$status, $id]);
        $reject(fn() => $service->cancel($source['slug'], 1, 'Accidental request'), 'Paid/cancelled parent blocked');
    }
    $db->execute('UPDATE transactions SET status=3,transaction_id_received=? WHERE id=?', ['PAYMENT-REF', $id]);
    $reject(fn() => $service->cancel($source['slug'], 1, 'Accidental request'), 'Payment reference blocks cancellation');
    $db->execute('UPDATE transactions SET transaction_id_received=NULL WHERE id=?', [$id]);
    foreach (['transactionstudents', 'transactionteachers'] as $table) {
        $db->execute("UPDATE $table SET status=1 WHERE transaction_id=?", [$id]);
        $reject(fn() => $service->cancel($source['slug'], 1, 'Accidental request'), 'Confirmed child blocks cancellation');
        $check((int)$db->execute('SELECT status FROM transactions WHERE id=?', [$id])->fetchColumn(0) === 3, 'Rejected cancellation preserves parent');
        $db->execute("UPDATE $table SET status=3 WHERE transaction_id=?", [$id]);
    }
    $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
    $request = new ServerRequest([
        'url' => '/admin/transactions/cancelrequest/' . $source['slug'], 'session' => $session,
        'params' => ['prefix' => 'Admin', 'controller' => 'Transactions', 'action' => 'cancelrequest'],
    ]);
    $controller = new \App\Controller\Admin\TransactionsController($request);
    $reject(fn() => $controller->cancelrequest($source['slug']), 'Non-admin cannot cancel', \Cake\Http\Exception\ForbiddenException::class);
    $session->write('admin_id', 1);
    $controller = new \App\Controller\Admin\TransactionsController($request);
    $controller->cancelrequest($source['slug']);
    $check((int)$db->execute('SELECT status FROM transactions WHERE id=?', [$id])->fetchColumn(0) === 3, 'GET preview does not cancel');
    $summary = static function () use ($source): array {
        $session = new Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
        $session->write(['user_id' => (int)$source['user_id'], 'user_type' => 'School',
            'sess_selected_convention_registration_id' => (int)$source['conventionregistration_id']]);
        $controller = new \App\Controller\TransactionsController(new ServerRequest([
            'session' => $session, 'params' => ['controller' => 'Transactions', 'action' => 'paymentsummary'],
        ]));
        $controller->paymentsummary();
        return $controller->viewBuilder()->getVars();
    };
    $before = $summary();
    $check(isset($before['alreadyPaidStudents'], $before['alreadyPaidTeachers']), 'School payment summary rendered before cancellation');
    $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
        public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
            return new \Cake\Http\Response();
        }
    };
    $reject(fn() => (new \App\Middleware\ScopedPublicationCsrfMiddleware())->process($request->withMethod('POST'), $handler),
        'Cancellation POST requires CSRF', \Cake\Http\Exception\InvalidCsrfTokenException::class);
    $session->write('admin_id', 1);
    $post = new ServerRequest([
        'url' => '/admin/transactions/cancelrequest/' . $source['slug'], 'session' => $session,
        'params' => ['prefix' => 'Admin', 'controller' => 'Transactions', 'action' => 'cancelrequest'],
        'environment' => ['REQUEST_METHOD' => 'POST'],
        'post' => ['reason' => 'Accidentally submitted', 'confirm_cancel' => '1'],
    ]);
    $controller = new class($post) extends \App\Controller\Admin\TransactionsController {
        public function redirect($url, int $status = 302): ?\Cake\Http\Response
        {
            $response = new \Cake\Http\Response(['status' => $status]);
            $this->setResponse($response);
            return $response;
        }
    };
    $controller->cancelrequest($source['slug']);
    $check($controller->getResponse()->getStatusCode() === 302, 'Confirmed admin POST cancels request and redirects: ' . json_encode($session->read('Flash.flash')));
    $row = $db->execute('SELECT * FROM transactions WHERE id=?', [$id])->fetch('assoc');
    $check(!$row, 'Unpaid request is permanently deleted');
    foreach (['transactionstudents', 'transactionteachers'] as $table) {
        $check((int)$db->execute("SELECT COUNT(*) FROM $table WHERE transaction_id=?", [$id])->fetchColumn(0) === 0, 'Child billing details permanently deleted');
        $check((int)$db->execute("SELECT COUNT(*) FROM $table WHERE transaction_id=? AND status != 4", [$id])->fetchColumn(0) === 0, 'Cancelled child no longer reserves payment');
    }
    $check(!$service->confirm($id, 'LATE-PAYMENT', 'Late callback'), 'Late confirmation cannot revive cancelled request');
    $after = $summary();
    $check($after['alreadyPaidStudents'] === $before['alreadyPaidStudents'] - 1
        && $after['pendingPaymentStudents'] === $before['pendingPaymentStudents'] + 1,
        'Cancelled student is available again in real school summary');
    $check($after['alreadyPaidTeachers'] === $before['alreadyPaidTeachers'] - 1
        && $after['pendingPaymentTeachers'] === $before['pendingPaymentTeachers'] + 1,
        'Cancelled supervisor is available again in real school summary');
    $reject(fn() => $service->cancel($source['slug'], 1, 'Again'), 'Repeat cancellation rejected');
    $source['status'] = 2;
    $source['slug'] .= '-confirm';
    $db->execute('INSERT INTO transactions (`' . implode('`,`', array_keys($source)) . '`) VALUES (' .
        implode(',', array_fill(0, count($source), '?')) . ')', array_values($source));
    $id = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    foreach (['transactionstudents', 'transactionteachers'] as $table) {
        $db->execute("INSERT INTO $table (transaction_id,conventionregistration_id,status) VALUES (?,?,2)", [$id, $source['conventionregistration_id']]);
    }
    $check($service->confirm($id, 'CONFIRMED', 'Payment received'), 'Pending payment can still be confirmed');
    $reject(fn() => $service->cancel($source['slug'], 1, 'After confirmation'), 'Confirmation wins over subsequent cancellation');
    foreach (['transactionstudents', 'transactionteachers'] as $table) {
        $check((int)$db->execute("SELECT status FROM $table WHERE transaction_id=?", [$id])->fetchColumn(0) === 1, 'Payment confirmation updates child status');
    }
    $reject(fn() => $service->deleteConfirmed($source['slug'], 0, 'No admin'), 'Confirmed deletion requires an admin actor');
    $reject(fn() => $service->deleteConfirmed($source['slug'], 1, ''), 'Confirmed deletion requires a reason');
    $reject(fn() => $service->deleteConfirmed($source['slug'], 1, str_repeat('x', 501)), 'Confirmed deletion enforces reason length');
    $get = new ServerRequest([
        'session' => $session,
        'params' => ['prefix' => 'Admin', 'controller' => 'Transactions', 'action' => 'deleteconfirmed', 'plugin' => null, '_ext' => null],
        'environment' => ['REQUEST_METHOD' => 'GET'],
    ]);
    $session->delete('admin_id');
    $reject(fn() => (new \App\Controller\Admin\TransactionsController($get))->deleteconfirmed($source['slug']),
        'School cannot delete confirmed payments', \Cake\Http\Exception\ForbiddenException::class);
    $session->write('admin_id', 1);
    $preview = new \App\Controller\Admin\TransactionsController($get);
    $preview->deleteconfirmed($source['slug']);
    $check((int)$db->execute('SELECT status FROM transactions WHERE id=?', [$id])->fetchColumn(0) === 1,
        'GET confirmed deletion preview preserves the payment');
    \Cake\Routing\Router::setRequest($get);
    $view = new \App\View\AppView($get->withAttribute('csrfToken', str_repeat('a', 64)));
    $view->disableAutoLayout();
    $view->set($preview->viewBuilder()->getVars());
    $html = $view->render('Admin/Transactions/cancelrequest');
    $check(str_contains($html, 'confirm_delete') && str_contains($html, '_csrfToken')
        && str_contains($html, 'No refund will be issued') && str_contains($html, 'can be billed again'),
        'Confirmed deletion page explicitly warns about refunds, rebilling and permanent removal');
    $deletePost = new ServerRequest([
        'session' => $session,
        'params' => ['prefix' => 'Admin', 'controller' => 'Transactions', 'action' => 'deleteconfirmed'],
        'environment' => ['REQUEST_METHOD' => 'POST'],
        'post' => ['reason' => 'Incorrectly confirmed fixture', 'confirm_cancel' => '1'],
    ]);
    $reject(fn() => (new \App\Middleware\ScopedPublicationCsrfMiddleware())->process($deletePost, $handler),
        'Confirmed deletion POST requires CSRF', \Cake\Http\Exception\InvalidCsrfTokenException::class);
    (new \App\Controller\Admin\TransactionsController($deletePost))->deleteconfirmed($source['slug']);
    $check((bool)$db->execute('SELECT id FROM transactions WHERE id=?', [$id])->fetch('assoc'),
        'Unpaid cancellation checkbox cannot authorize confirmed deletion');
    $beforeConfirmedDelete = $summary();
    $session->write('admin_id', 1);
    $deletePost = $deletePost->withParsedBody(['reason' => 'Incorrectly confirmed fixture', 'confirm_delete' => '1']);
    $deleteController = new class($deletePost) extends \App\Controller\Admin\TransactionsController {
        public function redirect($url, int $status = 302): ?\Cake\Http\Response
        {
            $response = new \Cake\Http\Response(['status' => $status]);
            $this->setResponse($response);
            return $response;
        }
    };
    $deleteController->deleteconfirmed($source['slug']);
    $check($deleteController->getResponse()->getStatusCode() === 302, 'Confirmed deletion POST completes and redirects');
    $check(!$db->execute('SELECT id FROM transactions WHERE id=?', [$id])->fetch('assoc'), 'Confirmed parent with payment reference is permanently deleted');
    foreach (['transactionstudents', 'transactionteachers'] as $table) {
        $check((int)$db->execute("SELECT COUNT(*) FROM $table WHERE transaction_id=?", [$id])->fetchColumn(0) === 0,
            'Confirmed child billing line items are deleted');
    }
    $check(!$service->confirm($id, 'LATE-CONFIRMED', 'Late callback'), 'Late confirmation cannot revive deleted confirmed payment');
    $reject(fn() => $service->deleteConfirmed($source['slug'], 1, 'Repeat'), 'Repeat confirmed deletion is rejected');
    $afterConfirmedDelete = $summary();
    $check($afterConfirmedDelete['alreadyPaidStudents'] === $beforeConfirmedDelete['alreadyPaidStudents'] - 1
        && $afterConfirmedDelete['pendingPaymentStudents'] === $beforeConfirmedDelete['pendingPaymentStudents'] + 1,
        'Confirmed student billing is released in school summary');
    $check($afterConfirmedDelete['alreadyPaidTeachers'] === $beforeConfirmedDelete['alreadyPaidTeachers'] - 1
        && $afterConfirmedDelete['pendingPaymentTeachers'] === $beforeConfirmedDelete['pendingPaymentTeachers'] + 1,
        'Confirmed supervisor billing is released in school summary');
    $source['slug'] .= '-legacy';
    $db->execute('INSERT INTO transactions (`' . implode('`,`', array_keys($source)) . '`) VALUES (' .
        implode(',', array_fill(0, count($source), '?')) . ')', array_values($source));
    $id = (int)$db->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
    $db->execute('UPDATE transactions SET status=4,transaction_id_received=NULL WHERE id=?', [$id]);
    foreach (['transactionstudents', 'transactionteachers'] as $table) {
        $db->execute("UPDATE $table SET status=4 WHERE transaction_id=?", [$id]);
    }
    $service->cancel($source['slug'], 1, 'Remove legacy cancellation history');
    $check(!$db->execute('SELECT id FROM transactions WHERE id=?', [$id])->fetch('assoc'), 'Legacy cancelled history can be permanently removed');
    echo "PASS: $checks transaction cancellation checks. All fixtures rolled back.\n";
} finally {
    $db->rollback(true);
}
