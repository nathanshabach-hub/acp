<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\SupervisorAssignmentPolicy;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use DomainException;

final class SupervisorAssignmentPolicyTest extends TestCase
{
    public function testMatchingGenderAndTenStudentBoundary(): void
    {
        $db = ConnectionManager::get('default');
        $supervisor = $db->execute(
            "SELECT t.teacher_id, t.convention_id, t.season_id, t.season_year, u.gender
             FROM conventionregistrationteachers t JOIN users u ON u.id = t.teacher_id
             WHERE t.status = 1 AND u.gender IN ('Male', 'Female') AND NOT EXISTS
             (SELECT 1 FROM conventionregistrationstudents s WHERE s.teacher_parent_id = t.teacher_id
              AND s.convention_id = t.convention_id AND s.season_id = t.season_id
              AND s.season_year = t.season_year AND s.status = 1) LIMIT 1"
        )->fetch('assoc');
        if (!$supervisor) {
            $this->markTestSkipped('An unassigned registered supervisor is required.');
        }
        $matching = $db->execute(
            "SELECT id FROM users WHERE user_type = 'Student' AND gender = ? LIMIT 11", [$supervisor['gender']]
        )->fetchAll('assoc');
        $opposite = $db->execute(
            "SELECT id FROM users WHERE user_type = 'Student' AND gender = ? LIMIT 1",
            [$supervisor['gender'] === 'Male' ? 'Female' : 'Male']
        )->fetchColumn(0);
        if (count($matching) < 11 || !$opposite) {
            $this->markTestSkipped('Students of both genders are required.');
        }
        $ids = array_map('intval', array_column($matching, 'id'));
        $policy = new SupervisorAssignmentPolicy($db);
        $args = [(int)$supervisor['teacher_id'], (int)$supervisor['convention_id'], (int)$supervisor['season_id'], (int)$supervisor['season_year']];
        $called = false;
        $policy->assign(...[...$args, array_slice($ids, 0, 10), [], function () use (&$called) { $called = true; }]);
        $this->assertTrue($called);
        foreach ([$ids, [(int)$opposite]] as $invalid) {
            $called = false;
            try {
                $policy->assign(...[...$args, $invalid, [], function () use (&$called) { $called = true; }]);
                $this->fail('Invalid assignment was accepted.');
            } catch (DomainException $error) {
                $this->assertFalse($called);
            }
        }
    }
}
