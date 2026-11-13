<?php
declare(strict_types=1);

namespace App\Test\TestCase\Mailer\Transport;

use App\Mailer\Transport\DemoSmtpTransport;
use Cake\Mailer\Message;
use PHPUnit\Framework\TestCase;

final class DemoSmtpTransportTest extends TestCase
{
    public function testEveryRecipientIsRedirectedToTheApprovedTestInbox(): void
    {
        $transport = new DemoSmtpTransport([
            'testRecipient' => 'tester@example.invalid',
            'testSender' => 'demo@example.invalid',
        ]);
        $message = (new Message())
            ->setTo('student@example.invalid')
            ->setCc('office@example.invalid')
            ->setBcc('hidden@example.invalid')
            ->setFrom('live-sender@example.invalid')
            ->setReplyTo('student@example.invalid')
            ->setReturnPath('live-sender@example.invalid');

        $transport->applyRecipientPolicy($message);

        $this->assertSame(['tester@example.invalid' => 'tester@example.invalid'], $message->getTo());
        $this->assertSame([], $message->getCc());
        $this->assertSame([], $message->getBcc());
        $this->assertSame(['demo@example.invalid' => 'demo@example.invalid'], $message->getFrom());
        $this->assertSame(['tester@example.invalid' => 'tester@example.invalid'], $message->getReplyTo());
        $this->assertSame(['tester@example.invalid' => 'tester@example.invalid'], $message->getReturnPath());
    }
}
