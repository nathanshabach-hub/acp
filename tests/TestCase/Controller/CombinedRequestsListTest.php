<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DOMDocument;
use DOMXPath;

final class CombinedRequestsListTest extends TestCase
{
    use IntegrationTestTrait;

    public function testListIsScopedAndIncludesRelatedNamesForPageAndAjax(): void
    {
        $connection = ConnectionManager::get('default');
        $registration = $connection->execute(
            "SELECT cr.id, cr.user_id FROM conventionregistrations cr
             INNER JOIN users school ON school.id = cr.user_id
             INNER JOIN combinerequests r ON r.conventionregistration_id = cr.id AND r.user_id = cr.user_id
             WHERE school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1
             GROUP BY cr.id, cr.user_id HAVING COUNT(*) BETWEEN 1 AND 30 LIMIT 1"
        )->fetch('assoc');
        if (!$registration) {
            $this->markTestSkipped('An active school with 1 to 30 combined requests is required.');
        }
        $expected = $connection->execute(
            'SELECT COUNT(*) FROM combinerequests WHERE conventionregistration_id = ? AND user_id = ?',
            [$registration['id'], $registration['user_id']]
        )->fetchColumn(0);
        $this->session([
            'user_id' => (int)$registration['user_id'],
            'user_type' => 'School',
            'sess_selected_convention_registration_id' => (int)$registration['id'],
        ]);
        foreach ([false, true] as $ajax) {
            if ($ajax) {
                $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
            }
            $this->get('/combinerequests/viewlist');
            $this->assertResponseOk();
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML((string)$this->_response->getBody());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new DOMXPath($document);
            $this->assertCount((int)$expected, $xpath->query('//td[@data-title="Student Name"]'));
            foreach ($xpath->query('//td[@data-title="Event Name" or @data-title="Combined with"]') as $cell) {
                $this->assertNotSame('', trim($cell->textContent));
            }
        }

        $otherRegistration = $connection->execute(
            'SELECT id FROM conventionregistrations WHERE user_id <> ? LIMIT 1',
            [$registration['user_id']]
        )->fetchColumn(0);
        if ($otherRegistration) {
            $this->session(['sess_selected_convention_registration_id' => (int)$otherRegistration]);
            $this->get('/combinerequests/viewlist');
            $this->assertRedirectContains('/users/dashboard');
        }

        $this->session(['sess_selected_convention_registration_id' => 0]);
        $this->get('/combinerequests/viewlist');
        $this->assertRedirectContains('/users/dashboard');
    }
}
