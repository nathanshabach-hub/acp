<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use Cake\Mailer\Mailer;
use Cake\TestSuite\TestCase;

final class PasswordResetEmailTest extends TestCase
{
    public function testBrandedResetPreservesSeparateFragmentLinksAndEscapesNames(): void
    {
        $links = [
            ['label' => '<script>alert(1)</script> - Account #1', 'url' => 'https://example.invalid/acp_demo/users/resetpassword#' . str_repeat('a', 64)],
            ['label' => 'Second account - Account #2', 'url' => 'https://example.invalid/acp_demo/users/resetpassword#' . str_repeat('b', 64)],
        ];
        $mailer = $this->renderEmail(['recipientName' => '<b>School</b>', 'links' => $links]);
        $html = $mailer->getMessage()->getBodyHtml();
        $text = $mailer->getMessage()->getBodyText();

        $this->assertStringContainsString('https://example.invalid/acp_demo/img/front/main-logo.png', $html);
        $this->assertStringContainsString('background-color:#1c2452', $html);
        $this->assertStringContainsString('Dear &lt;b&gt;School&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        foreach ($links as $link) {
            $this->assertStringContainsString('href="' . $link['url'] . '"', $html);
            $this->assertStringContainsString($link['label'] . "\n" . $link['url'], $text);
        }
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('30 minutes', $body);
            $this->assertStringContainsString('can be used once', $body);
            $this->assertStringContainsString('Events Team', $body);
            $this->assertStringContainsString('events@example.invalid', $body);
        }
    }

    public function testNotificationContainsNoResetLinksEvenIfSupplied(): void
    {
        $mailer = $this->renderEmail([
            'notification' => true,
            'accountType' => 'Teacher_Parent',
            'accountEmail' => 'account@example.invalid',
            'accountIds' => [1, 2],
            'links' => [['label' => 'Never display', 'url' => 'https://example.invalid/users/resetpassword#secret-token']],
        ]);
        foreach ([$mailer->getMessage()->getBodyHtml(), $mailer->getMessage()->getBodyText()] as $body) {
            $this->assertStringContainsString('Account IDs: 1, 2', $body);
            $this->assertStringContainsString('account@example.invalid', $body);
            $this->assertStringContainsString('No administrator action is required.', $body);
            $this->assertStringNotContainsString('resetpassword', $body);
            $this->assertStringNotContainsString('secret-token', $body);
            $this->assertStringNotContainsString('Never display', $body);
        }
    }

    public function testLocalWarningAppearsInBothFormats(): void
    {
        $mailer = $this->renderEmail(['localTest' => true, 'recipientName' => 'Test account', 'links' => []]);
        $this->assertStringContainsString('[LOCAL TEST]', $mailer->getMessage()->getBodyHtml());
        $this->assertStringContainsString('[LOCAL TEST]', $mailer->getMessage()->getBodyText());
    }

    private function renderEmail(array $vars): Mailer
    {
        $mailer = new Mailer();
        $mailer->setEmailFormat('both')->setViewVars($vars + [
            'notification' => false,
            'localTest' => false,
            'baseUrl' => 'https://example.invalid/acp_demo',
            'contactEmail' => 'events@example.invalid',
            'subject' => 'Convention portal password reset',
        ]);
        $mailer->viewBuilder()->setTemplate('password_reset')->setLayout('password_reset');
        $mailer->render();

        return $mailer;
    }
}
