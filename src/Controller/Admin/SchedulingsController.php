<?php

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Controller\Traits\ScheduleProgressRedirectTrait;
use App\Scheduling\ConflictRepairService;
use App\Scheduling\DayStrategy;
use App\Scheduling\ScheduleRepairStore;
use App\Scheduling\TimetableValidator;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;

#[\AllowDynamicProperties]
class SchedulingsController extends AppController {
	use ScheduleProgressRedirectTrait;

    public array $paginate = ['limit' => 50, 'order' => ['Schedulings.name' => 'asc']];
	public $Schedulings = null;
	public $Conventionseasons = null;
	public $Conventions = null;
	public $Conventionseasonevents = null;
	public $Conventionrooms = null;
	public $Conventionseasonroomevents = null;
	public $Conventionregistrations = null;
	public $Conventionregistrationstudents = null;
	public $Events = null;
	public $Schedulingtimings = null;
	public $Eventsubmissions = null;

    //public $helpers = array('Javascript', 'Ajax');

    public function initialize(): void {
        parent::initialize();
		$this->loadComponent('RequestHandler');
		$this->loadComponent('PImage');
		$this->loadComponent('PImageTest');
        $this->loadComponent('Paginator');
        $this->loadComponent('Flash');

		$this->loadModel("Schedulings");
		$this->loadModel("Conventionseasons");
		$this->loadModel("Conventions");
		$this->loadModel("Conventionseasonevents");
		$this->loadModel("Conventionrooms");
		$this->loadModel("Conventionseasonroomevents");
		$this->loadModel("Conventionregistrations");
		$this->loadModel("Conventionregistrationstudents");
		$this->loadModel("Events");
		$this->loadModel("Schedulingtimings");
		$this->loadModel("Eventsubmissions");
    }

	public function beforeFilter(EventInterface $event): void
	{
		parent::beforeFilter($event);
		if ($event->isStopped()) {
			// AppController stopped the event (read-only admin) without a result; make the stop effective.
			$event->setResult($event->getResult() ?? $this->getResponse());
			return;
		}
	}

	private function getConventionMetaFromSeason($conventionSD)
	{
		$meta = ['slug' => '', 'name' => 'N/A'];
		if(empty($conventionSD) || empty($conventionSD->convention_id))
		{
			return $meta;
		}

		$convention = $this->Conventions->find()
			->select(['slug', 'name'])
			->where(['Conventions.id' => $conventionSD->convention_id])
			->first();

		if(!empty($convention))
		{
			$meta['slug'] = !empty($convention->slug) ? $convention->slug : '';
			$meta['name'] = !empty($convention->name) ? $convention->name : 'N/A';
		}

		// Keep legacy template access working: $conventionSD->Conventions['name']
		$conventionSD->Conventions = [
			'slug' => $meta['slug'],
			'name' => $meta['name'],
		];

		return $meta;
	}

	private function getLatestSchedulingRecord($conventionSeasonId, $conventionId, $seasonId, $seasonYear)
	{
		$records = $this->Schedulings->find()
			->where(['Schedulings.conventionseasons_id' => $conventionSeasonId,
				'Schedulings.convention_id' => $conventionId,
				'Schedulings.season_id' => $seasonId,
				'Schedulings.season_year' => $seasonYear])
			->order(['Schedulings.id' => 'DESC'])
			->all();

		$toHms = function ($value) {
			if ($value === null || $value === '') {
				return '';
			}

			if ($value instanceof \DateTimeInterface) {
				return $value->format('H:i:s');
			}

			$timestamp = strtotime((string)$value);
			if ($timestamp === false) {
				return trim((string)$value);
			}

			return date('H:i:s', $timestamp);
		};

		$looksLikeDefaultTime = function ($value) use ($toHms) {
			return in_array($toHms($value), ['10:00:00', '10:00'], true);
		};

		$wizardFields = [
			'start_date',
			'first_day',
			'number_of_days',
			'normal_starting_time',
			'normal_finish_time',
			'lunch_time_start',
			'lunch_time_end',
			'different_first_day_start_time',
			'different_first_day_end_time',
			'judging_breaks_morning_break_starting_time',
			'judging_breaks_morning_break_finish_time',
			'judging_breaks_afternoon_break_start_time',
			'judging_breaks_afternoon_break_finish_time',
			'sports_day',
			'sports_day_starting_time',
			'sports_day_finish_time',
			'sports_day_other_starting_time',
			'sports_day_other_finish_time',
		];

		$getRecordCompletenessScore = function ($record) use ($wizardFields, $toHms) {
			$score = 0;
			foreach ($wizardFields as $field) {
				if (!isset($record->{$field}) || $record->{$field} === null || $record->{$field} === '') {
					continue;
				}

				if (strpos($field, 'time') !== false) {
					if ($toHms($record->{$field}) !== '') {
						$score++;
					}
					continue;
				}

				$score++;
			}

			return $score;
		};

		$latest = null;
		$bestPopulated = null;
		$bestScore = -1;
		foreach ($records as $record) {
			if ($latest === null) {
				$latest = $record;
			}

			$score = $getRecordCompletenessScore($record);
			if ($score > $bestScore) {
				$bestScore = $score;
				$bestPopulated = $record;
			}

			$coreTimesAreDefault =
				$looksLikeDefaultTime($record->normal_starting_time) &&
				$looksLikeDefaultTime($record->normal_finish_time) &&
				$looksLikeDefaultTime($record->lunch_time_start) &&
				$looksLikeDefaultTime($record->lunch_time_end);

			$coreTimesAreEmpty =
				empty($toHms($record->normal_starting_time)) &&
				empty($toHms($record->normal_finish_time)) &&
				empty($toHms($record->lunch_time_start)) &&
				empty($toHms($record->lunch_time_end));

			if (!$coreTimesAreDefault && !$coreTimesAreEmpty) {
				if ($latest !== null && $latest->id !== $record->id) {
					$mergePayload = [];
					foreach ($wizardFields as $field) {
						if (isset($record->{$field}) && $record->{$field} !== null && $record->{$field} !== '') {
							$mergePayload[$field] = $record->{$field};
						}
					}
					if (!empty($mergePayload)) {
						$mergePayload['modified'] = date('Y-m-d H:i:s');
						$this->Schedulings->updateAll($mergePayload, ['id' => $latest->id]);
						return $this->Schedulings->find()->where(['Schedulings.id' => $latest->id])->first();
					}
				}

				return $record;
			}
		}

		if ($bestPopulated !== null && $bestScore > 0) {
			if ($latest !== null && $latest->id !== $bestPopulated->id) {
				$mergePayload = [];
				foreach ($wizardFields as $field) {
					if (isset($bestPopulated->{$field}) && $bestPopulated->{$field} !== null && $bestPopulated->{$field} !== '') {
						$mergePayload[$field] = $bestPopulated->{$field};
					}
				}
				if (!empty($mergePayload)) {
					$mergePayload['modified'] = date('Y-m-d H:i:s');
					$this->Schedulings->updateAll($mergePayload, ['id' => $latest->id]);
					return $this->Schedulings->find()->where(['Schedulings.id' => $latest->id])->first();
				}
			}

			return $bestPopulated;
		}

		return $latest;
	}

	private function syncSchedulingRecordDuplicates($savedRecord, array $updatePayload)
	{
		if (empty($savedRecord) || empty($savedRecord->id)) {
			return;
		}

		$this->Schedulings->updateAll(
			$updatePayload,
			[
				'Schedulings.conventionseasons_id' => $savedRecord->conventionseasons_id,
				'Schedulings.convention_id' => $savedRecord->convention_id,
				'Schedulings.season_id' => $savedRecord->season_id,
				'Schedulings.season_year' => $savedRecord->season_year,
				'Schedulings.id !=' => $savedRecord->id,
			]
		);
	}

