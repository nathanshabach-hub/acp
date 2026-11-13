<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Utility\LegacyEmail;
use Cake\Mailer\Transport\DebugTransport;

if (\App\Deployment\Environment::isHosted()) {
    throw new RuntimeException('Run this email rendering test locally only.');
}

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
foreach (['Invoice request received', 'Invoice request for accounts'] as $subject) {
    $email = new LegacyEmail();
    $email->setTransport(new DebugTransport());
    $email->addAttachments(['fixture.txt' => ['data' => 'Preserved attachment', 'mimetype' => 'text/plain']]);
    $email->viewBuilder()->setTemplate('default')->setLayout('admintemplate');
    $result = $email->setEmailFormat('html')
        ->setTo('recipient@example.invalid')
        ->setCc('accounts@example.invalid')
        ->setFrom(['sender@example.invalid' => 'Invoice Test'])
        ->setSubject($subject)
        ->setViewVars(['content_for_layout' => '<tr><td>Invoice rendering fixture</td></tr>'])
        ->send();
    $check($email->getEmailFormat() === 'html', 'Legacy HTML format must reach the CakePHP mailer.');
    $check(str_contains($result['message'], 'Invoice rendering fixture'), 'Invoice body must be rendered.');
    $body = quoted_printable_decode($result['message']);
    $check(str_contains($body, 'cid:accelerate-header-logo'), 'HTML admin header references embedded logo.');
    $check(str_contains($body, 'Content-ID: <accelerate-header-logo>'), 'Email includes the matching logo MIME part.');
    $check(str_contains($body, 'image/png'), 'Embedded logo uses PNG MIME type.');
    $check(!str_contains($body, HTTP_PATH . '/img/front/main-logo.png'), 'Email logo does not depend on localhost or public image access.');
    $attachments = $email->getAttachments();
    $check(isset($attachments['fixture.txt'], $attachments['accelerate-logo.png']), 'Embedding preserves other email attachments.');
    $check(str_contains(preg_replace('/\s+/', '', $result['message']), base64_encode(file_get_contents(WWW_ROOT . 'img/front/main-logo.png'))),
        'Email contains actual logo bytes, not just a content ID.');
    $check(str_contains($result['headers'], $subject), 'Invoice subject must be preserved.');
    $check(str_contains($result['headers'], 'accounts@example.invalid'), 'Invoice CC must be preserved.');
}
$email = new LegacyEmail();
$email->setTransport(new DebugTransport());
$builder = $email->viewBuilder();
$check($builder->setClassName(\App\View\AppView::class) === $builder, 'View class selection preserves the legacy builder chain.');
$builder->setTemplate('default')->setLayout('admintemplate')
    ->setVars(['content_for_layout' => '<tr><td>Judge activation rendering fixture</td></tr>']);
$result = $email->setEmailFormat('html')->setTo('judge@example.invalid')
    ->setCc('accounts@example.invalid')->setFrom('sender@example.invalid')
    ->setSubject('Judge activation rendering regression')->send();
$check(str_contains(quoted_printable_decode($result['message']), 'Judge activation rendering fixture'),
    'Judge registration activation email renders through the exact view-builder chain.');
$check(str_contains($result['headers'], 'judge@example.invalid') && str_contains($result['headers'], 'accounts@example.invalid'),
    'Judge activation recipients are preserved with debug-only delivery.');
$email = new LegacyEmail();
$email->setTransport(new DebugTransport());
$result = $email->setEmailFormat('text')
    ->setTo('recipient@example.invalid')
    ->setFrom('sender@example.invalid')
    ->setSubject('Plain text regression')
    ->send('Plain text fixture');
$check($email->getEmailFormat() === 'text', 'Plain-text email format must remain supported.');
$check(str_contains($result['message'], 'Plain text fixture'), 'Plain-text content must be preserved.');
$check($email->getAttachments() === [], 'Plain-text email does not acquire a logo attachment.');
if (($argv[1] ?? '') === '--invoice') {
    $slug = $argv[2] ?? '';
    $transaction = \Cake\Datasource\ConnectionManager::get('default')->execute(
        'SELECT id FROM transactions WHERE slug = ?', [$slug]
    )->fetch('assoc');
    $check((bool)$transaction, 'Invoice regression requires an existing transaction.');
    \Cake\Mailer\Mailer::drop('default');
    \Cake\Mailer\Mailer::setConfig('default', ['transport' => 'invoiceTest']);
    \Cake\Mailer\TransportFactory::setConfig('invoiceTest', ['className' => DebugTransport::class]);
    $session = new \Cake\Http\Session(['defaults' => 'php', 'ini' => ['session.use_cookies' => 0]]);
    $request = new \Cake\Http\ServerRequest(['session' => $session]);
    $controller = new class($request) extends \App\Controller\TransactionsController {
        public function redirect($url, int $status = 302): ?\Cake\Http\Response
        {
            $response = new \Cake\Http\Response(['status' => $status]);
            $this->setResponse($response);
            return $response;
        }
    };
    $controller->invoiceprocess($slug);
    $check($controller->getResponse()->getStatusCode() === 302, 'Invoice processing completes and redirects.');
    $messages = $session->read('Flash.flash');
    $check(str_contains($messages[0]['message'] ?? '', 'successfully received your invoice request'),
        'Invoice processing renders both emails and reports success.');
}
echo "PASS: $checks legacy email checks. Debug transport only; no external email sent.\n";
