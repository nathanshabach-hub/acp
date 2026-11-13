<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\StudentPerformanceReportService;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Locator\LocatorInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use PHPUnit\Framework\TestCase;

final class StudentPerformanceReportServiceTest extends TestCase
{
    private function assemble(array $history, array $entries, array $positions): array
    {
        $service = new StudentPerformanceReportService(new TableLocator());
        return (new \ReflectionMethod($service, 'assemble'))->invoke(
            $service, ['id' => 1, 'first_name' => 'Sample', 'middle_name' => '', 'last_name' => 'Student'],
            $history, $entries, $positions
        );
    }

    private function history(int $id = 7, int $release = 1): array
    {
        return ['conventionseason_id' => $id, 'name' => 'Convention', 'year' => 2024, 'results_release' => $release];
    }

    private function entry(int $event = 10, int $season = 7, int $student = 1): array
    {
        return ['conventionseason_id' => $season, 'event_id' => $event, 'student_id' => $student];
    }

    private function position(?int $place = 1, int $event = 10, int $season = 7, string $name = 'Music'): array
    {
        return ['conventionseason_id' => $season, 'event_id' => $event, 'position' => $place, 'event_name' => $name];
    }

    public function testDistinctConventionsAndEventsUseStableStudentIdentity(): void
    {
        $result = $this->assemble(
            [$this->history(), $this->history(), $this->history(21)],
            [$this->entry(), $this->entry(), $this->entry(10, 21), $this->entry(11), $this->entry(99, 7, 2)],
            []
        );
        $this->assertSame(2, $result['totals']['conventions']);
        $this->assertSame(3, $result['totals']['events']);
    }

    public function testDuplicateFinalAwardsAreCollapsedAndAllSixPlacesCount(): void
    {
        $entries = $positions = [];
        for ($i = 1; $i <= 6; $i++) {
            $entries[] = $this->entry($i);
            $positions[] = $this->position($i, $i);
            $positions[] = $this->position($i, $i);
        }
        $result = $this->assemble([$this->history()], $entries, $positions);
        $this->assertSame(array_fill(1, 6, 1), $result['totals']['placements']);
        $this->assertCount(6, $result['conventions'][0]['awards']);
    }

    public function testConflictingAndUnsetPositionsAreFlaggedNotGuessed(): void
    {
        $result = $this->assemble([$this->history()], [$this->entry(), $this->entry(11)], [
            $this->position(1), $this->position(2), $this->position(3, 11), $this->position(null, 11),
        ]);
        $this->assertSame(2, $result['totals']['conflicts']);
        $this->assertSame(array_fill(1, 6, 0), $result['totals']['placements']);
        $this->assertSame([], $result['conventions'][0]['awards']);
    }

    public function testUnpublishedPlacementsNeverReachResponse(): void
    {
        $result = $this->assemble([$this->history(7, 0)], [$this->entry()], [
            $this->position(1, 10, 7, 'SECRET AWARD'),
        ]);
        $this->assertSame(1, $result['totals']['events']);
        $this->assertNull($result['conventions'][0]['placements']);
        $this->assertSame([], $result['conventions'][0]['awards']);
        $this->assertStringNotContainsString('SECRET', json_encode($result));
        $this->assertSame(array_fill(1, 6, 0), $result['totals']['placements']);
    }

    public function testWithdrawalAndMissingPublicationFlagFailClosed(): void
    {
        $released = $this->assemble([$this->history()], [$this->entry()], [$this->position()]);
        $this->assertSame(1, $released['totals']['placements'][1]);
        foreach ([0, null] as $flag) {
            $history = $this->history();
            $history['results_release'] = $flag;
            $hidden = $this->assemble([$history], [$this->entry()], [$this->position()]);
            $this->assertNull($hidden['conventions'][0]['placements']);
            $this->assertSame(0, $hidden['totals']['placements'][1]);
        }
    }