    public function precheck($convention_season_slug=null) {
        $this->set('title', ADMIN_TITLE . 'Scheduling Pre-check');
        $this->viewBuilder()->setLayout('admin');
		
        $this->set('manageConventions', '1');
        $this->set('conventionList', '1');
		
        $this->set('convention_season_slug', $convention_season_slug);
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		//$this->prx($conventionSD);
		
		$this->set('conventionSD', $conventionSD);
		
		$conventionMeta = $this->getConventionMetaFromSeason($conventionSD);
		$this->set('convention_slug', $conventionMeta['slug']);
		
		// to check that if record for this conv season entered in scheduling table..
		// ... if not entered, then entered
		$checkSchedulingRecord = $this->getLatestSchedulingRecord($conventionSD->id, $conventionSD->convention_id, $conventionSD->season_id, $conventionSD->season_year);
		if(!$checkSchedulingRecord)
		{
			// enter new record
			$schedulings = $this->Schedulings->newEntity([]);
			$dataSch = $this->Schedulings->patchEntity($schedulings, array());

			$dataSch->slug 						= "scheduling-conv-season-".$conventionSD->id.'-'.time();
			$dataSch->conventionseasons_id		= $conventionSD->id;
			$dataSch->convention_id				= $conventionSD->convention_id;
			$dataSch->season_id					= $conventionSD->season_id;
			$dataSch->season_year 				= $conventionSD->season_year;
			$dataSch->number_of_days				= 4;
			$dataSch->normal_starting_time		= null;
			$dataSch->normal_finish_time			= null;
			$dataSch->lunch_time_start				= null;
			$dataSch->lunch_time_end				= null;
			$dataSch->starting_different_time_first_day_yes_no = 0;
			$dataSch->judging_breaks_yes_no		= 0;
			$dataSch->sports_day_yes_no				= 0;
			$dataSch->sports_day_having_events_after_sport_yes_no = 0;
			if (in_array('round_robin_day_distribution_yes_no', (array)$this->Schedulings->getSchema()->columns(), true)) {
				$dataSch->round_robin_day_distribution_yes_no = 0;
			}
			
			$dataSch->created 					= date('Y-m-d H:i:s');

			$resultSch = $this->Schedulings->save($dataSch);
		}
		
		// to fetch scheduling data and send to template
		$schedulingD = $this->getLatestSchedulingRecord($conventionSD->id, $conventionSD->convention_id, $conventionSD->season_id, $conventionSD->season_year);
		if (!$schedulingD) {
			$schedulingD = $this->Schedulings->newEntity([
				'precheck_events' => 0,
				'total_events_found' => null,
				'precheck_locations' => 0,
				'total_locations_found' => null,
				'precheck_registrations' => 0,
				'total_registrations_found' => null,
				'precheck_students' => 0,
				'total_students_found' => null,
			]);
		}
		$this->set('schedulingD', $schedulingD);
		$this->set('schedulings', $schedulingD);
		
    }
	
	public function precheckevents($convention_season_slug=null) {
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		
		
		// to check events for this convention season
		$cntrPreCheckEvents = 0;
		$conventionSEventsList = $this->Conventionseasonevents->find()->where(['Conventionseasonevents.conventionseasons_id' => $conventionSD->id])->contain(['Events'])->all();
		foreach($conventionSEventsList as $convevPreCheck)
		{
			if($convevPreCheck->Events['needs_schedule'] == 1)
			{
				$cntrPreCheckEvents++;
			}
		}
		
		
		//$this->prx($conventionSEvents);
		if($cntrPreCheckEvents>0)
		{
			// now update this precheck events in scheduling table
			$this->Schedulings->updateAll(['precheck_events' => 1,'total_events_found' => $cntrPreCheckEvents,'modified' => date('Y-m-d H:i:s')], ["conventionseasons_id" => $conventionSD->id]);
			
			$this->Flash->success('Total event found: '.$cntrPreCheckEvents);
		}
		else
		{
			$this->Schedulings->updateAll(['precheck_events' => 0,'total_events_found' => NULL,'modified' => date('Y-m-d H:i:s')], ["conventionseasons_id" => $conventionSD->id]);
			
			$this->Flash->error('Sorry no event found for this convention season.');
		}
		
		$this->redirect(['controller' => 'schedulings', 'action' => 'precheck',$convention_season_slug]);
    }
	
	public function prechecklocations($convention_season_slug=null) {
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		
		// to check location/rooms for this convention
		$conventionRoomsTotal = $this->Conventionrooms->find()->where(['Conventionrooms.convention_id' => $conventionSD->convention_id])->count();
		if($conventionRoomsTotal>0)
		{
			// to check events for this convention season
			$cntrConvSeasonTotalEvents = 0;
			$conventionSEventsList = $this->Conventionseasonevents->find()->where(['Conventionseasonevents.conventionseasons_id' => $conventionSD->id])->contain(['Events'])->all();
			foreach($conventionSEventsList as $convEv)
			{
				if($convEv->Events['needs_schedule'] == 1)
				{
					$cntrConvSeasonTotalEvents++;
				}
			}
			
			$roomEventsArr = array();
			
			// now get events that is assigned to a room
			$convRoomEvents = $this->Conventionseasonroomevents->find()->where(['Conventionseasonroomevents.conventionseasons_id' => $conventionSD->id])->all();
			foreach($convRoomEvents as $convroomev)
			{
				$roomEventIDSExplode = explode(",",$convroomev->event_ids);
				foreach($roomEventIDSExplode as $eventidexplode)
				{
					if(!in_array($eventidexplode,(array)$roomEventsArr))
					{
						$roomEventsArr[] = $eventidexplode;
					}
				}
			}
			
			if(count((array)$roomEventsArr) < $cntrConvSeasonTotalEvents)
			{
				$this->Flash->error('Sorry, '.($cntrConvSeasonTotalEvents-count((array)$roomEventsArr)).' event(s) not assigned to any room. Please assign.');
				
				$this->Schedulings->updateAll(['precheck_locations' => 0,'total_locations_found' => NULL,'modified' => date('Y-m-d H:i:s')], ["conventionseasons_id" => $conventionSD->id]);
			}
			else
			{
				$this->Schedulings->updateAll(['precheck_locations' => 1,'total_locations_found' => $conventionRoomsTotal,'modified' => date('Y-m-d H:i:s')], ["conventionseasons_id" => $conventionSD->id]);
			
				$this->Flash->success('Total locations found: '.$conventionRoomsTotal);
			}
		}
		else
		{
			$this->Flash->error('Sorry no location found for this convention.');
		}
		
		
		
		$this->redirect(['controller' => 'schedulings', 'action' => 'precheck',$convention_season_slug]);
    }
	
	public function precheckregistrations($convention_season_slug=null) {
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		
		// to check convention registrations
		$conventionRegCount = $this->Conventionregistrations->find()->where(['Conventionregistrations.conventionseason_id' => $conventionSD->id])->count();
		if($conventionRegCount>0)
		{
			$this->Schedulings->updateAll(['precheck_registrations' => 1,'total_registrations_found' => $conventionRegCount,'modified' => date('Y-m-d H:i:s')], ["conventionseasons_id" => $conventionSD->id]);
			
			$this->Flash->success('Total registrations found: '.$conventionRegCount);
		}
		else
		{
			$this->Schedulings->updateAll(['precheck_registrations' => 0,'total_registrations_found' => NULL,'modified' => date('Y-m-d H:i:s')], ["conventionseasons_id" => $conventionSD->id]);
			
			$this->Flash->error('Sorry no registration found for this convention.');
		}
		
		
		
		$this->redirect(['controller' => 'schedulings', 'action' => 'precheck',$convention_season_slug]);
    }
	
	public function precheckstudents($convention_season_slug=null) {
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		
		// to check convention registrations
		$studentsRegCount = $this->Conventionregistrationstudents->find()->where(['Conventionregistrationstudents.convention_id' => $conventionSD->convention_id,'Conventionregistrationstudents.season_id' => $conventionSD->season_id,'Conventionregistrationstudents.season_year' => $conventionSD->season_year])->count();
		if($studentsRegCount>0)
		{
			$this->Schedulings->updateAll(['precheck_students' => 1,'total_students_found' => $studentsRegCount,'modified' => date('Y-m-d H:i:s')], ["conventionseasons_id" => $conventionSD->id]);
			
			$this->Flash->success('Total students found: '.$studentsRegCount);
		}
		else
		{
			$this->Schedulings->updateAll(['precheck_students' => 0,'total_students_found' => NULL,'modified' => date('Y-m-d H:i:s')], ["conventionseasons_id" => $conventionSD->id]);
			
			$this->Flash->error('Sorry no stuednts found for this convention.');
		}
		
		$this->redirect(['controller' => 'schedulings', 'action' => 'precheck',$convention_season_slug]);
    }
	
	public function resetallprecheck($convention_season_slug=null) {
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		
		//$this->prx($conventionSEvents);
		if($conventionSD)
		{
			// now reset all precheck
			$this->Schedulings->updateAll(
			[
			'precheck_events' => 0,'total_events_found' => NULL,
			'precheck_locations' => 0,'total_locations_found' => NULL,
			'precheck_registrations' => 0,'total_registrations_found' => NULL,
			'precheck_students' => 0,'total_students_found' => NULL,
			'modified' => date('Y-m-d H:i:s')
			], 
			["conventionseasons_id" => $conventionSD->id]);
			
			$this->Flash->success('Reset all pre-check prcessed successfully.');
		}
		else
		{	
			$this->Flash->error('Invalid convention season.');
		}
		
		$this->redirect(['controller' => 'schedulings', 'action' => 'precheck',$convention_season_slug]);
    }
	
