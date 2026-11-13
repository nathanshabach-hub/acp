<?php
declare(strict_types=1);

namespace App\Mailer\Transport;

use Cake\Mailer\Message;
use Cake\Mailer\Transport\SmtpTransport;
use RuntimeException;

final class DemoSmtpTransport extends SmtpTransport
{
    public function send(Message $message): array
    {
        $this->applyRecipientPolicy($message);

        return parent::send($message);
    }

    public function applyRecipientPolicy(Message $message): void
    {
        $recipient = $this->getConfig('testRecipient');
        if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid Demo test-mail recipient is required.');
        }
        $sender = $this->getConfig('testSender');
        if (!is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('A valid Demo test-mail sender is required.');
        }

        $message->setTo($recipient)
            ->setCc([])
            ->setBcc([])
            ->setFrom($sender)
            ->setReplyTo($recipient)
            ->setReturnPath($recipient);
    }
}
