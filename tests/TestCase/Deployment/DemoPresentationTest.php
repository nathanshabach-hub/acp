<?php
declare(strict_types=1);

namespace App\Test\TestCase\Deployment;

use App\Mailer\Transport\DemoTransport;
use App\Utility\LegacyEmail;
use Cake\Core\Configure;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Mailer;
use Cake\Mailer\Message;
use Cake\TestSuite\TestCase;
use RuntimeException;

final class DemoPresentationTest extends TestCase
{
    public function testDemoCapturesAllRecipientsWithoutTransportDelivery(): void
    {
        Configure::write('Environment.name', 'demo');
        $forbidden = new class extends AbstractTransport {
            public function send(Message $message): array
            {
                throw new RuntimeException('Network-capable transport must never be used.');
            }
        };
        $email = new LegacyEmail();
        $email->setTransport($forbidden);
        $result = $email->setFrom('sender@example.invalid')->setTo('recipient@example.invalid')
            ->setCc('copy@example.invalid')->setBcc('blind@example.invalid')
            ->setSubject('Synthetic demo test')->send('Synthetic message');
        $this->assertStringContainsString('Synthetic message', $result['message']);
        $mailer = (new Mailer())->setTransport(new DemoTransport())->setFrom('sender@example.invalid')
            ->setTo('recipient@example.invalid');
        $this->assertStringContainsString('Synthetic message', $mailer->deliver('Synthetic message')['message']);
    }

    public function testDemoPaymentTemplateCannotRenderBrowserSubmittedForm(): void
    {
        Configure::write('Environment.name', 'demo');
        Configure::write('Payments.enabled', false);
        ob_start();
        include ROOT . '/templates/Transactions/paymentprocess.php';
        $html = (string)ob_get_clean();
        $this->assertStringContainsString('Payments are disabled', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('submit()', $html);
        $this->assertStringNotContainsString('paypal', $html);
    }

    public function testDemoBannerIsVisibleOnlyInDemo(): void
    {
        foreach (['local', 'demo', 'live'] as $environment) {
            Configure::write('Environment.name', $environment);
            ob_start();
            include ROOT . '/templates/element/environment_banner.php';
            $html = (string)ob_get_clean();
            $this->assertSame($environment === 'demo', str_contains($html, 'DEMO - TEST DATA'));
        }
    }

    public function testAdministratorLoginDoesNotRenderDemoBanner(): void
    {
        Configure::write('Environment.name', 'demo');
        $view = new \Cake\View\View(new \Cake\Http\ServerRequest());
        $view->set('title', 'Synthetic Demo login');
        $html = $view->renderLayout('Synthetic login form', 'admin_login');
        $this->assertStringNotContainsString('DEMO - TEST DATA', $html);
        $this->assertStringContainsString('Synthetic login form', $html);
    }
}
