<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use App\Controller\Admin\ConventionsController;
use App\Middleware\ScopedPublicationCsrfMiddleware;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\MethodNotAllowedException;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ResultsPublicationControllerTest extends TestCase
{
    public function testGetCannotChangePublicationState(): void
    {
        $table = new FakePublicationSeasonTable(0);
        $controller = $this->controller('GET', $table, ['admin_id' => 12, 'admin_username' => 'admin']);

        try {
            $controller->seasonresultrelease('season-2026', 'convention');
            $this->fail('A GET request must be rejected.');
        } catch (MethodNotAllowedException) {
            $this->assertSame(0, $table->updateCount);
        }
    }

    public function testUnauthenticatedAndSquadAdminsCannotChangePublication(): void
    {
        $table = new FakePublicationSeasonTable(0);
        $unauthenticated = $this->controller('POST', $table);
        $response = $unauthenticated->seasonresultrelease('season-2026', 'convention');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/admin/admins/login', $response->getHeaderLine('Location'));
        $this->assertSame(0, $table->updateCount);

        $squad = $this->controller('POST', $table, ['admin_id' => 12, 'admin_username' => 'squad']);
        $this->expectException(ForbiddenException::class);
        $squad->seasonresultrelease('season-2026', 'convention');
    }

    public function testAuthorizedPostReleasesOnlyTheMatchedConventionSeason(): void
    {
        $table = new FakePublicationSeasonTable(0);
        $controller = $this->controller('POST', $table, ['admin_id' => 12, 'admin_username' => 'admin']);

        $response = $controller->seasonresultrelease('season-2026', 'convention');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $table->season->get('results_release'));
        $this->assertSame(['results_release' => 1], $table->updatedFields);
        $this->assertSame(['id' => 44, 'convention_id' => 3], $table->updatedConditions);
    }

    public function testAuthorizedPostWithValidCsrfTokenReleasesResults(): void
    {
        $table = new FakePublicationSeasonTable(0);
        $session = new Session();
        $session->write('admin_id', 12);
        $session->write('admin_username', 'admin');
        $token = (new CsrfProtectionMiddleware())->createToken();
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
            'url' => '/admin/conventions/seasonresultrelease/season-2026/convention',
            'session' => $session,
        ]))
            ->withAttribute('params', [
                'controller' => 'Conventions',
                'prefix' => 'Admin',
                'action' => 'seasonresultrelease',
            ])
            ->withCookieParams(['csrfToken' => $token])
            ->withParsedBody(['_csrfToken' => $token]);

        $response = (new ScopedPublicationCsrfMiddleware())->process(
            $request,
            new AuthorizedPublicationActionHandler($table),
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $table->season->get('results_release'));
        $this->assertSame(1, $table->updateCount);
    }

    public function testAuthorizedPostWithdrawsPublication(): void
    {
        $table = new FakePublicationSeasonTable(1);
        $controller = $this->controller('POST', $table, ['admin_id' => 12, 'admin_username' => 'admin']);

        $controller->seasonresultreleasestop('season-2026', 'convention');

        $this->assertSame(0, $table->season->get('results_release'));
        $this->assertSame(['results_release' => 0], $table->updatedFields);
        $this->assertSame(1, $table->updateCount);
    }

    public function testMismatchedConventionSlugCannotChangePublication(): void
    {
        $table = new FakePublicationSeasonTable(0);
        $controller = $this->controller('POST', $table, ['admin_id' => 12, 'admin_username' => 'admin']);

        $controller->seasonresultrelease('season-2026', 'other-convention');

        $this->assertSame(0, $table->updateCount);
        $this->assertSame(0, $table->season->get('results_release'));
    }

    private function controller(string $method, FakePublicationSeasonTable $table, array $sessionData = []): TestableConventionsController
    {
        $session = new Session();
        foreach ($sessionData as $key => $value) {
            $session->write($key, $value);
        }
        $request = new ServerRequest([
            'environment' => ['REQUEST_METHOD' => $method],
            'url' => '/admin/conventions/seasonresultrelease/season-2026/convention',
            'session' => $session,
        ]);
        $request = $request->withAttribute('params', [
            'controller' => 'Conventions',
            'prefix' => 'Admin',
            'action' => 'seasonresultrelease',
        ]);
        $controller = new TestableConventionsController($request);
        $controller->Conventionseasons = $table;
        $controller->Flash = new FakePublicationFlash();

        return $controller;
    }
}

final class TestableConventionsController extends ConventionsController
{
    public function initialize(): void
    {
    }

    public function redirect(UriInterface|array|string $url, int $status = 302): ?Response
    {
        $location = is_array($url) && ($url['controller'] ?? null) === 'Admins'
            ? '/admin/admins/login'
            : '/admin/conventions/seasons/convention';

        return (new Response())->withStatus($status)->withHeader('Location', $location);
    }
}

final class FakePublicationSeasonTable
{
    public Entity $season;
    public int $updateCount = 0;
    public array $updatedFields = [];
    public array $updatedConditions = [];

    public function __construct(mixed $releaseFlag)
    {
        $this->season = new Entity([
            'id' => 44,
            'slug' => 'season-2026',
            'convention_id' => 3,
            'results_release' => $releaseFlag,
            'Conventions' => new Entity(['slug' => 'convention']),
        ]);
    }

    public function find(): FakePublicationSeasonQuery
    {
        return new FakePublicationSeasonQuery($this->season);
    }

    public function updateAll(array $fields, array $conditions): int
    {
        $this->updateCount++;
        $this->updatedFields = $fields;
        $this->updatedConditions = $conditions;
        $this->season->set('results_release', $fields['results_release']);

        return 1;
    }
}

final class FakePublicationSeasonQuery
{
    public function __construct(private Entity $season)
    {
    }

    public function where(array $conditions): self
    {
        return $this;
    }

    public function contain(array $associations): self
    {
        return $this;
    }

    public function first(): Entity
    {
        return $this->season;
    }
}

final class FakePublicationFlash
{
    public function error(string $message): void
    {
    }

    public function success(string $message): void
    {
    }
}

final class AuthorizedPublicationActionHandler implements RequestHandlerInterface
{
    public function __construct(private FakePublicationSeasonTable $table)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $controller = new TestableConventionsController($request);
        $controller->Conventionseasons = $this->table;
        $controller->Flash = new FakePublicationFlash();

        return $controller->seasonresultrelease('season-2026', 'convention');
    }
}