	public function wizard($convention_season_slug=null) {
        $this->set('title', ADMIN_TITLE . 'Scheduling Wizard');
        $this->viewBuilder()->setLayout('admin');
		
        $this->set('manageConventions', '1');
        $this->set('conventionList', '1');
		
        $this->set('convention_season_slug', $convention_season_slug);
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		
		$this->set('conventionSD', $conventionSD);
		$conventionMeta = $this->getConventionMetaFromSeason($conventionSD);
		$this->set('convention_slug', $conventionMeta['slug']);
		
		global $weekDays;
		$this->set('weekDays', $weekDays);
		$hasRoundRobinColumn = in_array('round_robin_day_distribution_yes_no', (array)$this->Schedulings->getSchema()->columns(), true);
		$this->set('hasRoundRobinColumn', $hasRoundRobinColumn);
		$hasStrategyColumn = in_array('schedule_band_mode', (array)$this->Schedulings->getSchema()->columns(), true);
		$this->set('hasStrategyColumn', $hasStrategyColumn);
		$hasSeedColumn = in_array('schedule_seed', (array)$this->Schedulings->getSchema()->columns(), true);
		$this->set('hasSeedColumn', $hasSeedColumn);
		$this->set('lastRunSeed', $this->readLastRunSeed((int)$conventionSD->id));
		$this->set('dayStrategyOptions', DayStrategy::LABELS);
		
		// to fetch scheduling data and send to template
		$schedulingD = $this->getLatestSchedulingRecord($conventionSD->id, $conventionSD->convention_id, $conventionSD->season_id, $conventionSD->season_year);
		$toHms = function ($value) {
			if ($value === null || $value === '') {
				return '';
			}

			if ($value instanceof \DateTimeInterface) {
				return $value->format('H:i:s');
			}

			$timestamp = strtotime((string)$value);
			if ($timestamp === false) {
				return trim((string)$value);
			}

			return date('H:i:s', $timestamp);
		};

		$looksLikeDefaultTime = function ($value) use ($toHms) {
			return in_array($toHms($value), ['10:00:00', '10:00'], true);
		};
		// Keep wizard values exactly as stored; blank rows should render blank inputs.
		if (!$schedulingD) {
			$schedulings = $this->Schedulings->newEntity([]);
			$schedulings->slug = 'scheduling-conv-season-' . $conventionSD->id . '-' . time();
			$schedulings->conventionseasons_id = $conventionSD->id;
			$schedulings->convention_id = $conventionSD->convention_id;
			$schedulings->season_id = $conventionSD->season_id;
			$schedulings->season_year = $conventionSD->season_year;
			$schedulings->starting_different_time_first_day_yes_no = 0;
			$schedulings->judging_breaks_yes_no = 0;
			$schedulings->sports_day_yes_no = 0;
			$schedulings->sports_day_having_events_after_sport_yes_no = 0;
			if ($hasRoundRobinColumn) {
				$schedulings->round_robin_day_distribution_yes_no = 0;
			}
			$schedulings->created = date('Y-m-d H:i:s');

			if ($this->Schedulings->save($schedulings)) {
				$schedulingD = $this->getLatestSchedulingRecord($conventionSD->id, $conventionSD->convention_id, $conventionSD->season_id, $conventionSD->season_year);
			}
		}
		$this->set('schedulingD', $schedulingD);

		$wizardTimeValues = [
			'normal_starting_time' => '',
			'normal_finish_time' => '',
			'lunch_time_start' => '',
			'lunch_time_end' => '',
			'different_first_day_start_time' => '',
			'different_first_day_end_time' => '',
			'judging_breaks_morning_break_starting_time' => '',
			'judging_breaks_morning_break_finish_time' => '',
			'judging_breaks_afternoon_break_start_time' => '',
			'judging_breaks_afternoon_break_finish_time' => '',
			'sports_day_starting_time' => '',
			'sports_day_finish_time' => '',
			'sports_day_other_starting_time' => '',
			'sports_day_other_finish_time' => '',
		];

		if (!empty($schedulingD) && !empty($schedulingD->id)) {
			$rawScheduling = $this->Schedulings->find()
				->select(array_keys($wizardTimeValues))
				->where(['Schedulings.id' => $schedulingD->id])
				->enableHydration(false)
				->first();

			$valueSource = is_array($rawScheduling) ? $rawScheduling : [];

			$formatTimeValue = function ($value) {
				if ($value === null || $value === '') {
					return '';
				}

				$timestamp = strtotime((string)$value);
				if ($timestamp === false) {
					return '';
				}

				return date('h:i A', $timestamp);
			};

			$coreTimeKeys = [
				'normal_starting_time',
				'normal_finish_time',
				'lunch_time_start',
				'lunch_time_end',
			];

			$hasAnyCoreTime = false;
			foreach ($coreTimeKeys as $coreKey) {
				if (!empty($valueSource[$coreKey])) {
					$hasAnyCoreTime = true;
					break;
				}
			}

			if (!$hasAnyCoreTime) {
				$nonEmptyCoreTimeSql = [];
				foreach ($coreTimeKeys as $key) {
					$nonEmptyCoreTimeSql[] = "(Schedulings.".$key." IS NOT NULL AND Schedulings.".$key." != '')";
				}

				$fallbackRow = $this->Schedulings->find()
					->select(array_merge(['id'], array_keys($wizardTimeValues)))
					->where([
						'Schedulings.conventionseasons_id' => $conventionSD->id,
						'Schedulings.convention_id' => $conventionSD->convention_id,
						'Schedulings.season_id' => $conventionSD->season_id,
						'Schedulings.season_year' => $conventionSD->season_year,
					])
					->andWhere(function ($exp) use ($nonEmptyCoreTimeSql) {
						return $exp->or($nonEmptyCoreTimeSql);
					})
					->order(['Schedulings.modified' => 'DESC', 'Schedulings.id' => 'DESC'])
					->enableHydration(false)
					->first();

				if (is_array($fallbackRow)) {
					$valueSource = $fallbackRow;
				}
			}

			foreach ($wizardTimeValues as $timeField => $_unused) {
				$sourceValue = array_key_exists($timeField, $valueSource)
					? $valueSource[$timeField]
					: (isset($schedulingD->{$timeField}) ? $schedulingD->{$timeField} : null);
				$wizardTimeValues[$timeField] = $formatTimeValue($sourceValue);
			}
		}

		$this->set('wizardTimeValues', $wizardTimeValues);
			$this->set('wizardBuild', '20260621-direct-time-map-v2');
		
		// Use the schedulingD object directly (don't re-fetch with ->get() which may use cache)
		$schedulings = $schedulingD;
		if (!$schedulings) {
			$schedulings = $this->Schedulings->newEntity([]);
		}
        if ($this->request->is(['post', 'put'])) {
			// Accept both payload shapes used by legacy/modern templates.
			$submittedData = (array)$this->request->getData();
			if (isset($submittedData['Schedulings']) && is_array($submittedData['Schedulings'])) {
				$submittedData = (array)$submittedData['Schedulings'];
			}

			$timeParseErrors = [];
			$normalizeTime = function ($field, $label, $required = false) use (&$submittedData, &$timeParseErrors) {
				$raw = isset($submittedData[$field]) ? trim((string)$submittedData[$field]) : '';

				if ($raw === '') {
					if ($required) {
						$timeParseErrors[] = $label . ' is required.';
					}
					return null;
				}

				$timestamp = strtotime($raw);
				if ($timestamp === false) {
					$timeParseErrors[] = 'Invalid time format for ' . $label . '.';
					return null;
				}

				return date('H:i:s', $timestamp);
			};

			// Parse and normalize core required times.
			$submittedData['normal_starting_time'] = $normalizeTime('normal_starting_time', 'Normal Starting Time', true);
			$submittedData['normal_finish_time'] = $normalizeTime('normal_finish_time', 'Normal Finish Time', true);
			$submittedData['lunch_time_start'] = $normalizeTime('lunch_time_start', 'Lunch Time Start', true);
			$submittedData['lunch_time_end'] = $normalizeTime('lunch_time_end', 'Lunch Time End', true);

			if (!empty($submittedData['starting_different_time_first_day_yes_no'])) {
				$submittedData['different_first_day_start_time'] = $normalizeTime('different_first_day_start_time', 'First Day Start Time', true);
				$submittedData['different_first_day_end_time'] = $normalizeTime('different_first_day_end_time', 'First Day End Time', true);
			} else {
				$submittedData['different_first_day_start_time'] = null;
				$submittedData['different_first_day_end_time'] = null;
			}

			if (!empty($submittedData['judging_breaks_yes_no'])) {
				$submittedData['judging_breaks_morning_break_starting_time'] = $normalizeTime('judging_breaks_morning_break_starting_time', 'Morning Break Starting Time', true);
				$submittedData['judging_breaks_morning_break_finish_time'] = $normalizeTime('judging_breaks_morning_break_finish_time', 'Morning Break Finish Time', true);
				$submittedData['judging_breaks_afternoon_break_start_time'] = $normalizeTime('judging_breaks_afternoon_break_start_time', 'Afternoon Break Start Time', true);
				$submittedData['judging_breaks_afternoon_break_finish_time'] = $normalizeTime('judging_breaks_afternoon_break_finish_time', 'Afternoon Break Finish Time', true);
			} else {
				$submittedData['judging_breaks_morning_break_starting_time'] = null;
				$submittedData['judging_breaks_morning_break_finish_time'] = null;
				$submittedData['judging_breaks_afternoon_break_start_time'] = null;
				$submittedData['judging_breaks_afternoon_break_finish_time'] = null;
			}

			if (!empty($submittedData['sports_day_yes_no'])) {
				$submittedData['sports_day_starting_time'] = $normalizeTime('sports_day_starting_time', 'Sports Day Starting Time', true);
				$submittedData['sports_day_finish_time'] = $normalizeTime('sports_day_finish_time', 'Sports Day Finish Time', true);

				if ($submittedData['sports_day_starting_time'] === '10:00:00') {
					$submittedData['sports_day_starting_time'] = '08:30:00';
				}
				if ($submittedData['sports_day_finish_time'] === '10:00:00') {
					$submittedData['sports_day_finish_time'] = '12:30:00';
				}
			} else {
				$submittedData['sports_day'] = null;
				$submittedData['sports_day_starting_time'] = null;
				$submittedData['sports_day_finish_time'] = null;
			}

			if (!empty($submittedData['sports_day_having_events_after_sport_yes_no'])) {
				$submittedData['sports_day_other_starting_time'] = $normalizeTime('sports_day_other_starting_time', 'Events-After-Sport Starting Time', true);
				$submittedData['sports_day_other_finish_time'] = $normalizeTime('sports_day_other_finish_time', 'Events-After-Sport Finish Time', true);

				if ($submittedData['sports_day_other_starting_time'] === '10:00:00') {
					$submittedData['sports_day_other_starting_time'] = '13:30:00';
				}
				if ($submittedData['sports_day_other_finish_time'] === '10:00:00') {
					$submittedData['sports_day_other_finish_time'] = '17:30:00';
				}
			} else {
				$submittedData['sports_day_other_starting_time'] = null;
				$submittedData['sports_day_other_finish_time'] = null;
			}

			if (!empty($submittedData['start_date'])) {
				$startDateTs = strtotime($submittedData['start_date']);
				if ($startDateTs === false) {
					$timeParseErrors[] = 'Invalid date format for Start Date.';
				} else {
					$submittedData['start_date'] = date('Y-m-d', $startDateTs);
				}
			}

			if (!empty($timeParseErrors)) {
				$this->Flash->error(implode(' ', $timeParseErrors));
				$this->set('schedulings', $schedulings);
				return;
			}

			$getOrNull = function($key) use ($submittedData) {
				return array_key_exists($key, $submittedData) ? $submittedData[$key] : null;
			};
			$roundRobinToggle = (!empty($hasRoundRobinColumn) && !empty($submittedData['round_robin_day_distribution_yes_no'])) ? 1 : 0;
			$dayStrategy = null;
			if ($hasStrategyColumn && array_key_exists('schedule_band_mode', $submittedData)) {
				$dayStrategy = DayStrategy::normalise((string)$submittedData['schedule_band_mode'], (bool)$roundRobinToggle);
				// The old checkbox stays in sync for code that still reads it.
				$roundRobinToggle = $dayStrategy === DayStrategy::ROUND_ROBIN ? 1 : 0;
				$submittedData['round_robin_day_distribution_yes_no'] = $roundRobinToggle;
			}
			$seedValue = null;
			if ($hasSeedColumn && array_key_exists('schedule_seed', $submittedData)) {
				$rawSeed = trim((string)$submittedData['schedule_seed']);
				if ($rawSeed !== '') {
					if (!preg_match('/^-?\d+$/', $rawSeed)) {
						$timeParseErrors[] = 'Schedule seed must be an integer.';
					} else {
						$seedValue = (int)$rawSeed;
					}
				}
			}
			if (!empty($timeParseErrors)) {
				$this->Flash->error(implode(' ', $timeParseErrors));
				$this->set('schedulings', $schedulings);
				return;
			}

			$modifiedNow = date("Y-m-d H:i:s");
			$updatePayload = [
				'start_date' => $getOrNull('start_date'),
				'first_day' => $getOrNull('first_day'),
				'number_of_days' => $getOrNull('number_of_days'),
				'normal_starting_time' => $getOrNull('normal_starting_time'),
				'normal_finish_time' => $getOrNull('normal_finish_time'),
				'lunch_time_start' => $getOrNull('lunch_time_start'),
				'lunch_time_end' => $getOrNull('lunch_time_end'),
				'starting_different_time_first_day_yes_no' => !empty($submittedData['starting_different_time_first_day_yes_no']) ? 1 : 0,
				'different_first_day_start_time' => $getOrNull('different_first_day_start_time'),
				'different_first_day_end_time' => $getOrNull('different_first_day_end_time'),
				'judging_breaks_yes_no' => !empty($submittedData['judging_breaks_yes_no']) ? 1 : 0,
				'judging_breaks_morning_break_starting_time' => $getOrNull('judging_breaks_morning_break_starting_time'),
				'judging_breaks_morning_break_finish_time' => $getOrNull('judging_breaks_morning_break_finish_time'),
				'judging_breaks_afternoon_break_start_time' => $getOrNull('judging_breaks_afternoon_break_start_time'),
				'judging_breaks_afternoon_break_finish_time' => $getOrNull('judging_breaks_afternoon_break_finish_time'),
				'sports_day_yes_no' => !empty($submittedData['sports_day_yes_no']) ? 1 : 0,
				'sports_day' => $getOrNull('sports_day'),
				'sports_day_starting_time' => $getOrNull('sports_day_starting_time'),
				'sports_day_finish_time' => $getOrNull('sports_day_finish_time'),
				'sports_day_having_events_after_sport_yes_no' => !empty($submittedData['sports_day_having_events_after_sport_yes_no']) ? 1 : 0,
				'sports_day_other_starting_time' => $getOrNull('sports_day_other_starting_time'),
				'sports_day_other_finish_time' => $getOrNull('sports_day_other_finish_time'),
				'modified' => $modifiedNow,
			];
			if (!empty($hasRoundRobinColumn)) {
				$updatePayload['round_robin_day_distribution_yes_no'] = !empty($submittedData['round_robin_day_distribution_yes_no']) ? 1 : 0;
			}
			if ($dayStrategy !== null) {
				$updatePayload['schedule_band_mode'] = $dayStrategy;
			}
			if ($hasSeedColumn) {
				$updatePayload['schedule_seed'] = $seedValue;
			}

			$safeUpdatePayload = $this->filterSchedulingsPayloadBySchema($updatePayload);
			if (empty($safeUpdatePayload)) {
				$this->Flash->error('Could not save scheduling wizard settings because required database columns are missing.');
				return;
			}

			$saveOk = false;
			try {
				$saveOk = $this->Schedulings->updateAll($safeUpdatePayload, ['id' => $schedulings->id]);
			} catch (\Throwable $exception) {
				$this->log('Wizard save failed for convention season '.$convention_season_slug.': '.$exception->getMessage(), 'error');
				$this->Flash->error('Could not save scheduling wizard settings. Please verify database schema/times and try again.');
				return;
			}
			if ($saveOk !== false)
			{
				if (!empty($hasRoundRobinColumn)) {
					try {
						$this->Schedulings->getConnection()->execute(
							'UPDATE schedulings SET round_robin_day_distribution_yes_no = :flag WHERE id = :id',
							[
								'flag' => $roundRobinToggle,
								'id' => (int)$schedulings->id,
							],
							[
								'flag' => 'integer',
								'id' => 'integer',
							]
						);
					} catch (\Throwable $roundRobinSaveException) {
						$this->log('Round-robin toggle save fallback failed for scheduling id '.$schedulings->id.': '.$roundRobinSaveException->getMessage(), 'warning');
					}
				}

				$savedRecord = $this->Schedulings->get($schedulings->id);
				$this->syncSchedulingRecordDuplicates($savedRecord, $safeUpdatePayload);

				// Schedule settings changed: clear generated timings so rerun uses latest wizard times.
				$this->clearSchedulingtimings($convention_season_slug);
				$this->Flash->success('Data saved successfully. Existing generated schedules were cleared. Please click Start Scheduling to regenerate with the new times.');
				return $this->redirect(['controller' => 'schedulings', 'action' => 'precheck', $convention_season_slug]);
			}

			$this->Flash->error('Could not save scheduling wizard settings. Please try again and check file permissions/database connection.');
        }
        $this->set('schedulings', $schedulings);
		
	}

