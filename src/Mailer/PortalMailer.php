<?php
declare(strict_types=1);

namespace App\Mailer;

use App\Service\SchoolNotifications;
use Cake\Datasource\ConnectionManager;
use Cake\Log\Log;
use Cake\Mailer\Mailer;
use Cake\Mailer\Transport\DebugTransport;

class PortalMailer extends Mailer
{
    public function deliver(string $content = ''): array
    {
        $result = parent::deliver($content);
        if (!$this->getTransport() instanceof DebugTransport) {
            try {
                (new SchoolNotifications(ConnectionManager::get('default')))->emailSent($this->getMessage());
            } catch (\RuntimeException $error) {
                // Delivery already succeeded: retrying the email could send duplicates.
                Log::error('Portal email sent but notification recording failed: ' . $error->getMessage());
            }
        }
        return $result;
    }
}