    public function testPublishedAutomaticAwardsAreIdentifiedWithoutScoreCalculations(): void
    {
        $result = $this->assemble([$this->history()], [$this->entry()], [
            $this->position(1, 10, 7, 'Golden Apple Award U16'),
        ]);
        $this->assertSame(1, $result['totals']['automatic_awards']);
        $this->assertTrue($result['conventions'][0]['awards'][0]['automatic']);
        $missingEntry = $this->assemble([$this->history()], [], [
            $this->position(2, 10, 7, 'Silver Apple Award OPEN'),
        ]);
        $this->assertSame(1, $missingEntry['totals']['placements'][2]);
        $this->assertSame(1, $missingEntry['totals']['automatic_awards']);
        $this->assertSame(0, $missingEntry['totals']['events']);
    }

    public function testSavedIndividualAwardsDoNotRequireAnEventEntryButDoRequireOwnedHistory(): void
    {
        $result = $this->assemble([$this->history()], [$this->entry()], [
            $this->position(1, 11), $this->position(1, 10, 21),
        ]);
        $this->assertSame(1, $result['totals']['placements'][1]);
        $this->assertSame(1, $result['totals']['awards_without_entries']);
        $this->assertSame(1, $result['totals']['events']);
    }

    public function testIdentifiersRejectMalformedAndInjectionInputs(): void
    {
        foreach (['1 OR 1=1', '1,2', '-1', '0', '1.0', [], '999999999999999999999999', ' 1'] as $value) {
            try {
                StudentPerformanceReportService::positiveId($value);
                $this->fail('Invalid identifier accepted.');
            } catch (BadRequestException $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
        $this->assertSame(12, StudentPerformanceReportService::positiveId('12'));
    }

    private function queryReturning(array $rows, ?callable $checkWhere = null): SelectQuery
    {
        $query = $this->createStub(SelectQuery::class);
        foreach (['select', 'innerJoin', 'enableHydration'] as $method) {
            $query->method($method)->willReturnSelf();
        }
        $query->method('where')->willReturnCallback(static function ($where) use ($checkWhere, $query) {
            if ($checkWhere) {
                $checkWhere($where);
            }
            return $query;
        });
        $query->method('all')->willReturn(new ResultSet($rows));
        return $query;
    }

    public function testCombinedSchoolRequiresExactApprovalAndStableMembershipAtBothSchools(): void
    {
        foreach ([
            ['approval' => 'Sample Student', 'candidates' => [1], 'host' => true, 'expected' => 1],
            ['approval' => ' sample   STUDENT ', 'candidates' => [1], 'host' => true, 'expected' => 1],
            ['approval' => 'Sample', 'candidates' => [1], 'host' => true, 'expected' => 0],
            ['approval' => 'Sample Student', 'candidates' => [1, 2], 'host' => true, 'expected' => 0],
            ['approval' => 'Sample Student', 'candidates' => [1], 'host' => false, 'expected' => 0],
        ] as $scenario) {
            $request = $this->queryReturning([[
                'student_name' => $scenario['approval'], 'combine_with_user_id' => 99,
                'conventionseason_id' => 7, 'event_id' => 10, 'conventionregistration_id' => 55,
            ]], function ($where) {
                $this->assertSame(['Combinerequests.user_id' => 5, 'Combinerequests.status' => 1,
                    'Combinerequests.conventionseason_id IN' => [7]], $where);
            });
            $requests = $this->createStub(Table::class);
            $requests->method('find')->willReturn($request);
            $candidateRows = array_map(static fn($id) => [
                'student_id' => $id, 'first_name' => 'Sample', 'middle_name' => '', 'last_name' => 'Student',
            ], $scenario['candidates']);
            $candidates = $this->queryReturning($candidateRows);
            $host = $this->queryReturning($scenario['host'] ? [['conventionregistration_id' => 88]] : [], function ($conditions) {
                $this->assertSame(99, $conditions['Crstudentevents.user_id']);
                $this->assertSame(1, $conditions['Crstudentevents.student_id']);
                $this->assertSame('Group 1', $conditions['Crstudentevents.group_name']);
            });
            $events = $this->createStub(Table::class);
            $events->method('find')->willReturnOnConsecutiveCalls($candidates, $host);
            $locator = $this->createStub(LocatorInterface::class);
            $locator->method('get')->willReturnMap([
                ['Combinerequests', [], $requests], ['Crstudentevents', [], $events],
            ]);
            $service = new StudentPerformanceReportService($locator);
            $targets = (new \ReflectionMethod($service, 'combinedTargets'))->invoke(
                $service, 5, ['id' => 1, 'first_name' => 'Sample', 'middle_name' => '', 'last_name' => 'Student'],
                [7], [$this->entry() + ['conventionregistration_id' => 55, 'group_name' => 'Group 1']]
            );
            $this->assertCount($scenario['expected'], $targets);
            if ($targets) {
                $this->assertSame(88, $targets[0]['Resultpositions.conventionregistration_id']);
                $this->assertSame(99, $targets[0]['Resultpositions.user_id']);
            }
        }
    }

    public function testGroupResultQueryRejectsBlankAndOtherGroupNames(): void
    {
        $positions = $this->queryReturning([], function ($where) {
            $target = $where['OR'][1];
            $this->assertSame(5, $target['Resultpositions.user_id']);
            $this->assertSame(55, $target['Resultpositions.conventionregistration_id']);
            $this->assertSame('Group 1', $target['OR'][1]['Resultpositions.group_name']);
            $this->assertSame('', $target['OR'][1]['Resultpositions.group_name !=']);
        });
        $resultTable = $this->createStub(Table::class);
        $resultTable->method('find')->willReturn($positions);
        $requestTable = $this->createStub(Table::class);
        $requestTable->method('find')->willReturn($this->queryReturning([]));
        $registrationTable = $this->createStub(Table::class);
        $registrationTable->method('find')->willReturn($this->queryReturning([]));
        $locator = $this->createStub(LocatorInterface::class);
        $locator->method('get')->willReturnMap([
            ['Resultpositions', [], $resultTable], ['Combinerequests', [], $requestTable],
            ['Conventionregistrationstudents', [], $registrationTable],
        ]);
        $service = new StudentPerformanceReportService($locator);
        $result = (new \ReflectionMethod($service, 'positions'))->invoke(
            $service, 5, ['id' => 1, 'first_name' => 'Sample', 'middle_name' => '', 'last_name' => 'Student'], [7],
            [$this->entry() + ['conventionregistration_id' => 55, 'group_name' => 'Group 1']]
        );
        $this->assertSame([], $result);
    }

    public function testLocalOwnedHistoryAndPrivacyUsingSelectOnlyQueries(): void
    {
        $db = ConnectionManager::get('default');
        $this->assertContains($db->config()['host'], ['127.0.0.1', 'localhost']);
        $service = new StudentPerformanceReportService(new TableLocator());
        $result = $service->preview(7991, 8214);
        $this->assertSame(3, $result['totals']['conventions']);
        $this->assertSame(38, $result['totals']['events']);
        foreach ($result['conventions'] as $convention) {
            if (!$convention['released']) {
                $this->assertNull($convention['placements']);
                $this->assertSame([], $convention['awards']);
            }
        }
        $list = $service->students(7991, '8214');
        $this->assertSame(1, $list['total']);
        $this->assertSame(38, $list['students'][0]['events']);
        $nameSearch = $service->students(7991, $result['student']['first_name'] . ' ' . $result['student']['last_name']);
        $this->assertContains(8214, array_map('intval', array_column($nameSearch['students'], 'id')));
        $this->assertSame(0, $service->students(7991, "' OR 1=1 --")['total']);
        $this->assertSame(0, $service->students(7991, '%')['total']);
        $this->expectException(NotFoundException::class);
        $service->preview(5806, 8214);
    }

    public function testServiceExecutesOnlySelectQueries(): void
    {
        $driver = ConnectionManager::get('default')->getDriver();
        $previous = $driver->getLogger();
        $wasEnabled = (bool)(new \ReflectionProperty($driver, 'logQueries'))->getValue($driver);
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $sql = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['query'])) {
                    $this->sql[] = $context['query']->getContext()['query'];
                }
            }
        };
        $service = new StudentPerformanceReportService(new TableLocator());
        $service->students(7991, '8214');
        $driver->setLogger($logger);
        $driver->enableQueryLogging();
        try {
            $service->students(7991);
            $service->preview(7991, 8214);
            $this->assertNotEmpty($logger->sql);
            foreach ($logger->sql as $sql) {
                $this->assertMatchesRegularExpression('/^SELECT\\b/i', ltrim($sql));
            }
        } finally {
            $driver->setLogger($previous ?? new \Psr\Log\NullLogger());
            if (!$wasEnabled) {
                $driver->disableQueryLogging();
            }
        }
    }
}