	private function filterSchedulingsPayloadBySchema(array $payload)
	{
		$columns = $this->Schedulings->getSchema()->columns();
		$allowed = array_fill_keys($columns, true);

		return array_intersect_key($payload, $allowed);
	}

	private function seasonScopeConditions(object $conventionSD, string $alias): array
	{
		return [
			$alias.'.conventionseasons_id' => (int)$conventionSD->id,
			$alias.'.convention_id' => (int)$conventionSD->convention_id,
			$alias.'.season_id' => (int)$conventionSD->season_id,
			$alias.'.season_year' => (int)$conventionSD->season_year,
		];
	}

	private function parsePositiveInt(mixed $value): ?int
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (is_string($value) && !preg_match('/^\d+$/', $value)) {
			return null;
		}

		$int = (int)$value;

		return $int > 0 ? $int : null;
	}
	
	public function schedulecategory($convention_season_slug=null) {
        $this->set('title', ADMIN_TITLE . 'Schedule category');
        $this->viewBuilder()->setLayout('admin');
		
        $this->set('manageConventions', '1');
        $this->set('conventionList', '1');
		
        $this->set('convention_season_slug', $convention_season_slug);
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		$this->set('conventionSD', $conventionSD);
		
		$conventionMeta = $this->getConventionMetaFromSeason($conventionSD);
		$this->set('convention_slug', $conventionMeta['slug']);
		
		/* Category :: 1 */
		// group_event = yes || event_kind_id = Sequential || needs_schedule = 1 || has_to_be_consecutive = yes
		$arrEventsC1 = array();
		$eventsC1 = $this->Conventionseasonevents->find()->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonevents'))->all();
		foreach($eventsC1 as $eventc1)
		{
			$eventD = $this->Events->find()->where(['Events.id' => $eventc1->event_id])->first();
			if($eventD->needs_schedule == '1' && $eventD->group_event_yes_no == '1' && $eventD->event_kind_id == 'Sequential' && $eventD->has_to_be_consecutive == '1')
			{
				$arrEventsC1[] = $eventc1->event_id;
			}
		}
		$this->set('arrEventsC1', $arrEventsC1);
		
		
		
		
		
		/* Category :: 2 */
		// group_event = no || event_kind_id = Elimination || needs_schedule = 1 || has_to_be_consecutive = no
		$arrEventsC2 = array();
		$eventsC2 = $this->Conventionseasonevents->find()->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonevents'))->all();
		foreach($eventsC2 as $eventc2)
		{
			$eventD = $this->Events->find()->where(['Events.id' => $eventc2->event_id])->first();
			if($eventD->needs_schedule == '1' && $eventD->group_event_yes_no == '0' && $eventD->event_kind_id == 'Elimination' && $eventD->has_to_be_consecutive == '0')
			{
				$arrEventsC2[] = $eventc2->event_id;
			}
		}
		$this->set('arrEventsC2', $arrEventsC2);
		//$this->prx($arrEventsC2);
		
		
		/* Category :: 3 - this is similar to category 2 */
		// group_event = yes || event_kind_id = Elimination || needs_schedule = 1 || has_to_be_consecutive = no
		$arrEventsC3 = array();
		$eventsC3 = $this->Conventionseasonevents->find()->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonevents'))->all();
		foreach($eventsC3 as $eventc3)
		{
			$eventD = $this->Events->find()->where(['Events.id' => $eventc3->event_id])->first();
			if($eventD->needs_schedule == '1' && $eventD->group_event_yes_no == '1' && $eventD->event_kind_id == 'Elimination' && $eventD->has_to_be_consecutive == '0')
			{
				$arrEventsC3[] = $eventc3->event_id;
			}
		}
		$this->set('arrEventsC3', $arrEventsC3);
		//$this->prx($arrEventsC3);
		
		
		/* Category :: 4 - this is similar to category 1 */
		// group_event = no || event_kind_id = Sequential || needs_schedule = 1 || has_to_be_consecutive = yes
		$arrEventsC4 = array();
		$eventsC4 = $this->Conventionseasonevents->find()->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonevents'))->all();
		foreach($eventsC4 as $eventc4)
		{
			$eventD = $this->Events->find()->where(['Events.id' => $eventc4->event_id])->first();
			if($eventD->needs_schedule == '1' && $eventD->group_event_yes_no == '0' && $eventD->event_kind_id == 'Sequential' && $eventD->has_to_be_consecutive == '1')
			{
				$arrEventsC4[] = $eventc4->event_id;
			}
		}
		$this->set('arrEventsC4', $arrEventsC4);
		//$this->prx($arrEventsC4);

		// Expose latest scheduling record so the view can show conflict actions.
		$schedulingD = $this->getLatestSchedulingRecord($conventionSD->id, $conventionSD->convention_id, $conventionSD->season_id, $conventionSD->season_year);
		$this->set('schedulingD', $schedulingD);
		
		
    }

	public function resolveconflicts($convention_season_slug = null)
	{
		$this->Flash->warning('Resolve Conflict now opens a dry-run preview. Review and confirm before applying changes.');

		return $this->redirect(['controller' => 'schedulings', 'action' => 'resolvepreview', $convention_season_slug]);
	}

	public function resolveconflictsgroup($convention_season_slug = null)
	{
		$this->Flash->warning('Group conflict resolve now opens a dry-run preview. Review and confirm before applying changes.');

		return $this->redirect(['controller' => 'schedulings', 'action' => 'resolvepreview', $convention_season_slug]);
	}

	public function resolvepreview($convention_season_slug = null)
	{
		$this->set('title', ADMIN_TITLE . 'Conflict Repair Preview');
		$this->viewBuilder()->setLayout('admin');
		$this->set('manageConventions', '1');
		$this->set('conventionList', '1');
		$this->set('convention_season_slug', $convention_season_slug);

		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		if (!$conventionSD) {
			$this->Flash->error('Invalid convention season.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'precheck']);
		}
		$this->set('conventionSD', $conventionSD);
		$this->set('convention_slug', $this->getConventionMetaFromSeason($conventionSD)['slug']);

		$store = $this->scheduleRepairStore();
		$result = $this->conflictRepairService($store)->preview((int)$conventionSD->id, (string)$convention_season_slug);
		$preview = $result['preview'];
		$plan = $result['plan'];
		$this->set('repairFormToken', $this->repairFormToken());

		$this->set('preview', $preview);
		$this->set('plan', $plan);
		$this->set('labels', TimetableValidator::LABELS);
		$this->set('history', $store->listRuns((int)$conventionSD->id));
	}

	public function resolveapply($convention_season_slug = null)
	{
		$this->request->allowMethod(['post']);
		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		if (!$conventionSD) {
			$this->Flash->error('Invalid convention season.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'precheck']);
		}
		if (!$this->validRepairFormToken()) {
			$this->Flash->error('Your form has expired. Please run the preview again.');
			return $this->redirect(['action' => 'resolvepreview', $convention_season_slug]);
		}

		$token = trim((string)$this->request->getData('token'));
		if (!ScheduleRepairStore::isValidToken($token)) {
			$this->Flash->error('Missing or invalid preview token. Please run preview again.');
			return $this->redirect(['action' => 'resolvepreview', $convention_season_slug]);
		}
		if ((string)$this->request->getData('confirm_apply') !== '1') {
			$this->Flash->error('Please confirm before applying conflict repairs.');
			return $this->redirect(['action' => 'resolvepreview', $convention_season_slug]);
		}

		$result = $this->conflictRepairService()->apply((int)$conventionSD->id, $token);
		if ($result['status'] === ConflictRepairService::APPLIED) {
			$this->Flash->success($result['message']);
			return $this->redirect(['action' => 'resolvereport', $convention_season_slug, $result['run']['run_id']]);
		}
		if ($result['status'] === ConflictRepairService::FAILED) {
			$this->log('Conflict repair apply failed for season '.$conventionSD->id.': '.($result['error'] ?? ''), 'error');
		}
		$message = $result['message'];
		if (!empty($result['new_issues'])) {
			$message .= ' '.implode(' ', array_slice(array_column($result['new_issues'], 'message'), 0, 5));
		}
		$result['status'] === ConflictRepairService::NOTHING ? $this->Flash->success($message) : $this->Flash->error($message);

		return $this->redirect(['action' => 'resolvepreview', $convention_season_slug]);
	}

	public function resolvereport($convention_season_slug = null, $runId = null)
	{
		$this->set('title', ADMIN_TITLE . 'Conflict Repair Report');
		$this->viewBuilder()->setLayout('admin');
		$this->set('manageConventions', '1');
		$this->set('conventionList', '1');
		$this->set('convention_season_slug', $convention_season_slug);

		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		if (!$conventionSD) {
			$this->Flash->error('Invalid convention season.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'precheck']);
		}
		$this->set('conventionSD', $conventionSD);
		$this->set('convention_slug', $this->getConventionMetaFromSeason($conventionSD)['slug']);

		$store = $this->scheduleRepairStore();
		$history = $store->listRuns((int)$conventionSD->id);
		$activeRunId = $runId ?: ($history[0]['run_id'] ?? null);
		$run = $activeRunId ? $store->readRun((int)$conventionSD->id, (string)$activeRunId) : null;
		$this->set('history', $history);
		$this->set('run', $run);
		$this->set('runId', $activeRunId);
		$this->set('labels', TimetableValidator::LABELS);
		$this->set('repairFormToken', $this->repairFormToken());
	}

	public function resolverevert($convention_season_slug = null, $runId = null)
	{
		$this->request->allowMethod(['post']);
		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		if (!$conventionSD || !ScheduleRepairStore::isValidRunId((string)$runId)) {
			$this->Flash->error('Invalid conflict repair revert request.');
			return $this->redirect(['action' => 'resolvepreview', $convention_season_slug]);
		}
		if (!$this->validRepairFormToken()) {
			$this->Flash->error('Your form has expired. Please reload the report and try again.');
			return $this->redirect(['action' => 'resolvereport', $convention_season_slug, $runId]);
		}

		$result = $this->conflictRepairService()->revert((int)$conventionSD->id, (string)$runId);
		if ($result['status'] === ConflictRepairService::FAILED) {
			$this->log('Conflict repair revert failed for season '.$conventionSD->id.' run '.$runId.': '.($result['error'] ?? ''), 'error');
		}
		$message = $result['message'];
		if (!empty($result['new_issues'])) {
			$message .= ' '.implode(' ', array_slice(array_column($result['new_issues'], 'message'), 0, 5));
		}
		match ($result['status']) {
			ConflictRepairService::REVERTED => $this->Flash->success($message),
			ConflictRepairService::NOTHING, ConflictRepairService::ALREADY_DONE => $this->Flash->warning($message),
			default => $this->Flash->error($message),
		};

		return $this->redirect(['action' => 'resolvereport', $convention_season_slug, $runId]);
	}
	
	
	public function reports($convention_season_slug=null) {
        $this->set('title', ADMIN_TITLE . 'Scheduling Wizard');
        $this->viewBuilder()->setLayout('admin');
		
        $this->set('manageConventions', '1');
        $this->set('conventionList', '1');
		
        $this->set('convention_season_slug', $convention_season_slug);
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		
		$this->set('conventionSD', $conventionSD);
		$conventionMeta = $this->getConventionMetaFromSeason($conventionSD);
		$this->set('convention_slug', $conventionMeta['slug']);
		
		// to fetch scheduling data and send to template
		$schedulingD = $this->getLatestSchedulingRecord($conventionSD->id, $conventionSD->convention_id, $conventionSD->season_id, $conventionSD->season_year);
		$this->set('schedulingD', $schedulingD);
		
    }
	
	public function overwritetimings($convention_season_slug=null) {
        $this->set('title', ADMIN_TITLE . 'Overwrite Timings');
        $this->viewBuilder()->setLayout('admin');
		
        $this->set('manageConventions', '1');
        $this->set('conventionList', '1');
		
        $this->set('convention_season_slug', $convention_season_slug);
		
		$conventionSD = $this->Conventionseasons->find()->where(['Conventionseasons.slug' => $convention_season_slug])->first();
		
		$this->set('conventionSD', $conventionSD);
		$conventionMeta = $this->getConventionMetaFromSeason($conventionSD);
		$this->set('convention_slug', $conventionMeta['slug']);
		
		global $weekDays;
		$this->set('weekDays', $weekDays);
		
		// to fetch scheduling data and send to template
		$schedulingD = $this->getLatestSchedulingRecord($conventionSD->id, $conventionSD->convention_id, $conventionSD->season_id, $conventionSD->season_year);
		$this->set('schedulingD', $schedulingD);
		$this->set('schedulings', $schedulingD);
		
		// Nathan provided these 3 events for Overwrite
		/* Spelling U16 - 003--3   Spelling OPEN - 053--11   Bible Memory OPEN - 1056--343 */
		$eventIDArr = array(343,11,3);
		
		// Now check if these events are chosen for this convention season
		
		$finalEventArr = array();
		
		foreach($eventIDArr as $event_id)
		{
			$checkEventCS = $this->Conventionseasonevents->find()->where(['Conventionseasonevents.conventionseasons_id' => $conventionSD->id,'Conventionseasonevents.event_id' => $event_id])->contain(["Events"])->first();
			if($checkEventCS)
			{
				$entryCount = $this->Eventsubmissions->find()->where([
					'Eventsubmissions.conventionseason_id' => (int)$conventionSD->id,
					'Eventsubmissions.event_id' => (int)$event_id,
					'Eventsubmissions.event_id_number' => (string)$checkEventCS->Events['event_id_number'],
					'Eventsubmissions.convention_id' => (int)$conventionSD->convention_id,
					'Eventsubmissions.season_id' => (int)$conventionSD->season_id,
					'Eventsubmissions.season_year' => (int)$conventionSD->season_year,
				])->count();
				$studentLabel = ($entryCount === 1) ? 'student registered' : 'students registered';

				$finalEventArr[$event_id] = $checkEventCS->Events['event_name'].' '.$entryCount.' '.$studentLabel;
			}
		}
		$this->set('finalEventArr', $finalEventArr);
		
		
        if ($this->request->is(['post']))
		{
            $event_id			= $this->parsePositiveInt($this->request->getData()['Schedulings']['event_id'] ?? null);
            $overwrite_date		= $this->request->getData()['Schedulings']['overwrite_date'];
            $overwrite_time		= $this->request->getData()['Schedulings']['overwrite_time'];
			$max_students		= (int)$this->request->getData()['Schedulings']['max_students'];
			if ($event_id === null) {
				$this->Flash->error('Invalid event selected.');
				return $this->redirect(['controller' => 'schedulings', 'action' => 'overwritetimings', $convention_season_slug]);
			}

			// Default batching for spelling events if max is not provided.
			if($max_students <= 0)
			{
				if(in_array((int)$event_id, [3,11], true))
				{
					$max_students = 30;
				}
				else
				{
					$max_students = 1;
				}
			}
			
			
			
			
			// now get event details
            $eventD = $this->Events->find()->where(['Events.id' => $event_id])->first();
			if (!$eventD) {
				$this->Flash->error('Event not found.');
				return $this->redirect(['controller' => 'schedulings', 'action' => 'overwritetimings', $convention_season_slug]);
			}
			
			// Now calculate start time and end time based on event data
			$start_date 		= date("Y-m-d",strtotime($overwrite_date));
			$start_time 		= date("H:i:s",strtotime($overwrite_time));
			
			
			// to calculate event execution time
			$eventSetupRoundJudTime 	= $eventD->setup_time+$eventD->round_time+$eventD->judging_time;
			$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($start_time)));
			
			/* echo $start_date;
			echo '<hr>';
			echo $start_time;
			echo '<hr>';
			echo $finish_time; */
			
			
			// Now fetch students of this event for this convention season
			$cntrTotRec = 0;
			$cntrSc = 0;
			$schedulingtimings = $this->Schedulingtimings->find()->where(['Schedulingtimings.conventionseasons_id' => $conventionSD->id,'Schedulingtimings.event_id' => $event_id])->order(["Schedulingtimings.id"=>"ASC"])->all();
			foreach($schedulingtimings as $schrecord)
			{
				// Update record
				$this->Schedulingtimings->updateAll(
				[
					'sch_date_time' 	=> $start_date.' '.$start_time,
					'day' 				=> date("l",strtotime($start_date)),
					'start_time' 		=> $start_time,
					'finish_time' 		=> $finish_time,
					'modified' 			=> date("Y-m-d H:i:s"),
				]
				, 
				[
					"id" => $schrecord->id
				]
				);
				
				/* echo 'sch_date_time='.$start_date.' '.$start_time;echo '----';
				echo 'day='.date("l",strtotime($start_date));echo '----';
				echo 'start_time='.$start_time;echo '----';
				echo 'finish_time='.$finish_time;echo '----';
				echo 'counter='.$cntrSc;echo '----';
				
				echo '<hr>'; */
				
				$cntrSc++;
				$cntrTotRec++;
				
				// check counter
				if($cntrSc == $max_students)
				{
					// change time
					$start_time 	= date("H:i:s", strtotime('+1 minutes', strtotime($finish_time)));
					$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($start_time)));
					
					$cntrSc = 0;
				}
			}
			
			if($cntrTotRec>0)
			{
				$this->Flash->success('Scheduling date/time overwrite successfully. Total '.$cntrTotRec.' record(s) modified.');
			}
			else
			{
				$this->Flash->error('Sorry, no record updated.');
			}
			
            $this->redirect(['controller' => 'schedulings', 'action' => 'precheck', $convention_season_slug]);
			
			
        }
		
    }

		private function scheduleRepairStore(): ScheduleRepairStore
		{
			return new ScheduleRepairStore(LOGS.'schedule_repairs');
		}

		private function conflictRepairService(?ScheduleRepairStore $store = null): ConflictRepairService
		{
			return new ConflictRepairService(ConnectionManager::get('default'), $store ?? $this->scheduleRepairStore());
		}

		/**
		 * Per-session token for the repair apply/revert forms (the app has no CSRF middleware).
		 */
		private function repairFormToken(): string
		{
			$session = $this->request->getSession();
			$token = (string)$session->read('ScheduleRepair.formToken');
			if ($token === '') {
				$token = bin2hex(random_bytes(32));
				$session->write('ScheduleRepair.formToken', $token);
			}

			return $token;
		}

		private function validRepairFormToken(): bool
		{
			$expected = (string)$this->request->getSession()->read('ScheduleRepair.formToken');
			$given = (string)$this->request->getData('_repair_token');

			return $expected !== '' && $given !== '' && hash_equals($expected, $given);
		}

		private function readLastRunSeed(int $seasonId): ?int
		{
			$path = LOGS.'scheduling_seed_season_'.$seasonId.'.json';
			if (!is_file($path)) {
				return null;
			}
			$raw = file_get_contents($path);
			if ($raw === false || $raw === '') {
				return null;
			}
			$data = json_decode($raw, true);
			if (!is_array($data) || !isset($data['seed'])) {
				return null;
			}

			return (int)$data['seed'];
		}

		private function getConventionSeasonBySlug($conventionSeasonSlug)
		{
			return $this->Conventionseasons
				->find()
				->where(['Conventionseasons.slug' => $conventionSeasonSlug])
				->contain(['Conventions'])
				->first();
		}

		private function getSchedulingByConventionSeasonId($conventionSeasonId, $conventionId, $seasonId, $seasonYear)
		{
			return $this->getLatestSchedulingRecord($conventionSeasonId, $conventionId, $seasonId, $seasonYear);
		}

		private function updateSchedulingConflictField($schedulingId, $fieldName, array $ids)
		{
			$this->Schedulings->updateAll(
				[
					$fieldName => count($ids) ? implode(',', $ids) : null,
					'modified' => date('Y-m-d H:i:s'),
				],
				[
					'id' => $schedulingId,
				]
			);
		}

		private function updateSchedulingForSeason($conventionSeasonId, array $fields)
		{
			$fields['modified'] = date('Y-m-d H:i:s');
			$allowedColumns = array_flip($this->Schedulings->getSchema()->columns());
			$safeFields = array_intersect_key($fields, $allowedColumns);

			if (empty($safeFields)) {
				return;
			}

			$this->Schedulings->updateAll($safeFields, ['conventionseasons_id' => $conventionSeasonId]);
		}

		private function isValidTimeRange($startTime, $endTime): bool
		{
			if (empty($startTime) || empty($endTime)) {
				return false;
			}

			$start = strtotime((string)$startTime);
			$end = strtotime((string)$endTime);

			if ($start === false || $end === false) {
				return false;
			}

			return $start < $end;
		}

		private function getCurrentUserConflictIds($conventionSD): array
		{
			$schedulingtimings = $this->Schedulingtimings
				->find()
				->where($this->seasonScopeConditions($conventionSD, 'Schedulingtimings'))
				->andWhere(function ($exp) {
					return $exp->or([
						'Schedulingtimings.user_id >' => 0,
						'Schedulingtimings.user_id_opponent >' => 0,
					]);
				})
				->andWhere(function ($exp) {
					return $exp->or([
						'Schedulingtimings.is_bye IS' => null,
						'Schedulingtimings.is_bye !=' => 1,
					]);
				})
				->orderBy(["Schedulingtimings.id" => "ASC"])
				->all();

			$userSchedules = [];
			foreach ($schedulingtimings as $schrecord) {
				$day = $schrecord->day;
				$start = strtotime((string)$schrecord->start_time);
				$end = strtotime((string)$schrecord->finish_time);
				if ($start === false || $end === false) {
					continue;
				}

				foreach ($this->scheduleParticipantIds($schrecord) as $uid) {
					$userSchedules[$uid][(int)$schrecord->id] = [
						'day' => $day,
						'start' => $start,
						'end' => $end,
					];
				}
			}

			$conflictUserIds = [];
			foreach ($userSchedules as $uid => $entriesByScheduleId) {
				$entries = array_values($entriesByScheduleId);
				$entryCount = count($entries);
				for ($i = 0; $i < $entryCount; $i++) {
					for ($j = $i + 1; $j < $entryCount; $j++) {
						$a = $entries[$i];
						$b = $entries[$j];
						if ($a['day'] === $b['day'] && $a['start'] < $b['end'] && $a['end'] > $b['start']) {
							$conflictUserIds[] = (int)$uid;
							break 2;
						}
					}
				}
			}

			return array_values(array_unique($conflictUserIds));
		}

		/**
		 * Resolves participant clashes without moving matches. A clashing sequential
		 * slot (no opponent) is swapped with another slot of the same event; otherwise it
		 * moves to the start or end of the event's run in the same room, and finally to
		 * any free gap in that room. Changes are applied only when every affected
		 * participant and the room are free and the slot fits the scheduling window.
		 */
		private function resolveConflictsBySwappingSlots($conventionSD): int
		{
			$schedulingD = $this->getSchedulingByConventionSeasonId(
				$conventionSD->id,
				$conventionSD->convention_id,
				$conventionSD->season_id,
				$conventionSD->season_year
			);
			$rows = $this->Schedulingtimings->find()
				->where([
					'Schedulingtimings.conventionseasons_id' => $conventionSD->id,
					'Schedulingtimings.convention_id' => $conventionSD->convention_id,
					'Schedulingtimings.season_id' => $conventionSD->season_id,
					'Schedulingtimings.season_year' => $conventionSD->season_year,
					'OR' => [['Schedulingtimings.is_bye IS' => null], ['Schedulingtimings.is_bye !=' => 1]],
				])
				->orderBy(['Schedulingtimings.id' => 'ASC'])
				->all();

			$slots = [];
			$byUser = [];
			$byEvent = [];
			$byRoom = [];
			foreach ($rows as $row) {
				$start = strtotime((string)$row->start_time);
				$end = strtotime((string)$row->finish_time);
				if ($start === false || $end === false || empty($row->sch_date_time)) {
					continue;
				}
				$id = (int)$row->id;
				$date = $row->sch_date_time instanceof \DateTimeInterface
					? $row->sch_date_time->format('Y-m-d')
					: date('Y-m-d', strtotime((string)$row->sch_date_time));
				$participants = $this->scheduleParticipantIds($row);
				$slots[$id] = [
					'id' => $id,
					'eventId' => (int)$row->event_id,
					'participants' => $participants,
					'date' => $date,
					'day' => (string)$row->day,
					'start' => $start,
					'end' => $end,
					'room' => (int)$row->room_id,
					'fields' => [
						'sch_date_time' => $row->sch_date_time,
						'day' => $row->day,
						'start_time' => $row->start_time,
						'finish_time' => $row->finish_time,
						'room_id' => $row->room_id,
					],
				];
				foreach ($participants as $uid) {
					$byUser[$uid][$id] = true;
				}
				if ((int)$row->room_id > 0) {
					$byRoom[(int)$row->room_id][$id] = true;
				}
				if ((int)$row->user_id_opponent <= 0 && $participants) {
					$eventKey = (int)$row->schedule_category.'|'.(int)$row->event_id;
					$byEvent[$eventKey][] = $id;
					$slots[$id]['eventKey'] = $eventKey;
				}
			}

			$overlaps = function (array $a, array $b): bool {
				return $a['day'] === $b['day'] && $a['start'] < $b['end'] && $a['end'] > $b['start'];
			};
			$isFree = function (int $uid, array $time, array $ignoreIds) use (&$slots, &$byUser, $overlaps): bool {
				foreach (array_keys($byUser[$uid] ?? []) as $otherId) {
					if (!in_array($otherId, $ignoreIds, true) && $overlaps($slots[$otherId], $time)) {
						return false;
					}
				}

				return true;
			};
			$allFree = function (array $uids, array $time, array $ignoreIds) use ($isFree): bool {
				foreach ($uids as $uid) {
					if (!$isFree($uid, $time, $ignoreIds)) {
						return false;
					}
				}

				return true;
			};
			$nearestFirst = function (array $times, array $move): array {
				usort($times, function ($x, $y) use ($move) {
					return [$x['day'] !== $move['day'], abs($x['start'] - $move['start'])]
						<=> [$y['day'] !== $move['day'], abs($y['start'] - $move['start'])];
				});

				return $times;
			};
			$save = function (int $id) use (&$slots): void {
				$this->Schedulingtimings->updateAll(
					$slots[$id]['fields'] + ['modified' => date('Y-m-d H:i:s')],
					['id' => $id]
				);
			};

			$trySwap = function (int $moveId) use (&$slots, $byEvent, $overlaps, $allFree, $nearestFirst, $save): bool {
				$move = $slots[$moveId];
				$candidates = $nearestFirst(array_map(function ($id) use ($slots) {
					return $slots[$id];
				}, $byEvent[$move['eventKey']]), $move);
				foreach ($candidates as $other) {
					$otherId = $other['id'];
					$ignore = [$moveId, $otherId];
					if (
						$otherId === $moveId ||
						$overlaps($move, $other) ||
						!$allFree($move['participants'], $other, $ignore) ||
						!$allFree($other['participants'], $move, $ignore)
					) {
						continue;
					}
					foreach (['date', 'day', 'start', 'end', 'room', 'fields'] as $key) {
						$slots[$moveId][$key] = $other[$key];
						$slots[$otherId][$key] = $move[$key];
					}
					$save($moveId);
					$save($otherId);

					return true;
				}

				return false;
			};

			$tryMoveInRoom = function (int $moveId, bool $anyGap) use (&$slots, &$byRoom, $byEvent, $overlaps, $allFree, $nearestFirst, $save, $conventionSD, $schedulingD): bool {
				$move = $slots[$moveId];
				if (empty($schedulingD) || $move['room'] <= 0) {
					return false;
				}
				$duration = $move['end'] - $move['start'];
				$dayStart = strtotime(date('H:i:s', strtotime((string)$schedulingD->normal_starting_time)));
				$dayEnd = strtotime(date('H:i:s', strtotime((string)$schedulingD->normal_finish_time)));
				$lunch = !empty($schedulingD->lunch_time_start) && !empty($schedulingD->lunch_time_end) ? [
					'start' => strtotime(date('H:i:s', strtotime((string)$schedulingD->lunch_time_start))),
					'end' => strtotime(date('H:i:s', strtotime((string)$schedulingD->lunch_time_end))),
				] : null;

				$runs = [];
				foreach ($byEvent[$move['eventKey']] as $id) {
					$slot = $slots[$id];
					if ($id === $moveId || $slot['room'] !== $move['room']) {
						continue;
					}
					$runs[$slot['date']]['first'] = min($runs[$slot['date']]['first'] ?? PHP_INT_MAX, $slot['start']);
					$runs[$slot['date']]['last'] = max($runs[$slot['date']]['last'] ?? 0, $slot['end']);
				}
				$starts = [];
				foreach ($runs as $date => $run) {
					$starts[$date] = [$run['last'], $run['first'] - $duration];
				}
				if ($anyGap) {
					// Last resort: any free gap in the same room, which may split the event's run.
					foreach (array_keys($byRoom[$move['room']] ?? []) as $roomSlotId) {
						$roomSlot = $slots[$roomSlotId];
						$starts[$roomSlot['date']][] = $roomSlot['end'];
					}
					foreach (array_keys($starts) as $date) {
						$starts[$date][] = $dayStart;
						if ($lunch) {
							$starts[$date][] = $lunch['end'];
						}
					}
				}
				$times = [];
				foreach ($starts as $date => $dateStarts) {
					foreach (array_unique($dateStarts) as $start) {
						$times[] = ['date' => $date, 'day' => date('l', strtotime($date)), 'start' => $start, 'end' => $start + $duration];
					}
				}

				foreach ($nearestFirst($times, $move) as $time) {
					if (
						$time['start'] < $dayStart || $time['end'] > $dayEnd ||
						($lunch && $time['start'] < $lunch['end'] && $time['end'] > $lunch['start']) ||
						!$allFree($move['participants'], $time, [$moveId])
					) {
						continue;
					}
					$roomBusy = false;
					foreach (array_keys($byRoom[$move['room']] ?? []) as $roomSlotId) {
						if ($roomSlotId !== $moveId && $overlaps($slots[$roomSlotId], $time)) {
							$roomBusy = true;
							break;
						}
					}
					if ($roomBusy) {
						continue;
					}
					$startTime = date('H:i:s', $time['start']);
					$constraint = $this->applySlotConstraintsForConflict(
						$conventionSD,
						$schedulingD,
						$move['eventId'],
						$move['room'],
						(int)round($duration / 60),
						$time['date'],
						$startTime
					);
					if (empty($constraint['ok']) || $constraint['date'] !== $time['date'] || $constraint['start'] !== $startTime) {
						continue;
					}

					$slots[$moveId] = array_merge($slots[$moveId], $time, [
						'fields' => [
							'sch_date_time' => $time['date'].' '.$startTime,
							'day' => $time['day'],
							'start_time' => $startTime,
							'finish_time' => date('H:i:s', $time['end']),
							'room_id' => $move['fields']['room_id'],
						],
					]);
					$save($moveId);

					return true;
				}

				return false;
			};

			$changes = 0;
			for ($pass = 0; $pass < 5; $pass++) {
				$conflicts = [];
				foreach ($byUser as $ids) {
					$ids = array_keys($ids);
					for ($i = 0; $i < count($ids); $i++) {
						for ($j = $i + 1; $j < count($ids); $j++) {
							if ($overlaps($slots[$ids[$i]], $slots[$ids[$j]])) {
								$conflicts[] = [$ids[$i], $ids[$j]];
							}
						}
					}
				}
				if (!$conflicts) {
					break;
				}

				$progress = false;
				foreach ($conflicts as [$aId, $bId]) {
					if (!$overlaps($slots[$aId], $slots[$bId])) {
						continue;
					}
					$movable = array_values(array_filter([$bId, $aId], function ($id) use ($slots) {
						return isset($slots[$id]['eventKey']);
					}));
					$strategies = [
						$trySwap,
						function (int $id) use ($tryMoveInRoom): bool {
							return $tryMoveInRoom($id, false);
						},
						function (int $id) use ($tryMoveInRoom): bool {
							return $tryMoveInRoom($id, true);
						},
					];
					foreach ($strategies as $strategy) {
						foreach ($movable as $moveId) {
							if ($strategy($moveId)) {
								$changes++;
								$progress = true;
								continue 3;
							}
						}
					}
				}
				if (!$progress) {
					break;
				}
			}

			return $changes;
		}

		private function getCurrentGroupConflictScheduleIds($conventionSD): array
		{
			$schedulingtimings = $this->Schedulingtimings
				->find()
				->where($this->seasonScopeConditions($conventionSD, 'Schedulingtimings'))
				->andWhere(function ($exp) {
					return $exp->or([
						'Schedulingtimings.user_id >' => 0,
						'Schedulingtimings.user_id_opponent >' => 0,
					]);
				})
				->andWhere(function ($exp) {
					return $exp->or([
						'Schedulingtimings.is_bye IS' => null,
						'Schedulingtimings.is_bye !=' => 1,
					]);
				})
				->orderBy(["Schedulingtimings.id" => "ASC"])
				->all();

			$userSchedules = [];
			foreach ($schedulingtimings as $schrecord) {
				$day = $schrecord->day;
				$start = strtotime((string)$schrecord->start_time);
				$end = strtotime((string)$schrecord->finish_time);
				if ($start === false || $end === false) {
					continue;
				}

				foreach ($this->scheduleParticipantIds($schrecord) as $uid) {
					$userSchedules[$uid][(int)$schrecord->id] = [
						'id' => (int)$schrecord->id,
						'day' => $day,
						'start' => $start,
						'end' => $end,
					];
				}
			}

			$conflictScheduleIds = [];
			foreach ($userSchedules as $entriesByScheduleId) {
				$entries = array_values($entriesByScheduleId);
				$entryCount = count($entries);
				for ($i = 0; $i < $entryCount; $i++) {
					for ($j = $i + 1; $j < $entryCount; $j++) {
						$a = $entries[$i];
						$b = $entries[$j];
						if ($a['day'] === $b['day'] && $a['start'] < $b['end'] && $a['end'] > $b['start']) {
							$checkGroupA = $this->Schedulingtimings->find()->select(['id', 'user_type'])->where(['Schedulingtimings.id' => $a['id']])->first();
							$checkGroupB = $this->Schedulingtimings->find()->select(['id', 'user_type'])->where(['Schedulingtimings.id' => $b['id']])->first();
							if ($checkGroupA && $checkGroupA->user_type === 'School') {
								$conflictScheduleIds[] = (int)$a['id'];
							}
							if ($checkGroupB && $checkGroupB->user_type === 'School') {
								$conflictScheduleIds[] = (int)$b['id'];
							}
						}
					}
				}
			}

			return array_values(array_unique($conflictScheduleIds));
		}

}

?>
