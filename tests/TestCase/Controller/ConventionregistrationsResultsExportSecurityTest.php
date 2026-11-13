<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\ConventionregistrationsController;
use App\Service\ResultExportToken;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

final class ConventionregistrationsResultsExportSecurityTest extends TestCase
{
    private array $exportDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->exportDirectories as $directory) {
            $this->removeTestDirectory($directory);
        }
        parent::tearDown();
    }

    public function testPublishedOwnedExportIsDownloadedAndTokenIsOneTime(): void
    {
        [$token, $metadata, $directory] = $this->tokenFixture();
        $session = $this->sessionWithToken(41, $token, $metadata);
        $controller = $this->controller($session);

        $response = $controller->downloadallresultsfile($token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
        $this->assertSame('PK-test-zip-content', (string)$response->getBody());
        $this->assertArrayNotHasKey($token, (array)$session->read('downloadallresults_tokens'));
        $this->assertDirectoryExists($directory);

        $reusedResponse = $controller->downloadallresultsfile($token);
        $this->assertSame(404, $reusedResponse->getStatusCode());
        $this->assertSame('Download unavailable.', (string)$reusedResponse->getBody());
    }

    public function testPublicationWithdrawalAfterGenerationRevokesExportWithoutZipBytes(): void
    {
        [$token, $metadata, $directory] = $this->tokenFixture();
        $controller = $this->controller($this->sessionWithToken(41, $token, $metadata), releaseFlag: 0);

        $response = $controller->downloadallresultsfile($token);

        $this->assertDeniedWithoutZip($response);
        $this->assertArrayNotHasKey($token, (array)$controller->getRequest()->getSession()->read('downloadallresults_tokens'));
        $this->assertDirectoryExists($directory);
    }

    public function testUnreleasedAndMissingPublicationFlagsAreDenied(): void
    {
        foreach ([0, null] as $releaseFlag) {
            [$token, $metadata] = $this->tokenFixture();
            $controller = $this->controller($this->sessionWithToken(41, $token, $metadata), releaseFlag: $releaseFlag);

            $this->assertDeniedWithoutZip($controller->downloadallresultsfile($token));
        }
    }

    public function testAnonymousAndLoggedOutSchoolSessionsAreDenied(): void
    {
        [$token, $metadata] = $this->tokenFixture();
        $anonymous = $this->controller($this->sessionWithToken(0, $token, $metadata));
        $this->assertDeniedWithoutZip($anonymous->downloadallresultsfile($token));

        $loggedOut = $this->controller($this->sessionWithToken(41, $token, $metadata), activeUser: false);
        $this->assertDeniedWithoutZip($loggedOut->downloadallresultsfile($token));
    }

    public function testCrossSchoolTokenCannotBeUsed(): void
    {
        [$token, $metadata] = $this->tokenFixture();
        $controller = $this->controller($this->sessionWithToken(42, $token, $metadata));

        $this->assertDeniedWithoutZip($controller->downloadallresultsfile($token));
    }

    public function testExpiredTokenIsRevoked(): void
    {
        [$token, $metadata, $directory] = $this->tokenFixture(expires: time() - 10);
        $controller = $this->controller($this->sessionWithToken(41, $token, $metadata));

        $this->assertDeniedWithoutZip($controller->downloadallresultsfile($token));
        $this->assertArrayNotHasKey($token, (array)$controller->getRequest()->getSession()->read('downloadallresults_tokens'));
        $this->assertDirectoryExists($directory);
    }

    public function testTamperedTokenSignatureIsRevoked(): void
    {
        [$token, $metadata] = $this->tokenFixture();
        $metadata['signature'] = str_repeat('0', 64);
        $controller = $this->controller($this->sessionWithToken(41, $token, $metadata));

        $this->assertDeniedWithoutZip($controller->downloadallresultsfile($token));
    }

    public function testTokenKeyMustHaveExpectedFormat(): void
    {
        $controller = $this->controller($this->sessionWithToken(41, 'invalid-token', []));

        $this->assertDeniedWithoutZip($controller->downloadallresultsfile('../invalid-token'));
    }

    public function testPathTraversalMetadataCannotReadOutsideExportDirectory(): void
    {
        [$token, $metadata, $directory] = $this->tokenFixture();
        $metadata['export_dir'] = $directory . DS . '..' . DS . '..';
        $metadata['signature'] = ResultExportToken::signature(
            $token,
            41,
            88,
            7,
            (int)$metadata['expires'],
            (string)$metadata['zip_name'],
            (string)$metadata['export_dir'],
        );
        $controller = $this->controller($this->sessionWithToken(41, $token, $metadata));

        $this->assertDeniedWithoutZip($controller->downloadallresultsfile($token));
    }

    private function tokenFixture(?int $expires = null): array
    {
        $root = rtrim(TMP, DS) . DS . 'student-result-exports';
        if (!is_dir($root)) {
            mkdir($root, 0700, true);
        }
        $directory = realpath($root) . DS . bin2hex(random_bytes(16));
        mkdir($directory, 0700);
        $this->exportDirectories[] = $directory;
        file_put_contents($directory . DS . '.active.lock', '');
        $token = ResultExportToken::create();
        $expires ??= time() + 3600;
        $metadata = [
            'zip_name' => 'convention-2026-student-results.zip',
            'export_dir' => $directory,
            'expires' => $expires,
            'school_id' => 41,
            'registration_id' => 88,
            'conventionseason_id' => 7,
        ];
        $metadata['signature'] = ResultExportToken::signature(
            $token,
            41,
            88,
            7,
            $expires,
            $metadata['zip_name'],
            $directory,
        );
        file_put_contents($directory . DS . $metadata['zip_name'], 'PK-test-zip-content');

        return [$token, $metadata, $directory];
    }

    private function sessionWithToken(int $schoolId, string $token, array $metadata): Session
    {
        $session = new Session();
        $session->write('user_id', $schoolId);
        $session->write('user_type', 'School');
        $session->write('downloadallresults_tokens', [$token => $metadata]);

        return $session;
    }

    private function controller(Session $session, mixed $releaseFlag = 1, bool $activeUser = true): ExportSecurityTestController
    {
        $controller = new ExportSecurityTestController(new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'GET'],
            'url' => '/conventionregistrations/downloadallresultsfile',
            'session' => $session,
        ]));
        $controller->Users = new ExportSecurityUsersTable($activeUser);
        $controller->Conventionregistrations = new ExportSecurityRegistrationsTable($releaseFlag);

        return $controller;
    }

    private function assertDeniedWithoutZip(Response $response): void
    {
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Download unavailable.', (string)$response->getBody());
        $this->assertStringNotContainsString('PK-test-zip-content', (string)$response->getBody());
        $this->assertSame('private, no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
    }

    private function removeTestDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $item) {
            $path = $directory . DS . $item;
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
            } else {
                $this->removeTestDirectory($path);
            }
        }
        rmdir($directory);
    }
}

