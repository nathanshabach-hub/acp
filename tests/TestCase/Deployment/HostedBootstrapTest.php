<?php
declare(strict_types=1);

namespace App\Test\TestCase\Deployment;

use PHPUnit\Framework\TestCase;

final class HostedBootstrapTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/acp-hosted-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        foreach (['demo', 'live'] as $environment) {
            mkdir($this->directory . '/' . $environment, 0700);
            foreach (['tmp', 'logs', 'sessions', 'uploads', 'cache'] as $child) {
                mkdir($this->directory . '/' . $environment . '/' . $child, 0700);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (['demo', 'live'] as $environment) {
            foreach (['tmp', 'logs', 'sessions', 'uploads', 'cache'] as $child) {
                rmdir($this->directory . '/' . $environment . '/' . $child);
            }
            rmdir($this->directory . '/' . $environment);
        }
        if (file_exists($this->directory . '/settings.php')) {
            unlink($this->directory . '/settings.php');
        }
        rmdir($this->directory);
    }

    private function settings(string $environment): array
    {
        return [
            'environment' => $environment,
            'publicUrl' => 'https://convention.accelerateministries.com.au/' . ($environment === 'demo' ? 'acp_demo' : 'acp'),
            'releaseId' => 'synthetic-test-release',
            'storageRoot' => $this->directory . '/' . $environment,
            'securitySalt' => str_repeat('synthetic-not-a-real-secret-', 2),
            'database' => ['host' => 'localhost', 'database' => 'synthetic_' . $environment,
                'username' => 'synthetic_' . $environment, 'password' => 'synthetic-only'],
            'emailMode' => $environment === 'demo' ? 'capture' : 'smtp', 'payments' => 'disabled',
            'smtp' => ['host' => 'smtp.example.invalid', 'username' => 'synthetic-only', 'password' => 'synthetic-only'],
            'sender' => 'synthetic@example.test',
            'integrations' => [], 'commands' => [],
        ];
    }

    private function boot(string $environment, ?array $settings, string $code = ''): array
    {
        $file = $this->directory . '/settings.php';
        if ($settings !== null) {
            file_put_contents($file, '<?php return ' . var_export($settings, true) . ';');
            chmod($file, 0600);
        }
        $script = 'require ' . var_export(ROOT . '/config/bootstrap.php', true) . ';' . $code;
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $script], [
            1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, ROOT, array_replace(getenv(), [
            'ACP_ENV' => $environment, 'ACP_CONFIG_FILE' => $file,
            'DATABASE_URL' => 'mysql://invalid:invalid@invalid.invalid/live',
            'EMAIL_TRANSPORT_DEFAULT_URL' => 'smtp://invalid.invalid',
        ]));
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }

    public function testBothHostedBootstrapsUseOnlyTheirProtectedConfigurationWithoutConnecting(): void
    {
        foreach (['demo', 'live'] as $environment) {
            [$status, $output, $error] = $this->boot($environment, $this->settings($environment), <<<'PHP'
$routes = require CONFIG . 'routes.php';
Cake\Routing\Router::reload();
$routes(Cake\Routing\Router::createRouteBuilder('/'));
$base = Cake\Core\Configure::read('App.base');
$request = (new Cake\Http\ServerRequest(['url' => $base . '/reports']))
    ->withAttribute('base', $base)->withAttribute('webroot', $base . '/')
    ->withAttribute('params', ['controller' => 'Reports', 'action' => 'index']);
Cake\Routing\Router::setRequest($request);
$view = new Cake\View\View($request);
echo json_encode([
    'environment' => Cake\Core\Configure::read('Environment.name'),
    'database' => Cake\Datasource\ConnectionManager::getConfig('default')['database'],
    'url' => Cake\Datasource\ConnectionManager::getConfig('default')['url'],
    'cookie' => Cake\Core\Configure::read('Session.cookie'),
    'csrf' => Cake\Core\Configure::read('PublicationCsrf.cookieName'),
    'tmp' => TMP, 'log' => LOGS, 'urlConstant' => HTTP_PATH,
    'localCredentialsLoaded' => defined('DB_PASSWORD'),
    'connected' => Cake\Datasource\ConnectionManager::get('default')->getDriver()->isConnected(),
    'transport' => Cake\Mailer\TransportFactory::getConfig('default')['className'],
    'reportUrl' => Cake\Routing\Router::url(['controller' => 'Reports', 'action' => 'student', 'studentId' => 123]),
    'assetHtml' => $view->Html->css('front/reports.css'),
]);
PHP);
            $this->assertSame(0, $status, $error);
            $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($environment, $data['environment']);
            $this->assertSame('synthetic_' . $environment, $data['database']);
            $this->assertNull($data['url']);
            $this->assertSame('ACP_' . strtoupper($environment) . '_SESSION', $data['cookie']);
            $this->assertSame('acp_' . $environment . '_csrf', $data['csrf']);
            $this->assertSame($this->directory . '/' . $environment . '/tmp/', $data['tmp']);
            $this->assertSame($this->directory . '/' . $environment . '/logs/', $data['log']);
            $this->assertFalse($data['localCredentialsLoaded']);
            $this->assertFalse($data['connected']);
            $this->assertSame($this->settings($environment)['publicUrl'], $data['urlConstant']);
            $base = $environment === 'demo' ? '/acp_demo' : '/acp';
            $this->assertSame($base . '/reports/students/123', $data['reportUrl']);
            $this->assertStringContainsString($base . '/css/front/reports.css', $data['assetHtml']);
        }
    }

    public function testMissingAndMismatchedHostedConfigurationCannotBoot(): void
    {
        [$status] = $this->boot('demo', null);
        $this->assertNotSame(0, $status);
        [$status] = $this->boot('live', $this->settings('demo'));
        $this->assertNotSame(0, $status);
        [$status] = $this->boot('unknown', $this->settings('demo'));
        $this->assertNotSame(0, $status);
    }

    public function testWorldReadableProtectedConfigurationCannotBoot(): void
    {
        $file = $this->directory . '/settings.php';
        file_put_contents($file, '<?php return [];');
        chmod($file, 0644);
        [$status] = $this->boot('demo', null);
        $this->assertNotSame(0, $status);
    }

    public function testMalformedProtectedConfigurationDoesNotExposeItsContents(): void
    {
        $file = $this->directory . '/settings.php';
        file_put_contents($file, '<?php return [ "password" => synthetic_private_marker syntax_error ];');
        chmod($file, 0600);
        [$status, $output, $error] = $this->boot('demo', null);
        $this->assertNotSame(0, $status);
        $this->assertStringNotContainsString('synthetic_private_marker', $output . $error);
        $this->assertStringContainsString('could not be parsed', $error);
    }
}
