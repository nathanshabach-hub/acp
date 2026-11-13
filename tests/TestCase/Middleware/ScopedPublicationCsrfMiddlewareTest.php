<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\ScopedPublicationCsrfMiddleware;
use Cake\Core\Configure;
use Cake\Http\Exception\InvalidCsrfTokenException;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ScopedPublicationCsrfMiddlewareTest extends TestCase
{
    public function testScopedGetIssuesTokenForAdminSeasonForm(): void
    {
        $handler = new PublicationCsrfTestHandler();
        $response = (new ScopedPublicationCsrfMiddleware())->process(
            $this->request('GET', 'seasons'),
            $handler,
        );

        $this->assertNotEmpty($handler->request?->getAttribute('csrfToken'));
        $cookie = $response->getCookie('csrfToken');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie['httponly']);
        $this->assertSame('Lax', $cookie['samesite']);
    }

    public function testPublicationPostRequiresCsrfToken(): void
    {
        $this->expectException(InvalidCsrfTokenException::class);
        (new ScopedPublicationCsrfMiddleware())->process(
            $this->request('POST', 'seasonresultrelease'),
            new PublicationCsrfTestHandler(),
        );
    }

    public function testPublicationPostAcceptsMatchingCookieAndFormToken(): void
    {
        $token = (new CsrfProtectionMiddleware())->createToken();
        $request = $this->request('POST', 'seasonresultreleasestop')
            ->withCookieParams(['csrfToken' => $token])
            ->withParsedBody(['_csrfToken' => $token]);
        $handler = new PublicationCsrfTestHandler();

        $response = (new ScopedPublicationCsrfMiddleware())->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($handler->request);
    }

    public function testOtherRoutesAreNotAffectedByScopedCsrf(): void
    {
        $handler = new PublicationCsrfTestHandler();
        $response = (new ScopedPublicationCsrfMiddleware())->process(
            $this->request('POST', 'index', 'Results'),
            $handler,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($handler->request);
    }

    public function testErrorLogPageIssuesToken(): void
    {
        $handler = new PublicationCsrfTestHandler();
        (new ScopedPublicationCsrfMiddleware())->process($this->request('GET', 'errorlogs', 'Admins'), $handler);
        $this->assertNotEmpty($handler->request?->getAttribute('csrfToken'));
    }

    public function testErrorLogDeletionRequiresToken(): void
    {
        $this->expectException(InvalidCsrfTokenException::class);
        (new ScopedPublicationCsrfMiddleware())->process(
            $this->request('POST', 'deleteerrorlogs', 'Admins'),
            new PublicationCsrfTestHandler(),
        );
    }

    public function testHostedEnvironmentsUseSeparateCookieNamesAndPaths(): void
    {
        foreach (['demo' => '/acp_demo/', 'live' => '/acp/'] as $environment => $path) {
            Configure::write('PublicationCsrf.cookieName', 'acp_' . $environment . '_csrf');
            $request = $this->request('GET', 'seasons')->withAttribute('webroot', $path);
            $response = (new ScopedPublicationCsrfMiddleware())->process($request, new PublicationCsrfTestHandler());
            $cookie = $response->getCookie('acp_' . $environment . '_csrf');
            $this->assertNotNull($cookie);
            $this->assertSame($path, $cookie['path']);
            $this->assertNull($response->getCookie('csrfToken'));
        }
    }

    private function request(string $method, string $action, string $controller = 'Conventions'): ServerRequest
    {
        return (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => $method],
            'url' => '/admin/conventions/' . $action,
        ]))
            ->withAttribute('params', [
                'prefix' => 'Admin',
                'controller' => $controller,
                'action' => $action,
            ]);
    }
}

final class PublicationCsrfTestHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return new Response();
    }
}
