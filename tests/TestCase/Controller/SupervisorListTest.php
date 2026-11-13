<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DOMDocument;
use DOMXPath;

final class SupervisorListTest extends TestCase
{
    use IntegrationTestTrait;

    public function testSupervisorsAreGroupedAndPaginatedIndependently(): void
    {
        $connection = ConnectionManager::get('default');
        $schoolId = $connection->execute(
            "SELECT school.id FROM users school
             INNER JOIN users supervisor ON supervisor.school_id = school.id
             WHERE school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1
               AND supervisor.user_type = 'Teacher_Parent' AND supervisor.status = 1
             GROUP BY school.id HAVING COUNT(*) > 30 LIMIT 1"
        )->fetchColumn();
        if (!$schoolId) {
            $this->markTestSkipped('A school with more than 30 active supervisors is required.');
        }

        $this->session(['user_id' => (int)$schoolId, 'user_type' => 'School']);
        $this->get('/users/teachers');
        $this->assertResponseOk();
        $xpath = $this->responseXPath();
        $groups = ['Active' => 1, 'Archive' => 2, 'Inactive' => 0];

        foreach ($groups as $label => $status) {
            $nodes = $xpath->query("//details[contains(concat(' ', normalize-space(@class), ' '), ' supervisor-accordion ')][summary/span[1][normalize-space(.)='$label']]");
            $this->assertCount(1, $nodes);
            $group = $nodes->item(0);
            $this->assertFalse($group->hasAttribute('open'));
            $total = (int)$connection->execute(
                "SELECT COUNT(*) FROM users WHERE school_id = ? AND user_type = 'Teacher_Parent' AND status = ?",
                [(int)$schoolId, $status]
            )->fetchColumn();
            $this->assertSame((string)$total, trim($xpath->query('./summary/span[2]', $group)->item(0)->textContent));
            $rows = $xpath->query('.//tbody/tr', $group);
            $this->assertCount(min(30, $total), $rows);
            foreach ($xpath->query('.//td[@data-title="Status"]', $group) as $cell) {
                $this->assertSame($label, trim($cell->textContent));
            }
        }

        $this->assertResponseContains('active%5Bpage%5D=2');
        $this->get('/users/teachers?active%5Bpage%5D=2&section=active');
        $this->assertResponseOk();
        $xpath = $this->responseXPath();
        $this->assertCount(0, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " supervisor-accordion ")][@open]'));
        $this->assertResponseContains('Page 2 of');
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
