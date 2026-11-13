<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DOMDocument;
use DOMXPath;

final class JudgesSearchTest extends TestCase
{
    use IntegrationTestTrait;

    public function testSearchFiltersNamesAndEmailForAjaxAndFullPage(): void
    {
        $db = ConnectionManager::get('default');
        $adminId = $db->execute('SELECT id FROM admins LIMIT 1')->fetchColumn(0);
        $judge = $db->execute(
            "SELECT first_name, last_name, email_address FROM users
             WHERE status IN (0, 1, 2)
               AND (user_type = 'Judge' OR (user_type = 'Teacher_Parent' AND is_judge = 1))
               AND first_name <> '' AND last_name <> '' AND email_address <> ''
             ORDER BY id DESC LIMIT 1"
        )->fetch('assoc');
        if (!$adminId || !$judge) {
            $this->markTestSkipped('An administrator and a named judge with email are required.');
        }
        $this->session(['admin_id' => (int)$adminId, 'sess_admin_header_season_id' => 0]);

        foreach (array_values($judge) as $keyword) {
            foreach ([false, true] as $ajax) {
                if ($ajax) {
                    $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
                    $this->post('/admin/users/judges', ['Users' => ['keyword' => $keyword]]);
                } else {
                    $this->configRequest(['headers' => ['X-Requested-With' => '']]);
                    $this->get('/admin/users/judges?keyword=' . urlencode($keyword));
                }
                $this->assertResponseOk();
                $xpath = $this->responseXPath();
                $rows = $xpath->query('//table/tbody/tr');
                $this->assertGreaterThan(0, $rows->length);
                foreach ($rows as $row) {
                    $matched = false;
                    foreach ($xpath->query('./td[@data-title="First Name" or @data-title="Surname" or @data-title="Email Address"]', $row) as $cell) {
                        $matched = $matched || stripos(trim($cell->textContent), $keyword) !== false;
                    }
                    $this->assertTrue($matched, 'Every displayed judge must match the search.');
                }
                foreach ($xpath->query('//th/a | //*[@id="pagingLinks"]//a') as $link) {
                    parse_str((string)parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
                    $this->assertSame($keyword, $query['keyword'] ?? null);
                }
            }
        }
    }

    public function testUnmatchedSearchIncludingApostropheDoesNotShowUnfilteredRecords(): void
    {
        $adminId = ConnectionManager::get('default')->execute('SELECT id FROM admins LIMIT 1')->fetchColumn(0);
        if (!$adminId) {
            $this->markTestSkipped('An administrator is required.');
        }
        $this->session(['admin_id' => (int)$adminId, 'sess_admin_header_season_id' => 0]);
        $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
        $this->post('/admin/users/judges', ['Users' => ['keyword' => "no-such-judge'" . bin2hex(random_bytes(12))]]);
        $this->assertResponseOk();
        $this->assertResponseContains('No record found.');
    }

    public function testFilteredPaginationHasWorkingNextPage(): void
    {
        $db = ConnectionManager::get('default');
        $adminId = $db->execute('SELECT id FROM admins LIMIT 1')->fetchColumn(0);
        $total = (int)$db->execute(
            "SELECT COUNT(*) FROM users WHERE status IN (0, 1, 2)
             AND (user_type = 'Judge' OR (user_type = 'Teacher_Parent' AND is_judge = 1))
             AND (first_name LIKE '%a%' OR last_name LIKE '%a%' OR email_address LIKE '%a%')"
        )->fetchColumn(0);
        if (!$adminId || $total <= 50) {
            $this->markTestSkipped('An administrator and more than 50 matching judges are required.');
        }
        $this->session(['admin_id' => (int)$adminId, 'sess_admin_header_season_id' => 0]);
        $this->get('/admin/users/judges?keyword=a');
        $this->assertResponseOk();
        $xpath = $this->responseXPath();
        $next = $xpath->query('//*[@id="pagingLinks"]//a[contains(., "Next")]')->item(0);
        $this->assertNotNull($next);
        $nextUrl = $next->getAttribute('href');
        parse_str((string)parse_url($nextUrl, PHP_URL_QUERY), $query);
        $this->assertSame('a', $query['keyword'] ?? null);
        $this->assertSame('2', $query['page'] ?? null);
        $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
        $this->get($nextUrl);
        $this->assertResponseOk();
        $this->assertCount(min(50, $total - 50), $this->responseXPath()->query('//table/tbody/tr'));
        $this->assertResponseContains('2 of ');
    }

    private function responseXPath(): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML((string)$this->_response->getBody());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
