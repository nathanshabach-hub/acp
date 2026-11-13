<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

final class ConventionSupervisorListTest extends TestCase
{
    use IntegrationTestTrait;

    public function testFullPageAndAjaxListOnlyTheOwnedSelectedRegistration(): void
    {
        $db = ConnectionManager::get('default');
        $registration = $db->execute(
            "SELECT cr.id, cr.user_id FROM conventionregistrations cr
             JOIN users school ON school.id = cr.user_id
             JOIN conventionregistrationteachers t ON t.conventionregistration_id = cr.id
             WHERE school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1
             GROUP BY cr.id, cr.user_id HAVING COUNT(*) BETWEEN 1 AND 30 LIMIT 1"
        )->fetch('assoc');
        if (!$registration) {
            $this->markTestSkipped('An active school with registered supervisors is required.');
        }
        $this->session([
            'user_id' => (int)$registration['user_id'],
            'user_type' => 'School',
            'sess_selected_convention_registration_id' => (int)$registration['id'],
        ]);
        $expected = $db->execute(
            'SELECT slug FROM conventionregistrationteachers WHERE conventionregistration_id = ?',
            [$registration['id']]
        )->fetchAll('assoc');
        foreach ([false, true] as $ajax) {
            $this->configRequest(['headers' => ['X-Requested-With' => $ajax ? 'XMLHttpRequest' : '']]);
            $this->get('/conventionregistrations/teachers');
            $this->assertResponseOk();
            $html = (string)$this->_response->getBody();
            $this->assertSame(count($expected), substr_count($html, 'data-title="Supervisor"'));
            foreach ($expected as $row) {
                $this->assertResponseContains('/removeteacher/' . $row['slug']);
            }
        }
        $otherId = $db->execute(
            'SELECT id FROM conventionregistrations WHERE user_id <> ? LIMIT 1',
            [$registration['user_id']]
        )->fetchColumn(0);
        foreach ([0, (int)$otherId] as $invalidId) {
            $this->session(['sess_selected_convention_registration_id' => $invalidId]);
            $this->get('/conventionregistrations/teachers');
            $this->assertRedirectContains('/users/dashboard');
        }
    }
}
