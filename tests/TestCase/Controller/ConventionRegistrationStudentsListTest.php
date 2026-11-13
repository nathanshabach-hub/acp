<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DOMDocument;
use DOMXPath;

final class ConventionRegistrationStudentsListTest extends TestCase
{
    use IntegrationTestTrait;

    public function testNamesAreShownOnlyForTheSelectedRegistration(): void
    {
        $connection = ConnectionManager::get('default');
        $registration = $connection->execute(
            "SELECT cr.id, cr.user_id FROM conventionregistrations cr
             INNER JOIN users school ON school.id = cr.user_id
             INNER JOIN conventionregistrationstudents cs ON cs.conventionregistration_id = cr.id
             INNER JOIN users student ON student.id = cs.student_id
             WHERE school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1
             GROUP BY cr.id, cr.user_id
             HAVING COUNT(*) >= 1 AND
               (SELECT COUNT(*) FROM conventionregistrationstudents all_students
                WHERE all_students.conventionregistration_id = cr.id) <= 50 LIMIT 1"
        )->fetch('assoc');
        if (!$registration) {
            $this->markTestSkipped('An active school with a registration containing students is required.');
        }
        $expected = $connection->execute(
            "SELECT u.first_name, u.middle_name, u.last_name
             FROM conventionregistrationstudents cs LEFT JOIN users u ON u.id = cs.student_id
             WHERE cs.conventionregistration_id = ?",
            [$registration['id']]
        )->fetchAll('assoc');
        $expectedNames = array_map(static function (array $row): string {
            $name = trim(implode(' ', array_filter(
                [$row['first_name'], $row['middle_name'], $row['last_name']],
                static fn($part) => $part !== null && $part !== ''
            )));

            return $name === '' ? 'Student not found' : $name;
        }, $expected);
        sort($expectedNames);

        $this->session([
            'user_id' => (int)$registration['user_id'],
            'user_type' => 'School',
            'sess_selected_convention_registration_id' => (int)$registration['id'],
        ]);
        foreach ([false, true] as $ajax) {
            if ($ajax) {
                $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);
            }
            $this->get('/conventionregistrations/students');
            $this->assertResponseOk();
            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML((string)$this->_response->getBody());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new DOMXPath($document);
            $names = [];
            foreach ($xpath->query('//td[@data-title="Student"]') as $cell) {
                $names[] = trim($cell->textContent);
            }
            sort($names);
            $this->assertSame($expectedNames, $names);
        }
    }
}