final class ExportSecurityTestController extends ConventionregistrationsController
{
    public function initialize(): void
    {
    }
}

final class ExportSecurityUsersTable
{
    public function __construct(private bool $active)
    {
    }

    public function find(): ExportSecurityQuery
    {
        return new ExportSecurityQuery(fn(array $conditions): ?Entity => $this->active
            && (int)($conditions['Users.id'] ?? 0) > 0
            && ($conditions['Users.user_type'] ?? null) === 'School'
            ? new Entity(['id' => (int)$conditions['Users.id']])
            : null);
    }
}

final class ExportSecurityRegistrationsTable
{
    public function __construct(private mixed $releaseFlag)
    {
    }

    public function find(): ExportSecurityQuery
    {
        return new ExportSecurityQuery(function (array $conditions): ?Entity {
            if ((int)($conditions['Conventionregistrations.id'] ?? 0) !== 88
                || (int)($conditions['Conventionregistrations.user_id'] ?? 0) !== 41) {
                return null;
            }

            return new Entity([
                'id' => 88,
                'user_id' => 41,
                'conventionseason_id' => 7,
                'convention_id' => 3,
                'season_id' => 2,
                'season_year' => 2026,
                'Conventions' => new Entity(['id' => 3]),
                'Conventionseasons' => new Entity([
                    'id' => 7,
                    'convention_id' => 3,
                    'season_id' => 2,
                    'season_year' => 2026,
                    'results_release' => $this->releaseFlag,
                ]),
            ]);
        });
    }
}

final class ExportSecurityQuery
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

    public function select(array $fields): self
    {
        return $this;
    }

    public function contain(array $associations): self
    {
        return $this;
    }

    public function first(): ?Entity
    {
        return ($this->resolver)($this->conditions);
    }
}
