<?php
declare(strict_types=1);

namespace App\Test\TestCase\Deployment;

use App\Deployment\Environment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvironmentTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/acp-environment-test-' . bin2hex(random_bytes(8));
        mkdir($this->storage . '/demo', 0700, true);
        foreach (['tmp', 'logs', 'sessions', 'uploads', 'cache'] as $name) {
            mkdir($this->storage . '/demo/' . $name, 0700);
        }
    }

    protected function tearDown(): void
    {
        foreach (['tmp', 'logs', 'sessions', 'uploads', 'cache'] as $name) {
            rmdir($this->storage . '/demo/' . $name);
        }
        rmdir($this->storage . '/demo');
        rmdir($this->storage);
    }

    private function settings(): array
    {
        return [
            'environment' => 'demo', 'publicUrl' => 'https://convention.accelerateministries.com.au/acp_demo',
            'storageRoot' => $this->storage . '/demo', 'securitySalt' => str_repeat('synthetic-test-only-', 3),
            'releaseId' => 'test-release-1',
            'database' => ['host' => 'localhost', 'database' => 'synthetic_demo', 'username' => 'synthetic_demo',
                'password' => 'synthetic-test-only'],
            'emailMode' => 'smtp_test', 'testRecipient' => 'test-inbox@example.invalid',
            'sender' => 'demo-sender@example.invalid',
            'smtp' => ['host' => 'smtp.example.invalid', 'port' => 587, 'username' => 'synthetic-test',
                'password' => 'synthetic-test-only'],
            'payments' => 'sandbox', 'paypalSandboxEmail' => 'merchant@example.invalid',
            'integrations' => [], 'commands' => [],
        ];
    }

    public function testDemoConfigurationSeparatesAllRuntimeSurfaces(): void
    {
        $settings = Environment::validate('demo', $this->settings(), dirname(__DIR__, 4));
        $base = ['App' => [], 'Datasources' => ['default' => ['url' => 'must-not-survive'], 'test' => []],
            'Cache' => ['default' => ['url' => 'must-not-survive']], 'Log' => ['error' => []]];
        $config = Environment::apply($base, $settings);
        $this->assertSame('/acp_demo', $config['App']['base']);
        $this->assertSame('https://convention.accelerateministries.com.au', $config['App']['fullBaseUrl']);
        $this->assertSame('ACP_DEMO_SESSION', $config['Session']['cookie']);
        $this->assertSame('/acp_demo', $config['Session']['cookiePath']);
        $this->assertSame('acp_demo_csrf', $config['PublicationCsrf']['cookieName']);
        $this->assertSame($settings['storageRoot'] . '/sessions', $config['Session']['ini']['session.save_path']);
        $this->assertNull($config['Datasources']['default']['url']);
        $this->assertArrayNotHasKey('test', $config['Datasources']);
        $this->assertNull($config['Cache']['default']['url']);
        $this->assertStringStartsWith('acp_demo_', $config['Cache']['default']['prefix']);
        $this->assertTrue($config['Payments']['enabled']);
        $this->assertSame('sandbox', $config['Payments']['mode']);
        $this->assertSame('merchant@example.invalid', $config['Payments']['sandboxEmail']);
        $this->assertSame([], $config['Integrations']['allowed']);
        $this->assertSame([], $config['Commands']['allowed']);
        $this->assertSame('App\\Mailer\\Transport\\DemoSmtpTransport', $config['EmailTransport']['default']['className']);
        $this->assertSame('test-inbox@example.invalid', $config['EmailTransport']['default']['testRecipient']);
        $this->assertSame('demo-sender@example.invalid', $config['EmailTransport']['default']['testSender']);
        $this->assertSame('smtp_test', $config['Email']['mode']);
    }

    public function testDemoCanRetainCaptureAndDisabledModes(): void
    {
        $settings = $this->settings();
        $settings['emailMode'] = 'capture';
        $settings['payments'] = 'disabled';
        unset($settings['testRecipient'], $settings['sender'], $settings['smtp'], $settings['paypalSandboxEmail']);
        $config = Environment::apply(
            ['Email' => [], 'Payments' => [], 'App' => [], 'Datasources' => ['default' => []],
                'Cache' => [], 'Log' => []],
            Environment::validate('demo', $settings, dirname(__DIR__, 4)),
        );

        $this->assertFalse($config['Payments']['enabled']);
        $this->assertSame('App\\Mailer\\Transport\\DemoTransport', $config['EmailTransport']['default']['className']);
    }

    public function testLocalConfigurationPreservesExistingCredentialsAndSessionSettings(): void
    {
        $config = ['Session' => ['defaults' => 'php'], 'Datasources' => ['default' => ['password' => 'synthetic']],
            'Security' => ['salt' => 'synthetic']];
        $this->assertSame($config + ['Environment' => ['name' => 'local']],
            Environment::apply($config, ['environment' => 'local']));
    }

    public function testDemoRejectsEveryMissingOrUnsafeSafetySetting(): void
    {
        $scenarios = [
            ['emailMode', 'smtp'], ['payments', 'live'], ['integrations', ['recaptcha']],
            ['testRecipient', 'not-an-email'], ['sender', 'not-an-email'],
            ['smtp', ['host' => '', 'port' => 0, 'username' => '', 'password' => '']],
            ['paypalSandboxEmail', 'not-an-email'],
            ['commands', ['migrations']], ['environment', 'live'],
            ['publicUrl', 'https://convention.accelerateministries.com.au/acp'],
            ['securitySalt', 'REPLACE'], ['storageRoot', dirname(__DIR__, 4)],
            ['database', ['host' => 'localhost', 'database' => 'live', 'username' => 'live', 'password' => 'synthetic']],
        ];
        foreach ($scenarios as [$key, $value]) {
            $settings = $this->settings();
            $settings[$key] = $value;
            try {
                Environment::validate('demo', $settings, dirname(__DIR__, 4));
                $this->fail('Unsafe Demo configuration was accepted: ' . $key);
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('synthetic-test-only', $exception->getMessage());
            }
            $settings = $this->settings();
            unset($settings[$key]);
            try {
                Environment::validate('demo', $settings, dirname(__DIR__, 4));
                $this->fail('Missing Demo configuration was accepted: ' . $key);
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }
}
