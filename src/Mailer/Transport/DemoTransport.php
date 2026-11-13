<?php
declare(strict_types=1);

namespace App\Mailer\Transport;

use Cake\Log\Log;
use Cake\Mailer\Message;
use Cake\Mailer\Transport\DebugTransport;

final class DemoTransport extends DebugTransport
{
    public function send(Message $message): array
    {
        Log::info('Demo email captured in memory; external delivery is disabled.');

        return parent::send($message);
    }
}
