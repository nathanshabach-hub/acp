<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class ReportsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testAnonymousEndpointsRequireLogin(): void
    {
        foreach (['/reports', '/reports/students/8214'] as $url) {
            $this->get($url);
            $this->assertRedirect(['controller' => 'Users', 'action' => 'login']);
            $this->assertHeaderContains('Cache-Control', 'no-store');
        }
    }

    public function testNonSchoolAndForgedSchoolRolesAreRejected(): void
    {
        foreach ([['user_id' => 2, 'user_type' => 'Teacher_Parent'],
            ['user_id' => 3, 'user_type' => 'Student'],
            ['user_id' => 7456, 'user_type' => 'Judge'],
            ['user_id' => 3, 'user_type' => 'School'],
            ['user_id' => 999999999, 'user_type' => 'School'],
        ] as $session) {
            $this->session($session);
            $this->get('/reports/students/8214');
            $this->assertResponseCode(403);
            $this->assertResponseNotContains('Student #8214');
        }
    }

    public function testInactiveExpiredAndAdministratorSessionsCannotReadReports(): void
    {
        $inactiveSchoolId = (int)ConnectionManager::get('default')->execute(
            "SELECT id FROM users WHERE user_type='School' AND status=0 LIMIT 1"
        )->fetchColumn(0);
        $this->assertGreaterThan(0, $inactiveSchoolId, 'Expected a local inactive school account.');
        foreach ([
            ['user_id' => $inactiveSchoolId, 'user_type' => 'School'],
            ['user_id' => 999999999, 'user_type' => 'School'],
            ['user_id' => null, 'user_type' => null, 'admin_id' => 1],
            ['user_id' => 1, 'user_type' => 'Admin', 'admin_id' => 1],
        ] as $session) {
            $this->session($session);
            $this->get('/reports/students/8214');
            $expected = isset($session['user_id']) ? 403 : 302;
            $this->assertSame($expected, $this->_response->getStatusCode(), json_encode($session));
            $this->assertResponseNotContains('Student #8214');
            $this->assertResponseNotContains('Hazz');
        }
    }

    public function testSchoolListWorksWithoutSelectedConvention(): void
    {
        $this->session(['user_id' => 7991, 'user_type' => 'School']);
        $this->get('/reports?q=8214');
        $this->assertResponseOk();
        $this->assertResponseNotContains('Student #8214');
        $this->assertResponseContains('/reports/students/8214');
        $this->assertResponseNotContains('<th scope="col">Identifier</th>');
        $this->assertResponseNotContains('customer_code');
        $this->assertHeaderContains('Cache-Control', 'no-store');
        $this->assertSession(null, 'sess_selected_convention_registration_id');
    }

    public function testExistingImpersonationSessionIsPreserved(): void
    {
        $this->session(['user_id' => 7991, 'user_type' => 'School', 'admin_id' => 1,
            'sess_selected_convention_registration_id' => 12345]);
        $this->get('/reports/students/8214');
        $this->assertResponseOk();
        $this->assertResponseContains('Convention history');
        $this->assertResponseContains('<noscript>');
        $this->assertSession(1, 'admin_id');
        $this->assertSession(12345, 'sess_selected_convention_registration_id');
        $this->assertResponseContains('vendor/chartjs-4.4.8/chart.umd.js');
        $this->assertHeaderContains('Cache-Control', 'no-store');
        preg_match('/<script id="report-chart-data" type="application\\/json">(.*?)<\\/script>/s', (string)$this->_response->getBody(), $match);
        $chart = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([14, 9, 15], $chart['events']);
        $this->assertCount(6, $chart['placements']);
        $released = (int)ConnectionManager::get('default')
            ->execute('SELECT results_release FROM conventionseasons WHERE id=26')->fetchColumn(0);
        foreach ($chart['placements'] as $series) {
            if ($released === 1) {
                $this->assertIsInt($series[2]);
            } else {
                $this->assertNull($series[2], 'Unreleased 2026 placements must be chart gaps.');
            }
        }
    }

    public function testCrossSchoolAndUnknownStudentsHaveIdenticalResponses(): void
    {
        $this->session(['user_id' => 5806, 'user_type' => 'School']);
        $this->get('/reports/students/8214');
        $this->assertResponseCode(404);
        $body = (string)$this->_response->getBody();
        $this->get('/reports/students/999999999');
        $this->assertResponseCode(404);
        $this->assertSame($body, (string)$this->_response->getBody());
    }

    public function testAllMutatingMethodsAreRejected(): void
    {
        $this->session(['user_id' => 7991, 'user_type' => 'School']);
        foreach (['/reports', '/reports/students/8214'] as $url) {
            foreach (['post', 'put', 'patch', 'delete'] as $method) {
                $this->{$method}($url);
                $this->assertResponseCode(405);
                $this->assertHeader('Allow', 'GET');
                $this->assertHeaderContains('Cache-Control', 'no-store');
            }
        }
    }

    public function testMalformedInputsFailWithoutStackTraces(): void
    {
        $this->session(['user_id' => 7991, 'user_type' => 'School']);
        foreach (['?page=1%20OR%201=1', '?limit=101', '?q[]=test', '?page=0',
            '?sort=password', '?q=%FF', '?q=' . str_repeat('a', 101)] as $query) {
            $this->get('/reports' . $query);
            $this->assertResponseCode(400);
            $this->assertResponseNotContains('Stacktrace');
            $this->assertResponseNotContains('SQLSTATE');
        }
        $this->get('/reports/students/1%20OR%201=1');
        $this->assertResponseCode(404);
        $this->assertResponseNotContains('Stacktrace');
        $this->get('/reports?page=999999');
        $this->assertResponseCode(404);
    }

    public function testSearchPaginationAndInjectionRemainSchoolScoped(): void
    {
        $this->session(['user_id' => 7991, 'user_type' => 'School']);
        $this->get('/reports?limit=1&page=2');
        $this->assertResponseOk();
        $this->assertResponseContains('Page 2 of');
        $this->get('/reports?q=' . urlencode("' OR 1=1 --"));
        $this->assertResponseOk();
        $this->assertResponseContains('No matching registered students.');
        $this->get('/reports?q=' . urlencode('<script>alert(1)</script>'));
        $this->assertResponseOk();
        $this->assertResponseNotContains('<script>alert(1)</script>');
    }
}
