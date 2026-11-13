<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Deployment\Environment;
use App\Middleware\EnvironmentSafetyMiddleware;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

if (Environment::isHosted()) {
    throw new RuntimeException('Run this middleware test locally only.');
}

$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new Response())->withStringBody('Controller reached');
    }
};
$keys = ['Environment.name', 'App.base', 'App.fullBaseUrl', 'Payments.enabled', 'Payments.mode'];
$original = [];
foreach ($keys as $key) {
    $original[$key] = Configure::read($key);
}
$checks = 0;
try {
    Configure::write('Environment.name', 'demo');
    Configure::write('App.base', '/acp_demo');
    Configure::write('App.fullBaseUrl', 'https://convention.accelerateministries.com.au');
    $request = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'GET']]);
    $request = $request->withUri($request->getUri()->withScheme('https')->withHost('convention.accelerateministries.com.au'))
        ->withAttribute('base', '/acp_demo');
    $middleware = new EnvironmentSafetyMiddleware();
    foreach ([[false, 'disabled'], [true, 'live'], [true, 'sandbox']] as [$enabled, $mode]) {
        Configure::write('Payments.enabled', $enabled);
        Configure::write('Payments.mode', $mode);
        foreach ([
            ['paymentsummary', 'GET', null, 200],
            ['paymentsummary', 'POST', null, $mode === 'sandbox' ? 200 : 403],
            ['paymentsummary', 'GET', 'Admin', 403],
            ['paymentprocess', 'POST', null, $mode === 'sandbox' ? 200 : 403],
            ['unknown', 'GET', null, 403],
            ['mytransactions', 'GET', null, 200],
            ['viewdetails', 'GET', null, 200],
            ['index', 'GET', 'Admin', 200],
            ['index', 'POST', 'Admin', 200],
            ['viewdetails', 'GET', 'Admin', 200],
            ['viewdetails', 'POST', 'Admin', 403],
            ['invoice', 'GET', 'Admin', 200],
            ['invoice', 'POST', 'Admin', 200],
            ['invoicepdf', 'GET', 'Admin', 200],
            ['invoicepdf', 'POST', 'Admin', 403],
            ['invoice', 'DELETE', 'Admin', 403],
            ['cancelrequest', 'GET', 'Admin', 403],
            ['cancelrequest', 'POST', 'Admin', 403],
            ['deleteconfirmed', 'POST', 'Admin', 403],
            ['paymentprocess', 'POST', 'Admin', 403],
            ['unknown', 'GET', 'Admin', 403],
            ['invoice', 'POST', null, 403],
            ['invoice', 'POST', 'Other', 403],
        ] as [$action, $method, $prefix, $expected]) {
            $response = $middleware->process($request->withMethod($method)->withAttribute('params', [
                'controller' => 'Transactions', 'action' => $action, 'prefix' => $prefix,
            ]), $handler);
            if ($response->getStatusCode() !== $expected) {
                throw new RuntimeException("Unexpected policy: $mode $method $prefix/$action");
            }
            $checks++;
        }
    }
    $wrongHost = $request->withUri($request->getUri()->withHost('wrong.invalid'))->withAttribute('params', [
        'controller' => 'Transactions', 'action' => 'invoice', 'prefix' => 'Admin',
    ])->withMethod('POST');
    if ($middleware->process($wrongHost, $handler)->getStatusCode() !== 403) {
        throw new RuntimeException('Admin invoice access bypassed the deployment host guard.');
    }
    $checks++;
    $wrongAdminBase = $request->withAttribute('base', '/wrong')->withAttribute('params', [
        'controller' => 'Transactions', 'action' => 'index', 'prefix' => 'Admin',
    ]);
    if ($middleware->process($wrongAdminBase, $handler)->getStatusCode() !== 403) {
        throw new RuntimeException('Admin transaction access bypassed the deployment base guard.');
    }
    $checks++;
    $wrongBase = $request->withAttribute('base', '/wrong')->withAttribute('params', [
        'controller' => 'Transactions', 'action' => 'paymentsummary',
    ]);
    if ($middleware->process($wrongBase, $handler)->getStatusCode() !== 403) {
        throw new RuntimeException('Deployment URL guard was bypassed.');
    }
    $checks++;
    echo "PASS: $checks Demo Payment Summary policy checks. No database changes or payments made.\n";
} finally {
    foreach ($original as $key => $value) {
        Configure::write($key, $value);
    }
}
