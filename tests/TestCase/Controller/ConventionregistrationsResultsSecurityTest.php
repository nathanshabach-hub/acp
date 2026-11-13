<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\ConventionregistrationsController;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\UriInterface;

final class ConventionregistrationsResultsSecurityTest extends TestCase
{
    public function testUnauthenticatedRequestCannotReadResultPackage(): void
    {
        $controller = new TestableConventionregistrationsController(new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'url' => '/conventionregistrations/resultpackageprint',
            'session' => new Session(),
        ]));
        $controller->Flash = new FakeResultPackageFlash();

        $response = $controller->resultpackageprint();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('/users/login', $response->getHeaderLine('Location'));
        $this->assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
        $this->assertStringNotContainsString('placement-secret', (string)$response->getBody());
    }

    public function testUnreleasedAndMissingFlagsBlockEveryResultPackageAction(): void
    {
        foreach ([0, null] as $releaseFlag) {
            foreach ([
                'resultpackage',
                'resultpackageprint',
                'resultpackageindividual',
                'resultpackageindividualprint',
            ] as $action) {
                $controller = $this->controller($releaseFlag, 41, 41);

                $response = $controller->{$action}();

                $this->assertInstanceOf(Response::class, $response, $action);
                $this->assertSame(302, $response->getStatusCode(), $action);
                $this->assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
                $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
                $this->assertStringNotContainsString('placement-secret', (string)$response->getBody());
            }
        }
    }

    public function testCrossSchoolRegistrationIsNotRendered(): void
    {
        $controller = $this->controller(1, 41, 42);

        $response = $controller->resultpackageprint();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringNotContainsString('placement-secret', (string)$response->getBody());
    }

    public function testReleasedOwnedRegistrationContinuesToPrintAction(): void
    {
        $controller = $this->controller(1, 41, 41);

        $response = $controller->resultpackageprint();

        $this->assertNull($response);
        $this->assertSame('private, no-store, max-age=0', $controller->getResponse()->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $controller->getResponse()->getHeaderLine('Pragma'));
    }

    private function controller(mixed $releaseFlag, int $sessionSchoolId, int $registrationOwnerId): TestableConventionregistrationsController
    {
        $session = new Session();
        $session->write('user_id', $sessionSchoolId);
        $session->write('user_type', 'School');
        $session->write('sess_selected_convention_registration_id', 88);
        $request = new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'url' => '/conventionregistrations/resultpackageprint',
            'session' => $session,
        ]);
        $controller = new TestableConventionregistrationsController($request);
        $controller->Flash = new FakeResultPackageFlash();
        $controller->Users = new FakeResultPackageUsersTable($sessionSchoolId);
        $controller->Conventionregistrations = new FakeResultPackageRegistrationsTable(
            $releaseFlag,
            $registrationOwnerId,
        );
        $controller->Conventionseasonevents = new FakeResultPackageEventsTable();
        $controller->Resultpositions = new FakeResultPackagePositionsTable();

        return $controller;
    }
}

final class TestableConventionregistrationsController extends ConventionregistrationsController
{
    public function initialize(): void
    {
    }

    public function redirect(UriInterface|array|string $url, int $status = 302): ?Response
    {
        $location = is_array($url) && ($url['action'] ?? null) === 'login'
            ? '/users/login'
            : '/users/dashboard';

        return $this->getResponse()
            ->withStatus($status)
            ->withHeader('Location', $location);
    }
}

final class FakeResultPackageUsersTable
{
    public function __construct(private int $userId)
    {
    }

    public function find(): FakeResultPackageQuery
    {
        return new FakeResultPackageQuery(function (array $conditions): ?Entity {
            if ((int)($conditions['Users.id'] ?? 0) !== $this->userId
                || ($conditions['Users.user_type'] ?? null) !== 'School'
                || (int)($conditions['Users.activation_status'] ?? 0) !== 1
                || (int)($conditions['Users.status'] ?? 0) !== 1) {
                return null;
            }

            return new Entity(['id' => $this->userId]);
        });
    }
}

final class FakeResultPackageRegistrationsTable
{
    public function __construct(private mixed $releaseFlag, private int $ownerId)
    {
    }

    public function find(): FakeResultPackageQuery
    {
        return new FakeResultPackageQuery(function (array $conditions): ?Entity {
            if ((int)($conditions['Conventionregistrations.id'] ?? 0) !== 88
                || (int)($conditions['Conventionregistrations.user_id'] ?? 0) !== $this->ownerId) {
                return null;
            }

            return new Entity([
                'id' => 88,
                'user_id' => $this->ownerId,
                'conventionseason_id' => 7,
                'convention_id' => 3,
                'season_id' => 2,
                'season_year' => 2026,
                'Conventions' => new Entity(['id' => 3]),
                'Conventionseasons' => new Entity([
                    'id' => 7,
                    'results_release' => $this->releaseFlag,
                    'convention_id' => 3,
                    'season_id' => 2,
                    'season_year' => 2026,
                ]),
            ]);
        });
    }
}

final class FakeResultPackageEventsTable
{
    public function find(): FakeResultPackageQuery
    {
        return new FakeResultPackageQuery(static fn(): array => []);
    }
}

final class FakeResultPackagePositionsTable
{
    public function find(): FakeResultPackageQuery
    {
        return new FakeResultPackageQuery(static fn(): array => []);
    }
}

final class FakeResultPackageQuery
{
    private array $conditions = [];

    public function __construct(private \Closure $resolver)
    {
    }

    public function where(array $conditions): self
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function contain(array $associations): self
    {
        return $this;
    }

    public function orderBy(array $order): self
    {
        return $this;
    }

    public function first(): ?Entity
    {
        return ($this->resolver)($this->conditions);
    }

    public function all(): array
    {
        return ($this->resolver)($this->conditions);
    }
}

final class FakeResultPackageFlash
{
    public function error(string $message): void
    {
    }
}
