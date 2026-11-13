<?php
declare(strict_types=1);

namespace App\Test\TestCase\Scheduling;

use App\Controller\Admin\SchedulingtimingsController;
use Cake\Database\ValueBinder;
use Cake\Datasource\ConnectionManager;
use PHPUnit\Framework\TestCase;

class SchedulingtimingsSecurityTest extends TestCase
{
    public function testCsvEventMatchingPreservesAllPositionsAndSeasonScope(): void
    {
        $db = ConnectionManager::get('default');
        $this->assertContains($db->config()['host'], ['127.0.0.1', 'localhost']);
        $controller = (new \ReflectionClass(SchedulingtimingsController::class))->newInstanceWithoutConstructor();
        $query = $db->selectQuery()->where(['Entries.season_id' => 26]);
        $query = (function ($query) {
            return $this->whereCsvContainsEventId($query, 'Entries.event_ids', 12);
        })->call($controller, $query);
        $binder = new ValueBinder();
        $condition = $query->clause('where')->sql($binder);
        $params = [];
        foreach ($binder->bindings() as $binding) {
            $params[$binding['placeholder']] = $binding['value'];
        }

        foreach ([
            ['12', 26, 1],
            ['12,34', 26, 1],
            ['34,12,56', 26, 1],
            ['34,12', 26, 1],
            ['112', 26, 0],
            ['1,123,2', 26, 0],
            ['', 26, 0],
            [null, 26, 0],
            ['12 OR 1=1', 26, 0],
            ["12' OR '1'='1", 26, 0],
            ['12', 25, 0],
            ['12,34', 25, 0],
            ['34,12,56', 25, 0],
            ['34,12', 25, 0],
        ] as [$csv, $season, $expected]) {
            $actual = $db->execute(
                'SELECT CASE WHEN '.$condition.' THEN 1 ELSE 0 END AS matched'
                    .' FROM (SELECT :csv AS event_ids, :season AS season_id) Entries',
                $params + ['csv' => $csv, 'season' => $season]
            )->fetchColumn(0);
            $this->assertSame($expected, (int)$actual, json_encode([$csv, $season]));
        }
    }
}
