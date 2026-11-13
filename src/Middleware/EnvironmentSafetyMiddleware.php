<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Log\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class EnvironmentSafetyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $environment = Configure::read('Environment.name', 'local');
        $params = $request->getAttribute('params', []);
        if ($environment !== 'local') {
            $expectedBase = Configure::read('App.base');
            $expectedHost = parse_url((string)Configure::read('App.fullBaseUrl'), PHP_URL_HOST);
            if ($request->getAttribute('base') !== $expectedBase || $request->getUri()->getHost() !== $expectedHost) {
                Log::warning('A request was blocked because its deployment URL did not match configuration.');

                return $this->denied();
            }
            if (strtolower((string)($params['controller'] ?? '')) === 'transactions') {
                if ($environment === 'demo') {
                    $action = strtolower((string)($params['action'] ?? ''));
                    $prefix = strtolower((string)($params['prefix'] ?? ''));
                    $method = strtoupper($request->getMethod());
                    $adminInvoiceMethods = [
                        'index' => ['GET', 'POST'],
                        'viewdetails' => ['GET'],
                        'invoice' => ['GET', 'POST'],
                        'invoicepdf' => ['GET'],
                    ];
                    if ($prefix === 'admin' && in_array($method, $adminInvoiceMethods[$action] ?? [], true)) {
                        return $handler->handle($request);
                    }
                    if (in_array($action, ['mytransactions', 'viewdetails', 'paymentsummary'], true)
                        && strtoupper($request->getMethod()) === 'GET'
                        && empty($params['prefix'])) {
                        return $handler->handle($request);
                    }

                    $demoPaymentActions = [
                        'paymentsummary',
                        'paymentprocess',
                        'paymentsuccess',
                        'inpnotify',
                        'cancelbooking',
                        'invoiceprocess',
                    ];
                    if (!Configure::read('Payments.enabled', false)
                        || Configure::read('Payments.mode') !== 'sandbox'
                        || !empty($params['prefix'])
                        || !in_array(strtolower((string)($params['action'] ?? '')), $demoPaymentActions, true)) {
                        Log::warning('A payment request was blocked by environment policy.');

                        return $this->denied();
                    }
                } elseif (!Configure::read('Payments.enabled', false)) {
                    Log::warning('A payment request was blocked by environment policy.');

                    return $this->denied();
                }
            }
        }

        return $handler->handle($request);
    }

    private function denied(): ResponseInterface
    {
        return (new Response())->withStatus(403)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withType('text')
            ->withStringBody('This operation is unavailable in this environment.');
    }
}
