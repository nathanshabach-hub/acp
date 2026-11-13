<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DOMDocument;
use DOMXPath;

final class StudentPickerTest extends TestCase
{
    use IntegrationTestTrait;

    public function testAddStudentShowsSearchableCheckboxesForUnregisteredStudents(): void
    {
        $connection = ConnectionManager::get('default');
        $registration = $connection->execute(
            "SELECT cr.id, cr.user_id, cr.season_year FROM conventionregistrations cr
             JOIN conventionseasons cs ON cs.id = cr.conventionseason_id
             JOIN users school ON school.id = cr.user_id
             JOIN users student ON student.school_id = school.id AND student.user_type = 'Student'
             WHERE school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1
               AND student.status <> 2 AND NOW() BETWEEN cs.registration_start_date AND cs.registration_end_date
               AND NOT EXISTS (SELECT 1 FROM conventionregistrationstudents s
                   WHERE s.conventionregistration_id = cr.id AND s.student_id = student.id)
             LIMIT 1"
        )->fetch('assoc');
        if (!$registration) {
            $this->markTestSkipped('An open school registration with an unregistered student is required.');
        }

        $expected = $connection->execute(
            "SELECT u.id, u.birth_year, u.gender FROM users u WHERE u.school_id = ? AND u.user_type = 'Student' AND u.status <> 2
             AND NOT EXISTS (SELECT 1 FROM conventionregistrationstudents s
                 WHERE s.conventionregistration_id = ? AND s.student_id = u.id)",
            [$registration['user_id'], $registration['id']]
        )->fetchAll('assoc');
        $this->session([
            'user_id' => (int)$registration['user_id'],
            'user_type' => 'School',
            'sess_selected_convention_registration_id' => (int)$registration['id'],
        ]);
        $this->get('/conventionregistrations/addstudent');
        $this->assertResponseOk();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML((string)$this->_response->getBody());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $this->assertCount(1, $xpath->query('//input[@id="student_search" and @type="search"]'));
        $this->assertCount(1, $xpath->query('//input[@id="cross_school_preview" and @type="checkbox"]'));
        $this->assertCount(1, $xpath->query('//select[@id="cross_school"]'));
        $this->assertCount(1, $xpath->query('//select[@id="cross_supervisor"]'));
        $this->assertResponseContains('Pending request');
        $this->assertResponseContains('capacity is not reserved');
        $checkboxes = $xpath->query('//input[@type="checkbox" and @name="Conventionregistrationstudents[student_ids][]"]');
        $actualIds = [];
        foreach ($checkboxes as $checkbox) {
            $actualIds[] = (int)$checkbox->getAttribute('value');
        }
        foreach ($expected as $student) {
            $birthYear = (int)$student['birth_year'];
            $seasonYear = (int)$registration['season_year'];
            $age = $birthYear > 0 && $seasonYear > 0 && $birthYear <= $seasonYear
                ? (string)($seasonYear - $birthYear) : 'not available';
            $label = $xpath->query('//input[@type="checkbox" and @value="' . (int)$student['id'] . '"]/following-sibling::span')->item(0);
            $this->assertNotNull($label);
            $this->assertStringContainsString(($student['gender'] ?: 'Gender not set') . ', Age: ' . $age, $label->textContent);
        }
        $expectedIds = array_map('intval', array_column($expected, 'id'));
        sort($actualIds);
        sort($expectedIds);
        $this->assertSame($expectedIds, $actualIds);
    }

    public function testCrossSchoolRequestRejectsOwnSchoolAsSponsor(): void
    {
        $connection = ConnectionManager::get('default');
        $registration = $connection->execute(
            "SELECT cr.id, cr.user_id FROM conventionregistrations cr
             JOIN conventionseasons cs ON cs.id = cr.conventionseason_id
             JOIN users school ON school.id = cr.user_id
             WHERE school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1 AND cr.status = 1
               AND NOW() BETWEEN cs.registration_start_date AND cs.registration_end_date LIMIT 1"
        )->fetch('assoc');
        if (!$registration) {
            $this->markTestSkipped('An open school registration is required.');
        }
        $before = (int)$connection->execute(
            'SELECT COUNT(*) FROM conventionregistrationstudents WHERE conventionregistration_id = ?', [$registration['id']]
        )->fetchColumn(0);
        $this->session([
            'user_id' => (int)$registration['user_id'],
            'user_type' => 'School',
            'sess_selected_convention_registration_id' => (int)$registration['id'],
        ]);
        $this->enableCsrfToken();
        $this->post('/conventionregistrations/addstudent', [
            'cross_school_preview' => '1',
            'cross_school_id' => $registration['user_id'],
            'cross_supervisor_id' => '1',
            'Conventionregistrationstudents' => ['student_ids' => ['1'], 'teacher_parent_id' => '1'],
        ]);
        $this->assertRedirectContains('/conventionregistrations/addstudent');
        $after = (int)$connection->execute(
            'SELECT COUNT(*) FROM conventionregistrationstudents WHERE conventionregistration_id = ?', [$registration['id']]
        )->fetchColumn(0);
        $this->assertSame($before, $after);
    }

    public function testReceivingSchoolDoesNotSeeStudentNameInPendingRequest(): void
    {
        $connection = ConnectionManager::get('default');
        if (!(new \App\Service\CrossSchoolSponsorship($connection))->available()) {
            $this->markTestSkipped('Cross-school migration is required.');
        }
        $request = $connection->execute(
            "SELECT r.id, r.sponsor_school_id FROM cross_school_sponsorship_requests r
             JOIN conventionregistrationstudents s ON s.id = r.student_registration_id
             JOIN users school ON school.id = r.sponsor_school_id
             WHERE r.status = 'pending_school' AND s.status = 1 AND s.teacher_parent_id IS NULL
               AND school.user_type = 'School' AND school.status = 1 AND school.activation_status = 1 LIMIT 1"
        )->fetch('assoc');
        if (!$request) {
            $this->markTestSkipped('A pending cross-school request is required.');
        }
        $this->session(['user_id' => (int)$request['sponsor_school_id'], 'user_type' => 'School']);
        $this->get('/users/crossschoolrequests');
        $this->assertResponseOk();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML((string)$this->_response->getBody());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $card = $xpath->query('//section[@data-request-id="' . (int)$request['id'] . '"]')->item(0);
        $this->assertNotNull($card);
        $this->assertStringContainsString('Student details will be available', $card->textContent);
        $this->assertStringNotContainsString('Student:', $card->textContent);
        $this->assertStringNotContainsString('Cancel request', $card->textContent);
    }

    public function testCancellationRequiresCsrfToken(): void
    {
        $this->post('/users/cancelcrossschoolrequest/1');
        $this->assertResponseCode(403);
    }
}
