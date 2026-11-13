<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Deployment\SafetyPolicy;
use App\Middleware\EnvironmentSafetyMiddleware;
use Cake\Core\Configure;
use Cake\Http\Exception\ServiceUnavailableException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class EnvironmentSafetyMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Environment.name', 'demo');
        Configure::write('App.base', '/acp_demo');
        Configure::write('App.fullBaseUrl', 'https://convention.accelerateministries.com.au');
        Configure::write('Payments.enabled', true);
        Configure::write('Payments.mode', 'sandbox');
        Configure::write('Integrations.allowed', []);
        Configure::write('Commands.allowed', []);
    }

    private function request(string $controller): ServerRequest
    {
        $request = new ServerRequest(['url' => '/acp_demo/transactions/inpnotify']);

        return $request
            ->withUri($request->getUri()->withScheme('https')->withHost('convention.accelerateministries.com.au'))
            ->withAttribute('base', '/acp_demo')
            ->withAttribute('params', ['controller' => $controller, 'action' => 'inpnotify']);
    }

    private function handler(bool $mustNotRun): RequestHandlerInterface
    {
        return new class ($mustNotRun) implements RequestHandlerInterface {
            public function __construct(private bool $mustNotRun)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->mustNotRun) {
                    throw new RuntimeException('Controller must not be reached.');
                }

                return (new Response())->withStringBody('authorized handler');
            }
        };
    }

    public function testDemoAllowsOnlySandboxPaymentAndInvoiceActions(): void
    {
        $allowed = [
            'paymentsummary', 'paymentprocess', 'paymentsuccess', 'inpnotify', 'cancelbooking',
            'invoiceprocess', 'viewdetails',
        ];
        foreach ($allowed as $action) {
            $request = $this->request('Transactions')->withAttribute('params', [
                'controller' => 'Transactions', 'action' => $action,
            ]);
            $response = (new EnvironmentSafetyMiddleware())->process($request, $this->handler(false));
            $this->assertSame(200, $response->getStatusCode(), $action);

            $adminRequest = $request->withAttribute('params', [
                'prefix' => 'Admin', 'controller' => 'Transactions', 'action' => $action,
            ]);
            $adminReadAllowed = $action === 'viewdetails';
            $this->assertSame($adminReadAllowed ? 200 : 403, (new EnvironmentSafetyMiddleware())->process(
                $adminRequest,
                $this->handler(!$adminReadAllowed),
            )->getStatusCode(), $action . ' admin prefix');
        }

        foreach (['transactioninfo', 'unknown'] as $action) {
            $request = $this->request('Transactions')->withAttribute('params', [
                'controller' => 'Transactions', 'action' => $action,
            ]);
            $response = (new EnvironmentSafetyMiddleware())->process($request, $this->handler(true));
            $this->assertSame(403, $response->getStatusCode(), $action);
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertStringNotContainsString('paypal', (string)$response->getBody());
        }
    }

    public function testDemoAllowsTransactionReadsWhenPaymentsAreDisabled(): void
    {
        Configure::write('Payments.enabled', false);
        Configure::write('Payments.mode', 'disabled');
        $middleware = new EnvironmentSafetyMiddleware();
        foreach (['mytransactions', 'viewdetails', 'paymentsummary'] as $action) {
            $request = $this->request('Transactions')->withAttribute('params', [
                'controller' => 'Transactions', 'action' => $action,
            ]);
            $this->assertSame(200, $middleware->process($request, $this->handler(false))->getStatusCode());

            $response = $middleware->process($request->withMethod('POST'), $this->handler(true));
            $this->assertSame(403, $response->getStatusCode());
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));

            $adminRequest = $request->withAttribute('params', [
                'prefix' => 'Admin', 'controller' => 'Transactions', 'action' => $action,
            ]);
            $adminReadAllowed = $action === 'viewdetails';
            $this->assertSame($adminReadAllowed ? 200 : 403, $middleware->process(
                $adminRequest, $this->handler(!$adminReadAllowed),
            )->getStatusCode());
        }
    }

    public function testDemoAllowsOnlyExplicitAdminInvoiceAndViewingMethods(): void
    {
        Configure::write('Payments.enabled', false);
        Configure::write('Payments.mode', 'disabled');
        foreach ([
            ['index', 'GET', true], ['index', 'POST', true],
            ['viewdetails', 'GET', true], ['viewdetails', 'POST', false],
            ['invoice', 'GET', true], ['invoice', 'POST', true],
            ['invoicepdf', 'GET', true], ['invoicepdf', 'POST', false],
            ['invoice', 'DELETE', false], ['cancelrequest', 'POST', false],
            ['deleteconfirmed', 'POST', false], ['paymentprocess', 'POST', false],
        ] as [$action, $method, $allowed]) {
            $request = $this->request('Transactions')->withMethod($method)->withAttribute('params', [
                'prefix' => 'Admin', 'controller' => 'Transactions', 'action' => $action,
            ]);
            $this->assertSame($allowed ? 200 : 403, (new EnvironmentSafetyMiddleware())->process(
                $request, $this->handler(!$allowed),
            )->getStatusCode(), "$method $action");
        }
    }

    public function testDemoPaymentRoutesRemainDeniedUnlessSandboxIsExplicitlyEnabled(): void
    {
        Configure::write('Payments.enabled', false);
        $request = $this->request('Transactions')->withAttribute('params', [
            'controller' => 'Transactions', 'action' => 'paymentprocess',
        ]);
        $response = (new EnvironmentSafetyMiddleware())->process($request, $this->handler(true));
        $this->assertSame(403, $response->getStatusCode());

        Configure::write('Payments.enabled', true);
        Configure::write('Payments.mode', 'live');
        $response = (new EnvironmentSafetyMiddleware())->process($request, $this->handler(true));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReportsAreNotBlockedAndWrongEnvironmentUrlsAreDenied(): void
    {
        $middleware = new EnvironmentSafetyMiddleware();
        $this->assertSame(200, $middleware->process($this->request('Reports'), $this->handler(false))->getStatusCode());
        $this->assertSame(403, $middleware->process($this->request('Reports')->withAttribute('base', '/acp'), $this->handler(true))->getStatusCode());
        $wrongHost = $this->request('Reports')->withUri($this->request('Reports')->getUri()->withHost('wrong.invalid'));
        $this->assertSame(403, $middleware->process($wrongHost, $this->handler(true))->getStatusCode());
    }

    public function testLocalWorkflowIsUnchanged(): void
    {
        Configure::write('Environment.name', 'local');
        $this->assertSame(200, (new EnvironmentSafetyMiddleware())->process($this->request('Transactions'), $this->handler(false))->getStatusCode());
        SafetyPolicy::assertIntegrationAllowed('recaptcha');
        SafetyPolicy::assertCommandAllowed(['cake', 'existing-command']);
    }

    public function testDemoCannotCallProductionIntegration(): void
    {
        $this->expectException(ServiceUnavailableException::class);
        SafetyPolicy::assertIntegrationAllowed('recaptcha');
    }

    public function testDemoCannotRunProductionOrMigrationCommands(): void
    {
        $this->expectException(RuntimeException::class);
        SafetyPolicy::assertCommandAllowed(['cake', 'migrations', 'migrate']);
    }

    public function testDemoCannotBeReenabledByAccidentalPaymentConfigurationOverride(): void
    {
        Configure::write('Payments.enabled', true);
        Configure::write('Payments.mode', 'live');
        $this->assertSame(403, (new EnvironmentSafetyMiddleware())->process(
            $this->request('Transactions'), $this->handler(true),
        )->getStatusCode());
    }

    public function testHelpRemainsAvailableWithoutEnablingJobs(): void
    {
        SafetyPolicy::assertCommandAllowed(['cake', '--help']);
        $this->addToAssertionCount(1);
    }
}
