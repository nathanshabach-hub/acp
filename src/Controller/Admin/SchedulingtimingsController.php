<?php

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Controller\Traits\ScheduleProgressRedirectTrait;
use App\Scheduling\DayStrategy;
use App\Scheduling\KnockoutBracketPlanner;
use App\Scheduling\JudgeScheduleGate;
use App\Scheduling\TimetableLoader;
use App\Scheduling\SlotPlanner;
use App\Scheduling\TimetableLedger;
use App\Scheduling\TimetableValidator;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

use Cake\Datasource\ConnectionManager;

class SchedulingtimingsController extends AppController {
	use ScheduleProgressRedirectTrait;

    public array $paginate = ['limit' => 50, 'order' => ['Schedulings.name' => 'asc']];
    public $components = array('PImage');
private $scheduleWindowWarningShown = false;

    //public $helpers = array('Javascript', 'Ajax');

    public function initialize(): void {
        parent::initialize();
        $this->loadComponent('Flash');
        $action = $this->request->getParam('action');
        $loggedAdminId = $this->request->getSession()->read('admin_id');
        if ($action != 'forgotPassword' && $action != 'logout') {
            if (!$loggedAdminId && $action != "login" && $action != 'captcha') {
                $this->redirect(['controller' => 'admins', 'action' => 'login']);
            }
		}
		
		$this->Conventionseasons = $this->loadModel('Conventionseasons');
		$this->Conventionseasonevents = $this->loadModel('Conventionseasonevents');
		$this->Events = $this->loadModel('Events');
		$this->Conventionregistrations = $this->loadModel('Conventionregistrations');
		$this->Crstudentevents = $this->loadModel('Crstudentevents');
		$this->Schedulingtimings = $this->loadModel('Schedulingtimings');
		$this->Conventionseasonroomevents = $this->loadModel('Conventionseasonroomevents');
		$this->Schedulings = $this->loadModel('Schedulings');
		$this->Conventionregistrationstudents = $this->loadModel('Conventionregistrationstudents');
		$this->Schedulingeventtweaks = $this->loadModel('Schedulingeventtweaks');
		$this->Eventsubmissions = $this->loadModel('Eventsubmissions');
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

	private function judgeScheduleStage(int $seasonId, int $stage, bool $finished): void
	{
		$gate = new JudgeScheduleGate(ConnectionManager::get('default'));
		$token = $this->request->getSession()->read('Scheduling.judgeGate.'.$seasonId);
		if ($finished) {
			$gate->advance($seasonId, $token, $stage - 1, $stage);
		} else {
			$gate->enter($seasonId, $token, $stage - 1);
		}
	}

	private function whereCsvContainsEventId($query, string $field, int $eventId)
	{
		$value = (string)$eventId;
		return $query->andWhere(function ($exp) use ($field, $value) {
			return $exp->or([
				[$field => $value],
				[$field.' LIKE' => $value.',%'],
				[$field.' LIKE' => '%,'.$value.',%'],
				[$field.' LIKE' => '%,'.$value],
			]);
		});
	}
	
	
	public function removeoverlapping($convention_season_slug = null) {
		$this->request->allowMethod(['get']);
		return $this->redirect([
			'controller' => 'schedulings',
			'action' => 'resolvepreview',
			$convention_season_slug,
		]);
	}

	public function viewscheduling($convention_season_slug=null,$scheduling_category=null) {
		if (!in_array((string)$scheduling_category, ['1', '2', '3', '4'], true)) {
			throw new \Cake\Http\Exception\BadRequestException('Select a scheduling category from 1 to 4.');
		}
        $this->set('title', ADMIN_TITLE . 'Scheduling Pre-check');
        $this->viewBuilder()->setLayout('admin');
		
        $this->set('manageConventions', '1');
        $this->set('conventionList', '1');
		
        $this->set('convention_season_slug', $convention_season_slug);
        $this->set('scheduling_category', $scheduling_category);
		
		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		//$this->prx($conventionSD);
		
		$this->set('conventionSD', $conventionSD);
		
		$this->set('convention_slug', $conventionSD->Conventions['slug']);
		
		// to list all schedulings
		$baseConditions = [
			'Schedulingtimings.conventionseasons_id' => $conventionSD->id,
			'Schedulingtimings.convention_id' => $conventionSD->convention_id,
			'Schedulingtimings.season_id' => $conventionSD->season_id,
			'Schedulingtimings.season_year' => $conventionSD->season_year,
			'Schedulingtimings.schedule_category' => $scheduling_category,
		];

		$scheduledConditions = $baseConditions + [
			'Schedulingtimings.day IS NOT' => null,
			'Schedulingtimings.start_time IS NOT' => null,
			'Schedulingtimings.finish_time IS NOT' => null,
			'Schedulingtimings.sch_date_time IS NOT' => null,
			'Schedulingtimings.day !=' => '',
			'Schedulingtimings.start_time !=' => '',
			'Schedulingtimings.finish_time !=' => '',
		];

		$schedulingTimingsList = $this->Schedulingtimings->find()
			->where($scheduledConditions)
			->contain(["Events","Users","Conventionrooms","Opponentuser"])
			->order(["Schedulingtimings.sch_date_time" => "ASC", "Schedulingtimings.room_id" => "ASC", "Schedulingtimings.start_time" => "ASC", "Schedulingtimings.id" => "ASC"])
			->all();

		$unscheduledCount = $this->Schedulingtimings->find()
			->where($baseConditions)
			->andWhere(function ($exp) {
				return $exp->or([
					['Schedulingtimings.day IS' => null],
					['Schedulingtimings.start_time IS' => null],
					['Schedulingtimings.finish_time IS' => null],
					['Schedulingtimings.sch_date_time IS' => null],
					['Schedulingtimings.day' => ''],
					['Schedulingtimings.start_time' => ''],
					['Schedulingtimings.finish_time' => ''],
				]);
			})
			->count();

		if ($unscheduledCount > 0) {
			$this->Flash->warning('There are '.$unscheduledCount.' unscheduled row(s) in this category that are hidden from this view because day/start/finish are empty. Increase scheduling capacity or adjust tweaks, then rerun Start Scheduling to place them.');
		}
		$this->set('schedulingTimingsList', $schedulingTimingsList);
		$this->set('pendingEventsToRoomsList', []);
    }

    public function startschedulec1($convention_season_slug=null) {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        
		
        $this->set('convention_season_slug', $convention_season_slug);
		$this->request->getSession()->delete('Scheduling.windowWarningShown');
		$this->request->getSession()->delete('Scheduling.runWarnings');
        $this->request->getSession()->delete('Scheduling.runSeed');
        $this->scheduleWindowWarningShown = false;
        $this->clearSchedulingtimings($convention_season_slug);
		
		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		$gate = new JudgeScheduleGate(ConnectionManager::get('default'));
		$this->request->getSession()->write('Scheduling.judgeGate.'.$conventionSD->id, $gate->start((int)$conventionSD->id));
		
		// to get details of schedule timings
		$schedulingsD = $this->resolveSchedulingWizardRecord($conventionSD);
		if ($redirect = $this->ensureSchedulingWindowIsValid($schedulingsD, $convention_season_slug)) {
			return $redirect;
		}
		$this->applyScheduleSeed($conventionSD, $schedulingsD, 1);
		$start_date 			= date("Y-m-d",strtotime($schedulingsD->start_date));
		$first_day 				= $schedulingsD->first_day;
		$normal_starting_time 	= $this->normalizeWizardTime($schedulingsD->normal_starting_time);
		$normal_finish_time 	= $this->normalizeWizardTime($schedulingsD->normal_finish_time);
		
		$lunch_time_start 		= $this->normalizeWizardTime($schedulingsD->lunch_time_start);
		$lunch_time_end 		= $this->normalizeWizardTime($schedulingsD->lunch_time_end);

		$starting_different_time_first_day_yes_no = $schedulingsD->starting_different_time_first_day_yes_no;
		if($starting_different_time_first_day_yes_no == 1)
		{
			$different_first_day_start_time = $this->normalizeWizardTime($schedulingsD->different_first_day_start_time);
			$different_first_day_end_time 	= $this->normalizeWizardTime($schedulingsD->different_first_day_end_time);
		}
		
		\Cake\Log\Log::debug('C1 parsed window season '.$conventionSD->id.': start_date_raw='.(string)$schedulingsD->start_date.', first_day='.(string)$first_day.', normal_start_raw='.(string)$schedulingsD->normal_starting_time.', normal_finish_raw='.(string)$schedulingsD->normal_finish_time.', lunch_start_raw='.(string)$schedulingsD->lunch_time_start.', lunch_end_raw='.(string)$schedulingsD->lunch_time_end.', normal_start_parsed='.$normal_starting_time.', normal_finish_parsed='.$normal_finish_time.', lunch_start_parsed='.$lunch_time_start.', lunch_end_parsed='.$lunch_time_end.', number_of_days='.(string)$schedulingsD->number_of_days);
		
		
		/* TO GET ALL THE EVENTS WITH FOLLOWING CONDITIONS */
		// group_event = yes || event_kind_id = sequential || needs_schedule = 1 || has_to_be_consecutive = yes
		$arrEventC1 = array();
		$arrEventC1[] = 0;
		$eventList = $this->Events->find()
			->where([
				'Events.needs_schedule' => '1',
				'Events.group_event_yes_no' => '1',
				'Events.event_kind_id' => 'Sequential',
				'Events.has_to_be_consecutive' => '1',
			])
			->select(['id','event_id_number'])
			->all();
		//$this->prx($eventList);
		foreach($eventList as $eventdata)
		{
			$arrEventC1[] = $eventdata->id;
		}
		$arrEventC1 = array_values(array_unique(array_map('intval', $arrEventC1)));
		//$this->prx($arrEventC1);
		//$arrEventC1Implode = '344';
		
		
		
		// 1. Fetch all events that is required scheduling for this convention season
		$allEventsCS = $this->Conventionseasonevents->find()
			->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonevents'))
			->andWhere(['Conventionseasonevents.event_id IN' => $arrEventC1 ?: [0]])
			->all();
		$c1EventsWithoutRooms = 0;
		$c1EventsWithoutGroups = 0;
		$c1RecordsCreated = 0;
		$c1UngroupedFallbackCount = 0;
		$c1EventsUsingSeasonRoomFallback = 0;
		$c1SaveFailures = 0;
		$c1FirstSaveError = '';
		$c1EventsWithGroups = 0;
		$c1AttemptedRows = 0;
		$c1WindowExceededEvents = 0;
		$c1SkippedWithoutSubmission = 0;
		// Entries with a blank group name and/or no event submission, listed once per school/event/group.
		$c1EntryIssues = [];
		$submissionExistsCache = [];
		$combineRequestsTable = $this->fetchTable('Combinerequests');
		$combinedPairs = $this->loadCategoryOneCombinedPairs($conventionSD);
		$this->timetableLedger = new TimetableLedger(self::STUDENT_BUFFER_MINUTES);
		$this->unscheduledReport = [];
		@unlink($this->unscheduledReportPath($conventionSD));

		$seasonFallbackRoomIds = [];
		$seasonRoomRows = $this->Conventionseasonroomevents->find()
			->select(['room_id'])
			->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonroomevents'))
			->all();
		foreach($seasonRoomRows as $seasonRoomRow)
		{
			$roomId = (int)$seasonRoomRow->room_id;
			if($roomId > 0 && !in_array($roomId, $seasonFallbackRoomIds, true))
			{
				$seasonFallbackRoomIds[] = $roomId;
			}
		}
		//$this->prx($condEVCS);
		foreach($allEventsCS as $eventcs)
		{
			$mainArrForEvent = array();
			// to check if this event require schedule
			
			$eventD = $this->Events->find()->where(['Events.id' => $eventcs->event_id])->first();
			
			// to calculate event execution time
			$eventSetupRoundJudTime 	= $eventD->setup_time+$eventD->round_time+$eventD->judging_time;
			
			$eventIDCS = $eventD->id;
			// for now we are doing schedulings for event_id_number 870,871 for testing
			
			// now check that if any room is allocated for this event
			$roomQuery = $this->Conventionseasonroomevents->find()
				->select(['room_id', 'number_games'])
				->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonroomevents'));
			$roomCSEvent = $this->whereCsvContainsEventId($roomQuery, 'Conventionseasonroomevents.event_ids', (int)$eventIDCS)->all();
			$roomArrCSEvent = array();
			foreach($roomCSEvent as $roomeventcs)
			{
				$roomId = (int)$roomeventcs->room_id;
				$gamesAtOnce = isset($roomeventcs->number_games) ? (int)$roomeventcs->number_games : 1;
				if($gamesAtOnce <= 0)
				{
					$gamesAtOnce = 1;
				}
				for($gameCounter = 0; $gameCounter < $gamesAtOnce; $gameCounter++)
				{
					$roomArrCSEvent[] = $roomId;
				}
			}

			if(!count((array)$roomArrCSEvent) && count((array)$seasonFallbackRoomIds))
			{
				$roomArrCSEvent = $seasonFallbackRoomIds;
				$c1EventsUsingSeasonRoomFallback++;
			}
			//$this->prx($roomArrCSEvent);
			
			// Check if there's only one room, then duplicate
			/* if (count($roomArrCSEvent) === 1) {
				// Duplicate the same record up to 4 times
				while (count($roomArrCSEvent) < 4) {
					$roomArrCSEvent[] = $roomArrCSEvent[0];
				}
			} */
			
			
			
			// ---- Load event tweaks (A: pinned day, B: pinned room, C: pinned start time) ----
			$eventTweak = $this->Schedulingeventtweaks->find()
				->where(['Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
				         'Schedulingeventtweaks.event_id'             => $eventIDCS])
				->first();
			// B: override room list with the pinned room
			if ($eventTweak && $eventTweak->pinned_room_id) {
				$roomArrCSEvent = [(int)$eventTweak->pinned_room_id];
			}
			
			// check if there is rooms assigned for this event
			if(count((array)$roomArrCSEvent)>0)
			{	
				// First to fetch conv registrations
				$conventionRegistrations = $this->Conventionregistrations->find()->where([
					'Conventionregistrations.conventionseason_id' => (int)$conventionSD->id,
					'Conventionregistrations.convention_id' => (int)$conventionSD->convention_id,
				])->all();
				foreach($conventionRegistrations as $convreg)
				{
					//now fetch groups for this CR
					$convRegSTEV = $this->Crstudentevents->find()->where([
						'Crstudentevents.conventionseason_id' => (int)$conventionSD->id,
						'Crstudentevents.convention_id' => (int)$conventionSD->convention_id,
						'Crstudentevents.conventionregistration_id' => (int)$convreg->id,
						'Crstudentevents.event_id' => (int)$eventIDCS,
					])->select(['group_name', 'student_id'])->all();
					//$this->prx($convRegSTEV);
					if($convRegSTEV)
					{
						// if any group exists, then push it to array
						foreach($convRegSTEV as $convregstev)
						{
							// now create a variable with combination 
							// format is conventionseasons_id==convention_id==season_id==season_year==conventionregistration_id==event_id==event_id_number==user_id==group_name
							
							$varEventCombination 	= $conventionSD->id."==";
							$varEventCombination 	.= $conventionSD->convention_id."==";
							$varEventCombination 	.= $conventionSD->season_id."==";
							$varEventCombination 	.= $conventionSD->season_year."==";
							$varEventCombination 	.= $convreg->id."==";
							$varEventCombination	.= $eventIDCS."==";
							$varEventCombination 	.= $eventD->event_id_number."==";
							$varEventCombination 	.= $convreg->user_id."==";

							$groupName = trim((string)($convregstev->group_name ?? ''));
							if ($groupName === '') {
								$groupName = 'Ungrouped';
								$c1UngroupedFallbackCount++;
							}
							$entryIssueKey = $convreg->id.'|'.$eventIDCS.'|'.$groupName;

							$submissionKey = $convreg->id.'|'.$eventIDCS.'|'.$convreg->user_id.'|'.$groupName;
							if (!array_key_exists($submissionKey, $submissionExistsCache)) {
								$submissionConditions = [
									'Eventsubmissions.conventionregistration_id' => $convreg->id,
									'Eventsubmissions.conventionseason_id' => $conventionSD->id,
									'Eventsubmissions.convention_id' => $conventionSD->convention_id,
									'Eventsubmissions.season_id' => $conventionSD->season_id,
									'Eventsubmissions.season_year' => $conventionSD->season_year,
									'Eventsubmissions.event_id' => $eventIDCS,
									'Eventsubmissions.user_id' => $convreg->user_id,
								];
								if ($groupName === 'Ungrouped') {
									$submissionConditions[] = "(Eventsubmissions.group_name = 'Ungrouped' OR Eventsubmissions.group_name IS NULL OR TRIM(Eventsubmissions.group_name) = '')";
								} else {
									$submissionConditions['Eventsubmissions.group_name'] = $groupName;
								}
								$submissionCount = $this->Eventsubmissions->find()->where($submissionConditions)->count();

								if ($submissionCount === 0) {
									$combineWithUserIds = $combineRequestsTable->find()
										->select(['combine_with_user_id'])
										->where([
											'Combinerequests.conventionseason_id' => $conventionSD->id,
											'Combinerequests.convention_id' => $conventionSD->convention_id,
											'Combinerequests.season_id' => $conventionSD->season_id,
											'Combinerequests.season_year' => $conventionSD->season_year,
											'Combinerequests.conventionregistration_id' => $convreg->id,
											'Combinerequests.event_id' => $eventIDCS,
											'Combinerequests.status' => 1,
										])
										->enableHydration(false)
										->all()
										->extract('combine_with_user_id')
										->filter(function ($id) {
											return (int)$id > 0;
										})
										->toList();

									if (count($combineWithUserIds) > 0) {
										$combinedSubmissionConditions = [
											'Eventsubmissions.conventionseason_id' => $conventionSD->id,
											'Eventsubmissions.convention_id' => $conventionSD->convention_id,
											'Eventsubmissions.season_id' => $conventionSD->season_id,
											'Eventsubmissions.season_year' => $conventionSD->season_year,
											'Eventsubmissions.event_id' => $eventIDCS,
											'Eventsubmissions.user_id IN' => $combineWithUserIds,
										];
										if ($groupName === 'Ungrouped') {
											$combinedSubmissionConditions[] = "(Eventsubmissions.group_name = 'Ungrouped' OR Eventsubmissions.group_name IS NULL OR TRIM(Eventsubmissions.group_name) = '')";
										} else {
											$combinedSubmissionConditions['Eventsubmissions.group_name'] = $groupName;
										}
										$submissionCount = $this->Eventsubmissions->find()->where($combinedSubmissionConditions)->count();
									}
								}
								$submissionExistsCache[$submissionKey] = $submissionCount > 0;
							}

							if ($groupName === 'Ungrouped' || !$submissionExistsCache[$submissionKey]) {
								$c1EntryIssues[$entryIssueKey] ??= [
									'school_id' => (int)$convreg->user_id,
									'event' => trim($eventD->event_id_number.' '.$eventD->event_name),
									'group' => $groupName,
									'students' => [],
									'missing_group' => $groupName === 'Ungrouped',
									'missing_submission' => !$submissionExistsCache[$submissionKey],
								];
								$c1EntryIssues[$entryIssueKey]['students'][(int)$convregstev->student_id] = (int)$convregstev->student_id;
							}
							if (!$submissionExistsCache[$submissionKey]) {
								$c1SkippedWithoutSubmission++;
								continue;
							}
							$varEventCombination 	.= $groupName;
							
							if(!in_array($varEventCombination,(array)$mainArrForEvent))
							{
								$mainArrForEvent[] = $varEventCombination;
							}
						}
					}
				}
			
			
				// now define timings for schedule for this event
			
				//echo 'eeee';
				//$this->prx($mainArrForEvent);
			
				if(count((array)$mainArrForEvent))
				{
					$c1EventsWithGroups++;

					$eventBandsC1 = $this->buildCategory4EventBands($schedulingsD, $eventTweak);

					shuffle($mainArrForEvent);
					$c1RoomLanes = array_fill_keys(array_values(array_unique(array_map('intval', $roomArrCSEvent))), 1);
					$c1Units = $this->buildCategoryOneUnits($conventionSD, (int)$eventIDCS, $mainArrForEvent, $combinedPairs);
					$c1UnitsByKey = [];
					foreach ($c1Units as $c1Unit) {
						$c1UnitsByKey[$c1Unit['id']] = $c1Unit;
					}
					$c1AttemptedRows += count($mainArrForEvent);
					$c1Result = $this->placeEventWithStrategy($conventionSD, $schedulingsD, $c1Units, $eventBandsC1, $c1RoomLanes, (int)$eventSetupRoundJudTime, (int)$eventIDCS, 1, [], $eventTweak);

					foreach ($c1Result['placed'] as $unitKey => $slot) {
						foreach ($c1UnitsByKey[$unitKey]['payloads'] as $rowPayload) {
							$stData = explode("==", (string)$rowPayload);
							$dataST = $this->Schedulingtimings->newEmptyEntity();
							$dataST->schedule_category				= 1;
							$dataST->conventionseasons_id			= $stData[0];
							$dataST->convention_id					= $stData[1];
							$dataST->season_id						= $stData[2];
							$dataST->season_year					= $stData[3];
							$dataST->conventionregistration_id 		= $stData[4];
							$dataST->event_id 						= $stData[5];
							$dataST->event_id_number 				= $stData[6];
							$dataST->user_id 						= $stData[7];
							$dataST->group_name 					= $stData[8];
							$dataST->room_id 						= $slot['room_id'];
							$dataST->day 							= $slot['day'];
							$dataST->start_time 					= $slot['start'];
							$dataST->finish_time 					= $slot['finish'];
							$dataST->created 						= date('Y-m-d H:i:s');
							$dataST->modified 						= date('Y-m-d H:i:s');
							$dataST->user_type 						= $this->fetchUserType($stData[7]);
							$dataST->sch_date_time 					= $slot['date'].' '.$slot['start'];

							if ($this->Schedulingtimings->save($dataST)) {
								$c1RecordsCreated++;
							} else {
								$c1SaveFailures++;
								if ($c1FirstSaveError === '') {
									$c1FirstSaveError = json_encode($dataST->getErrors());
								}
							}
						}
					}

					if (count($c1Result['unscheduled']) > 0) {
						$c1WindowExceededEvents++;
						$this->recordUnscheduled(1, $eventD, $c1Result['unscheduled'], $c1UnitsByKey, (int)$eventSetupRoundJudTime, $c1RoomLanes, $eventTweak);
					}
				}
				else
				{
					$c1EventsWithoutGroups++;
				}
			
			}
			else
			{
				$c1EventsWithoutRooms++;
			}
		}

		if($c1RecordsCreated === 0 && count($allEventsCS) > 0)
		{
			$this->Flash->warning('Category 1 has no generated schedule rows yet. Possible reasons: rooms not assigned for '.$c1EventsWithoutRooms.' event(s), no grouped participants found for '.$c1EventsWithoutGroups.' event(s), or the configured day window ran out before slots could be assigned.');
		}
		if($c1EventsUsingSeasonRoomFallback > 0)
		{
			$this->Flash->warning('Category 1 used season-level room fallback for '.$c1EventsUsingSeasonRoomFallback.' event(s) because per-event room mappings were missing.');
		}
		$c1IssueLines = $this->describeCategoryOneEntryIssues($c1EntryIssues);
		if ($c1IssueLines) {
			$this->Flash->warning('Category 1 entries that need correcting by the school or admin: '.implode('; ', $c1IssueLines).'.');
		}
		$this->request->getSession()->write('Scheduling.runWarnings.category1', [
			'ungrouped' => $c1UngroupedFallbackCount,
			'skipped' => $c1SkippedWithoutSubmission,
			'entries' => $c1IssueLines,
		]);
		if($c1SaveFailures > 0)
		{
			$this->Flash->warning('Category 1 failed to save '.$c1SaveFailures.' row(s). First save error: '.$c1FirstSaveError);
		}

		$this->flushUnscheduledReport($conventionSD, 1);

		$summaryLevel = $this->categoryOneSummaryLevel(
			$c1SaveFailures,
			$c1WindowExceededEvents + $c1EventsWithoutRooms + $c1EventsWithoutGroups
				+ $c1EventsUsingSeasonRoomFallback + $c1UngroupedFallbackCount + $c1SkippedWithoutSubmission
		);
		\Cake\Log\Log::write($summaryLevel, 'C1 summary season '.$conventionSD->id.': events='.count($allEventsCS).', events_with_groups='.$c1EventsWithGroups.', attempted_rows='.$c1AttemptedRows.', window_exceeded_events='.$c1WindowExceededEvents.', created='.$c1RecordsCreated.', save_failures='.$c1SaveFailures.', no_rooms='.$c1EventsWithoutRooms.', no_groups='.$c1EventsWithoutGroups.', season_room_fallback='.$c1EventsUsingSeasonRoomFallback.', ungrouped_fallback='.$c1UngroupedFallbackCount.', skipped_without_submission='.$c1SkippedWithoutSubmission.', first_save_error='.$c1FirstSaveError);
		if ($c1SaveFailures === 0 && $c1WindowExceededEvents === 0) {
			$this->judgeScheduleStage((int)$conventionSD->id, 1, true);
		}
		
		//exit;
		
		//$this->Flash->success($msgSuccess);
		return $this->redirect(['controller' => 'schedulingtimings', 'action' => 'startschedulec2', $convention_season_slug]);
		
    }

	/**
	 * Approved combine requests as [event][school user] => partner school users.
	 */
	private function categoryOneSummaryLevel(int $saveFailures, int $warnings): string {
		if ($saveFailures > 0) {
			return 'error';
		}
		return $warnings > 0 ? 'warning' : 'info';
	}

	private function loadCategoryOneCombinedPairs($conventionSD): array {
		$combineRequests = $this->fetchTable('Combinerequests')->find()
			->where([
				'Combinerequests.conventionseason_id' => $conventionSD->id,
				'Combinerequests.convention_id' => $conventionSD->convention_id,
				'Combinerequests.season_id' => $conventionSD->season_id,
				'Combinerequests.season_year' => $conventionSD->season_year,
				'Combinerequests.status' => 1,
			])
			->all();

		$pairs = [];
		foreach ($combineRequests as $combineRequest) {
			$rightUserId = (int)$combineRequest->combine_with_user_id;
			$eventId = (int)$combineRequest->event_id;
			if ((int)$combineRequest->conventionregistration_id <= 0 || $rightUserId <= 0 || $eventId <= 0) {
				continue;
			}
			$leftReg = $this->Conventionregistrations->find()
				->select(['id', 'user_id'])
				->where(['Conventionregistrations.id' => (int)$combineRequest->conventionregistration_id])
				->first();
			$leftUserId = $leftReg ? (int)$leftReg->user_id : 0;
			if ($leftUserId <= 0 || $leftUserId === $rightUserId) {
				continue;
			}
			$pairs[$eventId][$leftUserId][$rightUserId] = $rightUserId;
			$pairs[$eventId][$rightUserId][$leftUserId] = $leftUserId;
		}

		return $pairs;
	}

	/**
	 * Category 1 placement units. Approved combined schools with the same group name
	 * become one unit, so they share one checked slot and room lane.
	 */
	private function buildCategoryOneUnits($conventionSD, int $eventId, array $payloads, array $combinedPairs): array {
		$payloadByKey = [];
		foreach ($payloads as $payload) {
			$parts = explode('==', (string)$payload);
			$payloadByKey[(int)$parts[7].'|'.$parts[8]] = $payload;
		}

		$units = [];
		$assigned = [];
		foreach ($payloads as $payload) {
			$parts = explode('==', (string)$payload);
			$key = (int)$parts[7].'|'.$parts[8];
			if (isset($assigned[$key])) {
				continue;
			}
			// Collect every school linked through approved combine requests for this group name.
			$members = [];
			$queue = [(int)$parts[7]];
			while ($queue) {
				$schoolId = array_shift($queue);
				$memberKey = $schoolId.'|'.$parts[8];
				if (isset($members[$memberKey]) || !isset($payloadByKey[$memberKey]) || isset($assigned[$memberKey])) {
					continue;
				}
				$members[$memberKey] = $payloadByKey[$memberKey];
				foreach ($combinedPairs[$eventId][$schoolId] ?? [] as $partnerId) {
					$queue[] = (int)$partnerId;
				}
			}

			$students = [];
			$labels = [];
			foreach ($members as $memberKey => $memberPayload) {
				$assigned[$memberKey] = true;
				[$schoolId, $groupName] = explode('|', $memberKey, 2);
				$students = array_merge($students, $this->groupMemberIds($conventionSD, (int)$schoolId, $eventId, $groupName));
				$labels[] = 'school '.$schoolId.' group '.$groupName;
			}
			$units[] = [
				'id' => 'c1-'.count($units),
				'students' => array_values(array_unique($students)),
				'possible' => [],
				'payloads' => array_values($members),
				'label' => implode(' + ', $labels),
			];
		}

		return $units;
	}
	
	
	public function startschedulec2($convention_season_slug=null) {
		
		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		$this->judgeScheduleStage((int)$conventionSD->id, 2, false);
		
		//$this->prx($conventionSD);
		
		// to get details of schedule timings
		$schedulingsD = $this->resolveSchedulingWizardRecord($conventionSD);
		if ($redirect = $this->ensureSchedulingWindowIsValid($schedulingsD, $convention_season_slug)) {
			return $redirect;
		}
		$this->applyScheduleSeed($conventionSD, $schedulingsD, 2);
		$first_day 				= $schedulingsD->first_day;
		$normal_starting_time 	= $this->normalizeWizardTime($schedulingsD->normal_starting_time);
		$normal_finish_time 	= $this->normalizeWizardTime($schedulingsD->normal_finish_time);
		
		$lunch_time_start 		= $this->normalizeWizardTime($schedulingsD->lunch_time_start);
		$lunch_time_end 		= $this->normalizeWizardTime($schedulingsD->lunch_time_end);
		
		$start_date 			= date("Y-m-d",strtotime($schedulingsD->start_date));
		
		
		$starting_different_time_first_day_yes_no = $schedulingsD->starting_different_time_first_day_yes_no;
		if($starting_different_time_first_day_yes_no == 1)
		{
			$different_first_day_start_time = $this->normalizeWizardTime($schedulingsD->different_first_day_start_time);
			$different_first_day_end_time 	= $this->normalizeWizardTime($schedulingsD->different_first_day_end_time);
		}
		
		/* TO GET ALL THE EVENTS WITH FOLLOWING CONDITIONS */
		// group_event = no || event_kind_id = Elimination || needs_schedule = 1 || has_to_be_consecutive = no
		$arrEventsC2 = array();
		$eventsC2 = $this->Conventionseasonevents->find()
			->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonevents'))
			->all();
		foreach($eventsC2 as $eventc2)
		{
			$eventD = $this->Events->find()->where(['Events.id' => $eventc2->event_id])->first();
			if($eventD->needs_schedule == '1' && $eventD->group_event_yes_no == '0' && $eventD->event_kind_id == 'Elimination' && $eventD->has_to_be_consecutive == '0')
			{
				$arrEventsC2[] = $eventD->id;
			}
		}
		//$this->pr($arrEventsC2);
		
		/* NOW GET STUDENTS FOR EACH EVENT */
		$arrStudentsC2 = array();
		foreach($arrEventsC2 as $event_id_c2)
		{
			$studentsQueryC2 = $this->Conventionregistrationstudents->find()->where([
				'Conventionregistrationstudents.convention_id' => (int)$conventionSD->convention_id,
				'Conventionregistrationstudents.season_id' => (int)$conventionSD->season_id,
				'Conventionregistrationstudents.season_year' => (int)$conventionSD->season_year,
				'Conventionregistrationstudents.status' => 1,
				'Conventionregistrationstudents.student_id >' => 0,
			]);
			$studentsC2 = $this->whereCsvContainsEventId($studentsQueryC2, 'Conventionregistrationstudents.event_ids', (int)$event_id_c2)->all();
			
			if($studentsC2)
			{
				foreach($studentsC2 as $studentEV)
				{
					$arrStudentsC2[$event_id_c2][] = $studentEV->student_id;
				}
			}
		}
		//$this->prx($arrStudentsC2);
		

		/* NOW FETCH STUDENTS FOR EACH EVENT AND PERFORM SCHEDULING */
		foreach($arrStudentsC2 as $event_id_c2 => $studentsListC2)
		{	
			// to get event details
			$eventD = $this->Events->find()->where(['Events.id' => $event_id_c2])->first();
			
			// now check that if any room is allocated for this event
			$roomQueryC2 = $this->Conventionseasonroomevents->find()
				->select(['room_id', 'number_games'])
				->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonroomevents'));
			$roomCSEvent = $this->whereCsvContainsEventId($roomQueryC2, 'Conventionseasonroomevents.event_ids', (int)$event_id_c2)->all();
			$roomArrCSEvent = array();
			foreach($roomCSEvent as $roomeventcs)
			{
				$roomId = (int)$roomeventcs->room_id;
				$gamesAtOnce = isset($roomeventcs->number_games) ? (int)$roomeventcs->number_games : 1;
				if($gamesAtOnce <= 0)
				{
					$gamesAtOnce = 1;
				}
				for($gameCounter = 0; $gameCounter < $gamesAtOnce; $gameCounter++)
				{
					$roomArrCSEvent[] = $roomId;
				}
			}

			// Apply saved tweaks (pinned day / room / start time) for this elimination event.
			$eventTweak2 = $this->Schedulingeventtweaks->find()
				->where([
					'Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
					'Schedulingeventtweaks.event_id' => $event_id_c2
				])
				->first();
			if ($eventTweak2 && $eventTweak2->pinned_room_id) {
				$roomArrCSEvent = [(int)$eventTweak2->pinned_room_id];
			}
			$eventBandsC2 = $this->buildEliminationEventBands($schedulingsD, $eventTweak2);
			$defaultBandC2 = !empty($eventBandsC2) ? $eventBandsC2[0] : null;
			$byeDayC2 = $defaultBandC2['day'] ?? $first_day;
			$byeStartTimeC2 = $defaultBandC2['start'] ?? ($starting_different_time_first_day_yes_no == 1 ? $different_first_day_start_time : $normal_starting_time);
			$byeDateC2 = $defaultBandC2['date'] ?? $start_date;
			//$this->prx($roomArrCSEvent);
			
			// shuffle array
			shuffle($studentsListC2);
			
			$totalStudentsEV 			= count($studentsListC2);
			$totalByePlayer 			= $this->getByePlayerScheduling($totalStudentsEV);
			$arrStudentsForSplice 		= $studentsListC2;
			
			//echo $totalByePlayer;exit;
			
			$match_number = 1;
			/* DEFINE SCHEDULING FOR BYE PLAYERS */
			if($totalByePlayer>0)
			{
				$arrByePlayer 			= array();
				
				// pick number of random players for bye
				for($cntrByeP=0;$cntrByeP<$totalByePlayer;$cntrByeP++)
				{
					// generate a random number from 0 to total count of students
					$randByeNumber 		= rand(0,count($arrStudentsForSplice)-1);
					$byeStudentID 		= $arrStudentsForSplice[$randByeNumber];
					$arrByePlayer[] 	= $byeStudentID;
					array_splice($arrStudentsForSplice, $randByeNumber, 1);
					
					/* Here we will check that this user_id is School Or student 
					School means its a group event
					Student means it's an individual event
					*/
					$fetchUserType = $this->fetchUserType($byeStudentID);
					
					//now save bye player in database, opponent of bye player id will be 0
					$schedulingtimings = $this->Schedulingtimings->newEntity([]);
					$dataBye = $this->Schedulingtimings->patchEntity($schedulingtimings, array());

					$dataBye->schedule_category				= 2;
					$dataBye->conventionseasons_id			= $conventionSD->id;
					$dataBye->convention_id					= $conventionSD->convention_id;
					$dataBye->season_id						= $conventionSD->season_id;
					$dataBye->season_year 					= $conventionSD->season_year;
					$dataBye->conventionregistration_id 	= NULL;
					$dataBye->event_id 						= $event_id_c2;
					$dataBye->event_id_number 				= $eventD->event_id_number;
					$dataBye->user_id 						= $byeStudentID;
					$dataBye->group_name 					= NULL;
					$dataBye->room_id 						= $roomArrCSEvent[0];
					$dataBye->day 							= $byeDayC2;
					$dataBye->start_time 					= $byeStartTimeC2;
					$dataBye->finish_time 					= $byeStartTimeC2;
					$dataBye->user_id_opponent 				= 0;
					$dataBye->round_number 					= 1;
					$dataBye->match_number 					= $match_number;
					$dataBye->is_bye 						= 1;
					$dataBye->created 						= date('Y-m-d H:i:s');
					
					$dataBye->sch_date_time 				= $byeDateC2.' '.date("H:i:s", strtotime($dataBye->start_time));
					
					$dataBye->user_type 					= $fetchUserType;

					$resultBye = $this->Schedulingtimings->saveOrFail($dataBye);
					
					$match_number++;
				}
			}
			
			$totalRoomsForThisEvent = count((array)$roomArrCSEvent);
			// now firstly choose first room
			$cntrRoomCSEvent = 0;
			$cntrEVSCH = 0;
			
			//$this->prx($arrStudentsForSplice);
			
			/* DEFINE SCHEDULING FOR REMAINING PLAYERS AFTER BYE PLAYERS */
			// To check how many matches are there
			$totalMatches = ($totalStudentsEV-$totalByePlayer)/2;
			for($cntrRemainP=0;$cntrRemainP<$totalMatches;$cntrRemainP++)
			{
				// to get first player id
				$randFirstP 				= rand(0,count((array)$arrStudentsForSplice)-1);
				$first_student_id 			= $arrStudentsForSplice[$randFirstP];
				array_splice($arrStudentsForSplice, $randFirstP, 1);
				
				// to get opponent user id
				$randSecondP 				= rand(0,count((array)$arrStudentsForSplice)-1);
				$second_student_id 			= $arrStudentsForSplice[$randSecondP];
				array_splice($arrStudentsForSplice, $randSecondP, 1);
				
				/* Here we will check that this user_id is School Or student 
				School means its a group event
				Student means it's an individual event
				*/
				$fetchUserType = $this->fetchUserType($first_student_id);
				
				//now save remaining player in database with opponent user id
				$schedulingtimings = $this->Schedulingtimings->newEntity([]);
				$dataBye = $this->Schedulingtimings->patchEntity($schedulingtimings, array());

				$dataBye->schedule_category				= 2;
				$dataBye->conventionseasons_id			= $conventionSD->id;
				$dataBye->convention_id					= $conventionSD->convention_id;
				$dataBye->season_id						= $conventionSD->season_id;
				$dataBye->season_year 					= $conventionSD->season_year;
				$dataBye->conventionregistration_id 	= NULL;
				$dataBye->event_id 						= $event_id_c2;
				$dataBye->event_id_number 				= $eventD->event_id_number;
				$dataBye->user_id 						= $first_student_id;
				$dataBye->group_name 					= NULL;
				$dataBye->room_id 						= (int)$roomArrCSEvent[$cntrRoomCSEvent];
				$dataBye->day 							= NULL;
				$dataBye->start_time 					= NULL;
				$dataBye->finish_time 					= NULL;
				$dataBye->user_id_opponent 				= $second_student_id;
				$dataBye->round_number 					= 1;
				$dataBye->match_number 					= $match_number;
				$dataBye->is_bye 						= 0;
				$dataBye->created 						= date('Y-m-d H:i:s');
				
				$dataBye->sch_date_time 				= $start_date.' 00:00:00';
				
				$dataBye->user_type 					= $fetchUserType;

				$resultBye = $this->Schedulingtimings->saveOrFail($dataBye);
				
				$match_number++;
				
				$cntrEVSCH++;
			}
		}
		
		
		
		
		/* After first round, we need to schedule next rounds till last round between 2 players */
		// Get all matches for each event and perform scheduling 'Schedulingtimings.schedule_category' => 2
		foreach($arrEventsC2 as $event_id_c2)
		{
			// to get event details
			$eventD = $this->Events->find()->where(['Events.id' => $event_id_c2])->first();
			
			// Later-round placeholders must use this event's mapped room, not a leftover room from the previous event.
			$roomQueryC2Round = $this->Conventionseasonroomevents->find()
				->select(['room_id', 'number_games'])
				->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonroomevents'));
			$roomCSEvent = $this->whereCsvContainsEventId($roomQueryC2Round, 'Conventionseasonroomevents.event_ids', (int)$event_id_c2)->all();
			$roomArrCSEvent = array();
			foreach($roomCSEvent as $roomeventcs)
			{
				$roomId = (int)$roomeventcs->room_id;
				$gamesAtOnce = isset($roomeventcs->number_games) ? (int)$roomeventcs->number_games : 1;
				if($gamesAtOnce <= 0)
				{
					$gamesAtOnce = 1;
				}
				for($gameCounter = 0; $gameCounter < $gamesAtOnce; $gameCounter++)
				{
					$roomArrCSEvent[] = $roomId;
				}
			}

			$eventTweak2Round = $this->Schedulingeventtweaks->find()
				->where([
					'Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
					'Schedulingeventtweaks.event_id' => $event_id_c2
				])
				->first();
			if ($eventTweak2Round && $eventTweak2Round->pinned_room_id) {
				$roomArrCSEvent = [(int)$eventTweak2Round->pinned_room_id];
			}
			
			// Rounds 2..Final (+ 3rd place) are planned once, so the loop cannot run past the Final.
			$roomIdForRound = isset($roomArrCSEvent[0]) ? (int)$roomArrCSEvent[0] : null;
			$this->createKnockoutLaterRounds(2, $conventionSD, $eventD, (int)$event_id_c2, $roomIdForRound, $start_date);
		}
		
		//exit;
		
		
		
		/* IN ABOVE CODE, WE DEFINE SCHEDULING BUT NOT DEFINED DAY (EXCEPT BYE), START AND END TIME */
		/* IN BELOW CODE WE WILL FETCH THIS SCHEDULING AGAIN FOR EACH EVENT ONE BY ONE AND DEFINE 
		DAY, START TIME AND END TIME */
		
		//exit;
		
		$arrEventsC2 = $this->orderEventsForStrategy($conventionSD, $schedulingsD, 2, $arrEventsC2);
		foreach($arrEventsC2 as $event_id)
		{
			// to get event details
			$eventD = $this->Events->find()->where(['Events.id' => $event_id])->first();
			
			// to calculate event execution time
			$eventSetupRoundJudTime 	= $eventD->setup_time+$eventD->round_time+$eventD->judging_time;
			
			// now check that if any room is allocated for this event
			$roomQueryC2Sched = $this->Conventionseasonroomevents->find()
				->select(['room_id', 'number_games'])
				->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonroomevents'));
			$roomCSEvent = $this->whereCsvContainsEventId($roomQueryC2Sched, 'Conventionseasonroomevents.event_ids', (int)$event_id)->all();
			$roomArrCSEvent = array();
			foreach($roomCSEvent as $roomeventcs)
			{
				$roomId = (int)$roomeventcs->room_id;
				$gamesAtOnce = isset($roomeventcs->number_games) ? (int)$roomeventcs->number_games : 1;
				if($gamesAtOnce <= 0)
				{
					$gamesAtOnce = 1;
				}
				for($gameCounter = 0; $gameCounter < $gamesAtOnce; $gameCounter++)
				{
					$roomArrCSEvent[] = $roomId;
				}
			}

			$eventTweak2Schedule = $this->Schedulingeventtweaks->find()
				->where([
					'Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
					'Schedulingeventtweaks.event_id' => $event_id
				])
				->first();
			if ($eventTweak2Schedule && $eventTweak2Schedule->pinned_room_id) {
				$roomArrCSEvent = [(int)$eventTweak2Schedule->pinned_room_id];
			}
			
			//$this->prx($roomArrCSEvent);
			
			
			// Events without rooms still go through the planner so their matches are reported as unscheduled.
			{
				// now get all scheduling timings except BYE for this convention season
				$schedulingT = $this->Schedulingtimings->find()
					->where($this->seasonScopeConditions($conventionSD, 'Schedulingtimings'))
					->andWhere([
						'Schedulingtimings.schedule_category' => 2,
						'Schedulingtimings.is_bye' => 0,
						'Schedulingtimings.event_id' => (int)$event_id,
					])
					->order([
					"Schedulingtimings.round_number" => "ASC",
					"Schedulingtimings.match_number" => "DESC",
					"Schedulingtimings.id" => "ASC",
				])->all();
				//$this->prx($schedulingT);
				$eventBandsC2 = $this->buildEliminationEventBands($schedulingsD, $eventTweak2Schedule);
				$pendingRows = [];
				$roundMatchCountMap = [];
				foreach ($schedulingT as $schdata) {
					$pendingRows[] = $schdata;
					$roundNumberKey = (int)($schdata->round_number ?? 0);
					if ($roundNumberKey > 0) {
						$roundMatchCountMap[$roundNumberKey] = ($roundMatchCountMap[$roundNumberKey] ?? 0) + 1;
					}
				}
				$roomLanesC2 = array_count_values(array_map('intval', (array)$roomArrCSEvent));
				$knockoutRowsC2 = $this->knockoutRowsForEvent($conventionSD, 2, (int)$event_id);
				$unitsC2 = $this->buildKnockoutUnits($eventD, $pendingRows, $knockoutRowsC2, $roundMatchCountMap);
				$resultC2 = $this->placeEventWithStrategy($conventionSD, $schedulingsD, $unitsC2, $eventBandsC2, $roomLanesC2, (int)$eventSetupRoundJudTime, (int)$event_id, 1, $knockoutRowsC2, $eventTweak2Schedule);
				$this->savePlacedRows($resultC2['placed']);
				if (count($resultC2['unscheduled']) > 0) {
					$unitsC2ByKey = [];
					foreach ($unitsC2 as $unitC2) {
						$unitC2['label'] = 'match row '.$unitC2['row_id'];
						$unitC2['row_ids'] = [$unitC2['row_id']];
						$unitsC2ByKey[$unitC2['id']] = $unitC2;
					}
					$this->recordUnscheduled(2, $eventD, $resultC2['unscheduled'], $unitsC2ByKey, (int)$eventSetupRoundJudTime, $roomLanesC2, $eventTweak2Schedule);
				}
			
			}
			
			//exit;
			
			
		}
		
		//exit;
		
		$this->flushUnscheduledReport($conventionSD, 2);
		$this->judgeScheduleStage((int)$conventionSD->id, 2, true);
		//$this->Flash->success('Scheduling completed successfully for category 2.');
		return $this->redirect(['controller' => 'schedulingtimings', 'action' => 'startschedulec3', $convention_season_slug]);
		
	}
	
	
	public function startschedulec3($convention_season_slug=null) {
		
		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		$this->judgeScheduleStage((int)$conventionSD->id, 3, false);
		
		//$this->prx($conventionSD);
		
		// to get details of schedule timings
		$schedulingsD = $this->resolveSchedulingWizardRecord($conventionSD);
		if ($redirect = $this->ensureSchedulingWindowIsValid($schedulingsD, $convention_season_slug)) {
			return $redirect;
		}
		$this->applyScheduleSeed($conventionSD, $schedulingsD, 3);
		$first_day 				= $schedulingsD->first_day;
		
		$normal_starting_time 	= $this->normalizeWizardTime($schedulingsD->normal_starting_time);
		$normal_finish_time 	= $this->normalizeWizardTime($schedulingsD->normal_finish_time);
		
		$lunch_time_start 		= $this->normalizeWizardTime($schedulingsD->lunch_time_start);
		$lunch_time_end 		= $this->normalizeWizardTime($schedulingsD->lunch_time_end);
		
		$start_date 			= date("Y-m-d",strtotime($schedulingsD->start_date));
		
		$starting_different_time_first_day_yes_no = $schedulingsD->starting_different_time_first_day_yes_no;
		if($starting_different_time_first_day_yes_no == 1)
		{
			$different_first_day_start_time = $this->normalizeWizardTime($schedulingsD->different_first_day_start_time);
			$different_first_day_end_time 	= $this->normalizeWizardTime($schedulingsD->different_first_day_end_time);
		}
		
		
		/* TO GET ALL THE EVENTS WITH FOLLOWING CONDITIONS */
		// group_event = yes || event_kind_id = Elimination || needs_schedule = 1 || has_to_be_consecutive = no
		$arrEventsC3 = array();
		$eventsC3 = $this->Conventionseasonevents->find()
			->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonevents'))
			->all();
		foreach($eventsC3 as $eventc3)
		{
			$eventD = $this->Events->find()->where(['Events.id' => $eventc3->event_id])->first();
			if($eventD->needs_schedule == '1' && $eventD->group_event_yes_no == '1' && $eventD->event_kind_id == 'Elimination' && $eventD->has_to_be_consecutive == '0')
			{
				$arrEventsC3[] = $eventD->id;
			}
		}
		//$this->prx($arrEventsC3);
		/* $arrEventsC3 = array();
		$arrEventsC3[] = 63;
		$arrEventsC3[] = 107; */
		//$arrEventsC3 = array();
		//$arrEventsC3[] = 65;
		//$this->prx($arrEventsC3);
		
		
		$eventCTR = 0;
		// Now run loop on each event and get groups and schedule
		foreach($arrEventsC3 as $event_id_c3)
		{
			/* PART 1 OF THIS EVENT */
			
			$mainArrForEvent = array();
			
			// to get event details
			$eventD = $this->Events->find()->where(['Events.id' => $event_id_c3])->first();
			
			// now check that if any room is allocated for this event
			$roomQueryC3 = $this->Conventionseasonroomevents->find()
				->select(['room_id', 'number_games'])
				->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonroomevents'));
			$roomCSEvent = $this->whereCsvContainsEventId($roomQueryC3, 'Conventionseasonroomevents.event_ids', (int)$event_id_c3)->all();
			$roomArrCSEvent = array();
			foreach($roomCSEvent as $roomeventcs)
			{
				$roomId = (int)$roomeventcs->room_id;
				$gamesAtOnce = isset($roomeventcs->number_games) ? (int)$roomeventcs->number_games : 1;
				if($gamesAtOnce <= 0)
				{
					$gamesAtOnce = 1;
				}
				for($gameCounter = 0; $gameCounter < $gamesAtOnce; $gameCounter++)
				{
					$roomArrCSEvent[] = $roomId;
				}
			}

			$eventTweak3 = $this->Schedulingeventtweaks->find()
				->where([
					'Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
					'Schedulingeventtweaks.event_id' => $event_id_c3
				])
				->first();
			if ($eventTweak3 && $eventTweak3->pinned_room_id) {
				$roomArrCSEvent = [(int)$eventTweak3->pinned_room_id];
			}
			$eventBandsC3 = $this->buildEliminationEventBands($schedulingsD, $eventTweak3);
			$defaultBandC3 = !empty($eventBandsC3) ? $eventBandsC3[0] : null;
			$byeDayC3 = $defaultBandC3['day'] ?? $first_day;
			$byeStartTimeC3 = $defaultBandC3['start'] ?? ($starting_different_time_first_day_yes_no == 1 ? $different_first_day_start_time : $normal_starting_time);
			$byeDateC3 = $defaultBandC3['date'] ?? $start_date;
			//$this->prx($roomArrCSEvent);
			 
			// now get groups for this event from convention registration
			// First to fetch conv registrations
			$conventionRegistrations = $this->Conventionregistrations->find()->where([
				'Conventionregistrations.conventionseason_id' => (int)$conventionSD->id,
				'Conventionregistrations.convention_id' => (int)$conventionSD->convention_id,
			])->all();
			foreach($conventionRegistrations as $convreg)
			{
				//now fetch groups for this CR
				$convRegSTEV = $this->Crstudentevents->find()->where([
					'Crstudentevents.conventionseason_id' => (int)$conventionSD->id,
					'Crstudentevents.convention_id' => (int)$conventionSD->convention_id,
					'Crstudentevents.conventionregistration_id' => (int)$convreg->id,
					'Crstudentevents.event_id' => (int)$event_id_c3,
					'Crstudentevents.group_name !=' => '',
				])->select(['group_name'])->all();
				//$this->prx($convRegSTEV);
				if($convRegSTEV)
				{
					// if any group exists, then push it to array
					foreach($convRegSTEV as $convregstev)
					{
						// now create a variable with combination 
						// format is conventionseasons_id==convention_id==season_id==season_year==conventionregistration_id==event_id==event_id_number==user_id==group_name
						
						$varEventCombination 	= $conventionSD->id."==";
						$varEventCombination 	.= $conventionSD->convention_id."==";
						$varEventCombination 	.= $conventionSD->season_id."==";
						$varEventCombination 	.= $conventionSD->season_year."==";
						$varEventCombination 	.= $convreg->id."==";
						$varEventCombination	.= $event_id_c3."==";
						$varEventCombination 	.= $eventD->event_id_number."==";
						$varEventCombination 	.= $convreg->user_id."==";
						$varEventCombination 	.= $convregstev->group_name;
						
						if(!in_array($varEventCombination,(array)$mainArrForEvent))
						{
							$mainArrForEvent[] = $varEventCombination;
						}
					}
				}
			}
			
			//$this->prx($mainArrForEvent);
			
			
			
			if(count((array)$mainArrForEvent))
			{
				shuffle($mainArrForEvent);
				
				// now get total bye groups
				$totalGroupsEV 				= count($mainArrForEvent);
				$totalByeGroup 				= $this->getByePlayerScheduling($totalGroupsEV);
				$arrGroupsForSplice 		= $mainArrForEvent;
				
				//$this->prx($arrGroupsForSplice);
				
				//echo $totalByeGroup;exit;
				
				$match_number = 1;
				/* DEFINE SCHEDULING FOR BYE GROUPS */
				if($totalByeGroup>0)
				{
					// pick number of random players for bye
					for($cntrByeP=0;$cntrByeP<$totalByeGroup;$cntrByeP++)
					{
						// generate a random number from 0 to total count of students
						$randByeNumber 		= rand(0,count($arrGroupsForSplice)-1);
						
						// now explode data from array to get al details
						$dataGExplode = explode("==",$arrGroupsForSplice[$randByeNumber]);
						//$this->prx($dataGExplode);
						
						array_splice($arrGroupsForSplice, $randByeNumber, 1);
						
						/* Here we will check that this user_id is School Or student 
						School means its a group event
						Student means it's an individual event
						*/
						$fetchUserType = $this->fetchUserType($dataGExplode[7]);
						
						
						
						//now save bye player in database, opponent of bye player id will be 0
						$schedulingtimings = $this->Schedulingtimings->newEntity([]);
						$dataBye = $this->Schedulingtimings->patchEntity($schedulingtimings, array());

						$dataBye->schedule_category				= 3;
						$dataBye->conventionseasons_id			= $conventionSD->id;
						$dataBye->convention_id					= $conventionSD->convention_id;
						$dataBye->season_id						= $conventionSD->season_id;
						$dataBye->season_year 					= $conventionSD->season_year;
						$dataBye->conventionregistration_id 	= $dataGExplode[4];
						$dataBye->event_id 						= $eventD->id;
						$dataBye->event_id_number 				= $eventD->event_id_number;
						$dataBye->user_id 						= $dataGExplode[7];
						$dataBye->group_name 					= $dataGExplode[8];
						$dataBye->room_id 						= $roomArrCSEvent[0];
						$dataBye->day 							= $byeDayC3;
						$dataBye->start_time 					= $byeStartTimeC3;
						$dataBye->finish_time 					= $byeStartTimeC3;
						$dataBye->user_id_opponent 				= 0;
						$dataBye->round_number 					= 1;
						$dataBye->match_number 					= $match_number;
						$dataBye->is_bye 						= 1;
						$dataBye->created 						= date('Y-m-d H:i:s');
						
						$dataBye->sch_date_time 				= $byeDateC3.' '.date("H:i:s", strtotime($dataBye->start_time));
						
						$dataBye->user_type 					= $fetchUserType;

						$resultBye = $this->Schedulingtimings->saveOrFail($dataBye);
						
						$match_number++;
					}
				}
				
				//$this->prx($arrGroupsForSplice);
				
				/* DEFINE SCHEDULING FOR REMAINING PLAYERS AFTER BYE PLAYERS */
				// To check how many matches are there
				$totalMatches = ($totalGroupsEV-$totalByeGroup)/2;
				for($cntrRemainP=0;$cntrRemainP<$totalMatches;$cntrRemainP++)
				{
					// to get first group id
					$randFirstP 				= rand(0,count((array)$arrGroupsForSplice)-1);
					// now explode data to get info
					$dataGExplodeFirst = explode("==",$arrGroupsForSplice[$randFirstP]);
					array_splice($arrGroupsForSplice, $randFirstP, 1);
					
					
					// to get opponent group id
					$randSecondP 				= rand(0,count((array)$arrGroupsForSplice)-1);
					// now explode data to get info
					$dataGExplodeSecond = explode("==",$arrGroupsForSplice[$randSecondP]);
					array_splice($arrGroupsForSplice, $randSecondP, 1);
					
					/* Here we will check that this user_id is School Or student 
					School means its a group event
					Student means it's an individual event
					*/
					$fetchUserType = $this->fetchUserType($dataGExplodeFirst[7]);
					
					//now save remaining player in database with opponent user id
					$schedulingtimings = $this->Schedulingtimings->newEntity([]);
					$dataBye = $this->Schedulingtimings->patchEntity($schedulingtimings, array());

					$dataBye->schedule_category				= 3;
					$dataBye->conventionseasons_id			= $conventionSD->id;
					$dataBye->convention_id					= $conventionSD->convention_id;
					$dataBye->season_id						= $conventionSD->season_id;
					$dataBye->season_year 					= $conventionSD->season_year;
					$dataBye->conventionregistration_id 	= NULL;
					$dataBye->event_id 						= $eventD->id;
					$dataBye->event_id_number 				= $eventD->event_id_number;
					$dataBye->user_id 						= $dataGExplodeFirst[7];
					$dataBye->group_name 					= $dataGExplodeFirst[8];
					$dataBye->room_id 						= NULL;
					$dataBye->day 							= NULL;
					$dataBye->start_time 					= NULL;
					$dataBye->finish_time 					= NULL;
					$dataBye->user_id_opponent 				= $dataGExplodeSecond[7];
					$dataBye->group_name_opponent 			= $dataGExplodeSecond[8];
					$dataBye->round_number 					= 1;
					$dataBye->match_number 					= $match_number;
					$dataBye->is_bye 						= 0;
					$dataBye->created 						= date('Y-m-d H:i:s');
					
					$dataBye->sch_date_time 				= $start_date.' 00:00:00';
					
					$dataBye->user_type 					= $fetchUserType;

					$resultBye = $this->Schedulingtimings->saveOrFail($dataBye);
					
					$match_number++;
					
				}
			}
			
			
			
			
			
			
			/* PART 2 OF THIS EVENT */
			
			/* After first round, we need to schedule next rounds till last round between 2 players */
			// Get all matches for each event and perform scheduling 'Schedulingtimings.schedule_category' => 3
			
			// Rounds 2..Final (+ 3rd place) are planned once, so the loop cannot run past the Final.
			$this->createKnockoutLaterRounds(3, $conventionSD, $eventD, (int)$event_id_c3, null, $start_date);
			
			
			
			
			
			
			
			
			
			/* PART 3 OF THIS EVENT */
			
			/* IN ABOVE CODE, WE DEFINE SCHEDULING BUT NOT DEFINED DAY (EXCEPT BYE), START AND END TIME */
			/* IN BELOW CODE WE WILL FETCH THIS SCHEDULING AGAIN FOR EACH EVENT ONE BY ONE AND DEFINE 
			DAY, START TIME AND END TIME */
			
			$event_id = $event_id_c3;
			
			// to calculate event execution time
			$eventSetupRoundJudTime 	= $eventD->setup_time+$eventD->round_time+$eventD->judging_time;
			
			// now check that if any room is allocated for this event
			$roomQueryC3Sched = $this->Conventionseasonroomevents->find()
				->select(['room_id', 'number_games'])
				->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonroomevents'));
			$roomCSEvent = $this->whereCsvContainsEventId($roomQueryC3Sched, 'Conventionseasonroomevents.event_ids', (int)$event_id)->all();
			$roomArrCSEvent = array();
			foreach($roomCSEvent as $roomeventcs)
			{
				$roomId = (int)$roomeventcs->room_id;
				$gamesAtOnce = isset($roomeventcs->number_games) ? (int)$roomeventcs->number_games : 1;
				if($gamesAtOnce <= 0)
				{
					$gamesAtOnce = 1;
				}
				for($gameCounter = 0; $gameCounter < $gamesAtOnce; $gameCounter++)
				{
					$roomArrCSEvent[] = $roomId;
				}
			}
			$eventTweak3Schedule = $this->Schedulingeventtweaks->find()
				->where([
					'Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
					'Schedulingeventtweaks.event_id' => $event_id
				])
				->first();
			if ($eventTweak3Schedule && $eventTweak3Schedule->pinned_room_id) {
				$roomArrCSEvent = [(int)$eventTweak3Schedule->pinned_room_id];
			}
			
			
			// Events without rooms still go through the planner so their matches are reported as unscheduled.
			{
				// now get all scheduling timings except BYE for this convention season
				$schedulingT = $this->Schedulingtimings->find()
					->where($this->seasonScopeConditions($conventionSD, 'Schedulingtimings'))
					->andWhere([
						'Schedulingtimings.schedule_category' => 3,
						'Schedulingtimings.is_bye' => 0,
						'Schedulingtimings.event_id' => (int)$event_id,
					])
					->order([
					"Schedulingtimings.round_number" => "ASC",
					"Schedulingtimings.match_number" => "DESC",
					"Schedulingtimings.id" => "ASC",
				])->all();
				//$this->prx($schedulingT);
				$eventBandsC3 = $this->buildEliminationEventBands($schedulingsD, $eventTweak3Schedule);
				$pendingRows = [];
				$roundMatchCountMap = [];
				foreach ($schedulingT as $schdata) {
					$pendingRows[] = $schdata;
					$roundNumberKey = (int)($schdata->round_number ?? 0);
					if ($roundNumberKey > 0) {
						$roundMatchCountMap[$roundNumberKey] = ($roundMatchCountMap[$roundNumberKey] ?? 0) + 1;
					}
				}
				$roomLanesC3 = array_count_values(array_map('intval', (array)$roomArrCSEvent));
				$knockoutRowsC3 = $this->knockoutRowsForEvent($conventionSD, 3, (int)$event_id);
				$unitsC3 = $this->buildKnockoutUnits($eventD, $pendingRows, $knockoutRowsC3, $roundMatchCountMap);
				$resultC3 = $this->placeEventWithStrategy($conventionSD, $schedulingsD, $unitsC3, $eventBandsC3, $roomLanesC3, (int)$eventSetupRoundJudTime, (int)$event_id, 1, $knockoutRowsC3, $eventTweak3Schedule);
				$this->savePlacedRows($resultC3['placed']);
				if (count($resultC3['unscheduled']) > 0) {
					$unitsC3ByKey = [];
					foreach ($unitsC3 as $unitC3) {
						$unitC3['label'] = 'match row '.$unitC3['row_id'];
						$unitC3['row_ids'] = [$unitC3['row_id']];
						$unitsC3ByKey[$unitC3['id']] = $unitC3;
					}
					$this->recordUnscheduled(3, $eventD, $resultC3['unscheduled'], $unitsC3ByKey, (int)$eventSetupRoundJudTime, $roomLanesC3, $eventTweak3Schedule);
				}
			
			}
			
			//echo '<hr>';
			
		$eventCTR++;	
			
		}
		
		//exit;
		//echo $cntrEVSCH;exit;
		
		$this->flushUnscheduledReport($conventionSD, 3);
		$this->judgeScheduleStage((int)$conventionSD->id, 3, true);
		//$this->Flash->success('Scheduling completed successfully for category 3.');
		return $this->redirect(['controller' => 'schedulingtimings', 'action' => 'startschedulec4', $convention_season_slug]);
		
	}
	
	
	public function startschedulec4($convention_season_slug=null) {
		@set_time_limit(0);
		@ini_set('max_execution_time', '0');
		$this->set('convention_season_slug', $convention_season_slug);
		$defaultConnection = ConnectionManager::get('default');
		if (method_exists($defaultConnection, 'enableQueryLogging')) {
			$defaultConnection->enableQueryLogging(false);
		} elseif (method_exists($defaultConnection, 'logQueries')) {
			$defaultConnection->logQueries(false);
		}
		
		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		$this->judgeScheduleStage((int)$conventionSD->id, 4, false);

		/* Category 4 now runs after categories 1-3 in full chain. */
		
		//$this->prx($conventionSD);
		
		// to get details of schedule timings
		$schedulingsD = $this->resolveSchedulingWizardRecord($conventionSD);
		if ($redirect = $this->ensureSchedulingWindowIsValid($schedulingsD, $convention_season_slug)) {
			return $redirect;
		}
		$this->applyScheduleSeed($conventionSD, $schedulingsD, 4);
		$category4Bands = $this->buildConventionBands($schedulingsD);
		if (!empty($category4Bands)) {
			\Cake\Log\Log::debug('Category 4 convention bands mode='.$this->getScheduleBandMode($schedulingsD).', total_bands='.count($category4Bands).', first_band='.$category4Bands[0]['day'].' '.$category4Bands[0]['band'].' '.$category4Bands[0]['start'].'-'.$category4Bands[0]['end'].', last_band='.$category4Bands[count($category4Bands) - 1]['day'].' '.$category4Bands[count($category4Bands) - 1]['band'].' '.$category4Bands[count($category4Bands) - 1]['start'].'-'.$category4Bands[count($category4Bands) - 1]['end']);
		}
		$first_day 				= $schedulingsD->first_day;
		$normal_starting_time 	= $schedulingsD->normal_starting_time;
		$normal_finish_time 	= $schedulingsD->normal_finish_time;
		
		$lunch_time_start 		= $schedulingsD->lunch_time_start;
		$lunch_time_end 		= $schedulingsD->lunch_time_end;
		
		$start_date 			= date("Y-m-d",strtotime($schedulingsD->start_date));
		
		$starting_different_time_first_day_yes_no = $schedulingsD->starting_different_time_first_day_yes_no;
		if($starting_different_time_first_day_yes_no == 1)
		{
			$different_first_day_start_time = $schedulingsD->different_first_day_start_time;
			$different_first_day_end_time 	= $schedulingsD->different_first_day_end_time;
		}
		
		
		/* TO GET ALL THE EVENTS WITH FOLLOWING CONDITIONS */
		// group_event = no || event_kind_id = Sequential || needs_schedule = 1 || has_to_be_consecutive = yes
		$arrEventsC4 = array();
		$eventsC4 = $this->Conventionseasonevents->find()
			->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonevents'))
			->all();
		foreach($eventsC4 as $eventc4)
		{
			$eventD = $this->Events->find()->where(['Events.id' => $eventc4->event_id])->first();
			if($eventD->needs_schedule == '1' && $eventD->group_event_yes_no == '0' && $eventD->event_kind_id == 'Sequential' && $eventD->has_to_be_consecutive == '1')
			{
				$arrEventsC4[] = $eventD->id;
			}
		}
		//$this->prx($arrEventsC4);
		//$arrEventsC4 = array();$arrEventsC4[] = 59;
		
		
		/* NOW GET STUDENTS FOR EACH EVENT */
		$arrStudentsC4 = array();
		foreach($arrEventsC4 as $event_id_c4)
		{
			$studentsQueryC4 = $this->Conventionregistrationstudents->find()->where([
				'Conventionregistrationstudents.convention_id' => (int)$conventionSD->convention_id,
				'Conventionregistrationstudents.season_id' => (int)$conventionSD->season_id,
				'Conventionregistrationstudents.season_year' => (int)$conventionSD->season_year,
				'Conventionregistrationstudents.status' => 1,
				'Conventionregistrationstudents.student_id >' => 0,
			]);
			$studentsC4 = $this->whereCsvContainsEventId($studentsQueryC4, 'Conventionregistrationstudents.event_ids', (int)$event_id_c4)->all();
			
			if($studentsC4)
			{
				foreach($studentsC4 as $studentEV)
				{
					$arrStudentsC4[$event_id_c4][] = $studentEV->student_id;
				}
			}
		}
		//$this->prx($arrStudentsC4);
		
		
		/* NOW FETCH STUDENTS FOR EACH EVENT AND PERFORM SCHEDULING */
		foreach($arrStudentsC4 as $event_id_c4 => $studentsListC4)
		{	
			// to get event details
			$eventD = $this->Events->find()->where(['Events.id' => $event_id_c4])->first();
			
			// shuffle array
			shuffle($studentsListC4);
			
			foreach($studentsListC4 as $student_id)
			{
				/* Here we will check that this user_id is School Or student 
				School means its a group event
				Student means it's an individual event
				*/
				$fetchUserType = $this->fetchUserType($student_id);
				
				//now enter schedule timings
				$schedulingtimings = $this->Schedulingtimings->newEntity([]);
				$dataST = $this->Schedulingtimings->patchEntity($schedulingtimings, array());

				$dataST->schedule_category				= 4;
				$dataST->conventionseasons_id			= $conventionSD->id;
				$dataST->convention_id					= $conventionSD->convention_id;
				$dataST->season_id						= $conventionSD->season_id;
				$dataST->season_year					= $conventionSD->season_year;
				$dataST->conventionregistration_id 		= NULL;
				$dataST->event_id 						= $eventD->id;
				$dataST->event_id_number 				= $eventD->event_id_number;
				$dataST->user_id 						= $student_id;
				$dataST->group_name 					= NULL;
				
				$dataST->room_id 						= NULL;
				$dataST->day 							= NULL;
				$dataST->start_time 					= NULL;
				$dataST->finish_time 					= NULL;
				
				$dataST->created 						= date('Y-m-d H:i:s');
				$dataST->modified 						= date('Y-m-d H:i:s');
				
				$dataST->sch_date_time 					= $start_date.' 00:00:00';
				
				$dataST->user_type 						= $fetchUserType;
				//$this->prx($dataST);

				$resultST = $this->Schedulingtimings->saveOrFail($dataST);
			}
			
			
		}
		
		
		/* IN ABOVE CODE, WE DEFINE SCHEDULING BUT NOT DEFINED DAY, START AND END TIME */
		/* IN BELOW CODE WE WILL FETCH THIS SCHEDULING AGAIN FOR EACH EVENT ONE BY ONE AND DEFINE 
		DAY, START TIME AND END TIME */
		
		$arrEventsC4 = $this->orderEventsForStrategy($conventionSD, $schedulingsD, 4, $arrEventsC4);
		foreach($arrEventsC4 as $event_id)
		{
			// to get event details
			$eventD = $this->Events->find()->where(['Events.id' => $event_id])->first();
			
			// to calculate event execution time
			$eventSetupRoundJudTime 	= $eventD->setup_time+$eventD->round_time+$eventD->judging_time;
			
			// now check that if any room is allocated for this event
			$roomQueryC4 = $this->Conventionseasonroomevents->find()
				->select(['room_id', 'number_games'])
				->where($this->seasonScopeConditions($conventionSD, 'Conventionseasonroomevents'));
			$roomCSEvent = $this->whereCsvContainsEventId($roomQueryC4, 'Conventionseasonroomevents.event_ids', (int)$event_id)->all();
			$roomArrCSEvent = array();
			foreach($roomCSEvent as $roomeventcs)
			{
				$roomId = (int)$roomeventcs->room_id;
				$gamesAtOnce = isset($roomeventcs->number_games) ? (int)$roomeventcs->number_games : 1;
				if($gamesAtOnce <= 0)
				{
					$gamesAtOnce = 1;
				}
				for($gameCounter = 0; $gameCounter < $gamesAtOnce; $gameCounter++)
				{
					$roomArrCSEvent[] = $roomId;
				}
			}
			//$this->prx($roomArrCSEvent);
			
			// ---- Load event tweaks (A: pinned day, B: pinned room, C: pinned start time) ----
			$eventTweak4 = $this->Schedulingeventtweaks->find()
				->where(['Schedulingeventtweaks.conventionseasons_id' => $conventionSD->id,
				         'Schedulingeventtweaks.event_id'             => $event_id])
				->first();
			// B: override room assignment
			if ($eventTweak4 && $eventTweak4->pinned_room_id) {
				$roomArrCSEvent = [(int)$eventTweak4->pinned_room_id];
			}
			$eventBandsC4 = $this->buildCategory4EventBands($schedulingsD, $eventTweak4);
			
			// Events without rooms or bands still go through the planner so their rows are reported as unscheduled.
			$schedulingT = $this->Schedulingtimings->find()
				->where($this->seasonScopeConditions($conventionSD, 'Schedulingtimings'))
				->andWhere([
					'Schedulingtimings.schedule_category' => 4,
					'Schedulingtimings.event_id' => (int)$event_id,
				])
				->order(["Schedulingtimings.id" => "ASC"])
				->all();

			$batchSizeC4 = $this->getCategory4ConcurrentBatchSize($eventD, $eventTweak4);
			$roomLanesC4 = array_fill_keys(array_values(array_unique(array_map('intval', (array)$roomArrCSEvent))), 1);
			$unitsC4 = [];
			$unitsC4ByKey = [];
			foreach ($schedulingT as $schdata) {
				$unitC4 = [
					'id' => 'r'.(int)$schdata->id,
					'row_id' => (int)$schdata->id,
					'row_ids' => [(int)$schdata->id],
					'students' => $this->rowStudentIds($conventionSD, $schdata),
					'possible' => [],
					'label' => 'participant '.(int)$schdata->user_id,
				];
				$unitsC4[] = $unitC4;
				$unitsC4ByKey[$unitC4['id']] = $unitC4;
			}
			if (count($unitsC4) > 0) {
				$resultC4 = $this->placeEventWithStrategy($conventionSD, $schedulingsD, $unitsC4, $eventBandsC4, $roomLanesC4, (int)$eventSetupRoundJudTime, (int)$event_id, $batchSizeC4, [], $eventTweak4);
				$this->savePlacedRows($resultC4['placed']);
				if (count($resultC4['unscheduled']) > 0) {
					$this->recordUnscheduled(4, $eventD, $resultC4['unscheduled'], $unitsC4ByKey, (int)$eventSetupRoundJudTime, $roomLanesC4, $eventTweak4);
				}
			}
			
			
		}
		
		$this->flushUnscheduledReport($conventionSD, 4);
		$this->judgeScheduleStage((int)$conventionSD->id, 4, true);
		//$this->Flash->success('Scheduling done for category 4.');
		return $this->redirect(['controller' => 'schedulingtimings', 'action' => 'fillgroupuserids', $convention_season_slug]);
		
	}
	
	public function fillgroupuserids($convention_season_slug=null)
	{
		// To get convention season details
		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		
		$this->judgeScheduleStage((int)$conventionSD->id, 5, false);
		$this->fillScheduleGroupMemberIds($conventionSD);
		$this->judgeScheduleStage((int)$conventionSD->id, 5, true);
		
		// Now check for conflicts
		return $this->redirect(['controller' => 'schedulingtimings', 'action' => 'listconflicts', $convention_season_slug]);
	}
	
	
	public function listconflicts($convention_season_slug=null)
	{
		$conventionSD = $this->getConventionSeasonBySlug($convention_season_slug);
		if (!$conventionSD) {
			$this->Flash->error('Invalid convention season.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'precheck']);
		}

		$loader = new TimetableLoader(ConnectionManager::get('default'));
		$data = $loader->load((int)$conventionSD->id);
		$validator = new TimetableValidator($data['context']);
		$report = $validator->validate($data['bookings']);

		$conflictUsers = [];
		$groupRows = [];
		foreach ($report['issues'] as $issue) {
			if (($issue['type'] ?? '') !== TimetableValidator::STUDENT_OVERLAP) {
				continue;
			}
			foreach (($issue['students'] ?? []) as $studentId) {
				$conflictUsers[(int)$studentId] = true;
			}
			foreach (($issue['rows'] ?? []) as $rowId) {
				$row = $data['rows'][(int)$rowId] ?? null;
				if ($row && (string)($row['user_type'] ?? '') === 'School') {
					$groupRows[(int)$rowId] = true;
				}
			}
		}

		$this->Schedulings->updateAll([
			'conflict_user_ids' => $conflictUsers ? implode(',', array_keys($conflictUsers)) : null,
			'conflict_user_ids_group' => $groupRows ? implode(',', array_keys($groupRows)) : null,
			'modified' => date('Y-m-d H:i:s'),
		], ['conventionseasons_id' => $conventionSD->id]);
		$this->judgeScheduleStage((int)$conventionSD->id, 6, true);

		if ($conflictUsers || $groupRows) {
			$this->Flash->warning('Scheduling conflict check completed. Conflicts remain. Use Check & Resolve Conflicts to preview safe repairs.');
		} else {
			$this->Flash->success('Scheduling conflict check completed. No participant conflicts remain.');
		}

		return $this->redirect(['controller' => 'schedulings', 'action' => 'schedulecategory', $convention_season_slug]);
	}
	
	
	
	
	
	
	
	public function conflictdone($convention_season_slug=null) {
		
		$this->Flash->success('Scheduling completed successfully. Overlapping and conflicts removed successfully.');
		
		return $this->redirect(['controller' => 'schedulings', 'action' => 'schedulecategory', $convention_season_slug]);
		
	}

	private function ensureSchedulingWindowIsValid($schedulingsD, $convention_season_slug)
	{
		if (empty($schedulingsD) || empty($schedulingsD->id)) {
			$this->Flash->error('Scheduling wizard configuration is missing. Please save the wizard settings before generating schedules.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'wizard', $convention_season_slug]);
		}

		$startDate = !empty($schedulingsD->start_date) ? date('Y-m-d', strtotime($schedulingsD->start_date)) : null;
		$actualFirstDay = $this->getWeekDayFromDate($startDate);
		$configuredFirstDay = (string)($schedulingsD->first_day ?? '');
		$numberOfDays = (int)($schedulingsD->number_of_days ?? 0);
		$normalStartingTime = $this->normalizeWizardTime($schedulingsD->normal_starting_time ?? null);
		$normalFinishTime = $this->normalizeWizardTime($schedulingsD->normal_finish_time ?? null);
		$lunchStartTime = $this->normalizeWizardTime($schedulingsD->lunch_time_start ?? null);
		$lunchEndTime = $this->normalizeWizardTime($schedulingsD->lunch_time_end ?? null);

		if ($numberOfDays < 1) {
			$this->Flash->error('Scheduling wizard configuration is incomplete. Number of Days must be at least 1 before schedules can be generated.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'wizard', $convention_season_slug]);
		}

		if (!$startDate || !$actualFirstDay || $configuredFirstDay === '') {
			$this->Flash->error('Scheduling wizard configuration is incomplete. Please review the Start Date and First Day before generating schedules.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'wizard', $convention_season_slug]);
		}

		if ($actualFirstDay !== $configuredFirstDay) {
			$this->Flash->error('Scheduling wizard mismatch: Start Date falls on '.$actualFirstDay.', but First Day is set to '.$configuredFirstDay.'. Please fix the wizard before generating schedules.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'wizard', $convention_season_slug]);
		}

		if (empty($normalStartingTime) || empty($normalFinishTime) || empty($lunchStartTime) || empty($lunchEndTime)) {
			$this->Flash->error('Scheduling wizard configuration is incomplete. Normal and lunch start/finish times are required before schedules can be generated.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'wizard', $convention_season_slug]);
		}

		if (!$this->isValidTimeRange($normalStartingTime, $normalFinishTime)) {
			$this->Flash->error('Scheduling wizard time window is invalid. Normal Starting Time must be earlier than Normal Finish Time.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'wizard', $convention_season_slug]);
		}

		if (!$this->isValidTimeRange($lunchStartTime, $lunchEndTime)) {
			$this->Flash->error('Scheduling wizard lunch window is invalid. Lunch Time Start must be earlier than Lunch Time End.');
			return $this->redirect(['controller' => 'schedulings', 'action' => 'wizard', $convention_season_slug]);
		}

		if ($schedulingsD->starting_different_time_first_day_yes_no == 1) {
			$firstDayStart = $this->normalizeWizardTime($schedulingsD->different_first_day_start_time ?? null);
			$firstDayEnd = $this->normalizeWizardTime($schedulingsD->different_first_day_end_time ?? null);
			if (empty($firstDayStart) || empty($firstDayEnd) || !$this->isValidTimeRange($firstDayStart, $firstDayEnd)) {
				$this->Flash->error('Scheduling wizard first-day window is invalid. Different first-day start and finish times are required and must be ordered correctly.');
				return $this->redirect(['controller' => 'schedulings', 'action' => 'wizard', $convention_season_slug]);
			}
		}

		if ($schedulingsD->sports_day_yes_no == 1) {
			$sportsStart = $this->normalizeWizardTime($schedulingsD->sports_day_starting_time ?? null);
			$sportsEnd = $this->normalizeWizardTime($schedulingsD->sports_day_finish_time ?? null);
			if (empty($schedulingsD->sports_day) || empty($sportsStart) || empty($sportsEnd) || !$this->isValidTimeRange($sportsStart, $sportsEnd)) {
				$this->Flash->error('Scheduling wizard sports-day window is invalid. Sports day, start time, and finish time are required and must be ordered correctly.');
				return $this->redirect(['controller' => 'schedulings', 'action' => 'wizard', $convention_season_slug]);
			}
		}

		return null;
	}

	private function resolveSchedulingWizardRecord($conventionSD)
	{
		if (empty($conventionSD) || empty($conventionSD->id)) {
			return null;
		}

		$records = $this->Schedulings->find()
			->where([
				'Schedulings.conventionseasons_id' => $conventionSD->id,
				'Schedulings.convention_id' => $conventionSD->convention_id,
				'Schedulings.season_id' => $conventionSD->season_id,
				'Schedulings.season_year' => $conventionSD->season_year,
			])
			->order(['Schedulings.modified' => 'DESC', 'Schedulings.id' => 'DESC'])
			->all();

		$fallback = null;
		foreach ($records as $record) {
			if ($fallback === null) {
				$fallback = $record;
			}

			$hasCoreWindow =
				!empty($this->normalizeWizardTime($record->normal_starting_time ?? null)) &&
				!empty($this->normalizeWizardTime($record->normal_finish_time ?? null)) &&
				!empty($this->normalizeWizardTime($record->lunch_time_start ?? null)) &&
				!empty($this->normalizeWizardTime($record->lunch_time_end ?? null));

			if ($hasCoreWindow) {
				return $record;
			}
		}

		return $fallback;
	}

	private function normalizeWizardTime($timeValue)
	{
		if ($timeValue === null || $timeValue === '') {
			return null;
		}

		if (is_object($timeValue) && method_exists($timeValue, 'format')) {
			return $timeValue->format('H:i:s');
		}

		$timestamp = strtotime((string)$timeValue);
		if ($timestamp === false) {
			return null;
		}

		return date('H:i:s', $timestamp);
	}

	private function isValidTimeRange($startTime, $endTime): bool
	{
		$start = strtotime((string)$startTime);
		$end = strtotime((string)$endTime);

		if ($start === false || $end === false) {
			return false;
		}

		return $start < $end;
	}

	private function applyScheduleSeed($conventionSD, $schedulingsD, int $category): int
	{
		$session = $this->request->getSession();
		$baseSeed = (int)$session->read('Scheduling.runSeed');
		if ($baseSeed === 0) {
			$wizardSeed = null;
			if ($this->hasScheduleSeedColumn()) {
				$raw = $schedulingsD->schedule_seed ?? null;
				if ($raw !== null && $raw !== '') {
					$wizardSeed = (int)$raw;
				}
			}
			$baseSeed = $wizardSeed ?? random_int(100000, 999999999);
			$session->write('Scheduling.runSeed', $baseSeed);
			$this->writeRunSeedLog((int)$conventionSD->id, $baseSeed, $wizardSeed);
		}
		$categorySeed = $baseSeed + $category;
		mt_srand($categorySeed);

		return $categorySeed;
	}

	private function hasScheduleSeedColumn(): bool
	{
		return in_array('schedule_seed', (array)$this->Schedulings->getSchema()->columns(), true);
	}

	private function runSeedLogPath(int $seasonId): string
	{
		return LOGS.'scheduling_seed_season_'.$seasonId.'.json';
	}

	private function writeRunSeedLog(int $seasonId, int $seed, ?int $wizardSeed): void
	{
		$payload = [
			'season_id' => $seasonId,
			'seed' => $seed,
			'wizard_seed' => $wizardSeed,
			'updated_at' => gmdate('c'),
		];
		file_put_contents($this->runSeedLogPath($seasonId), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
	}

	private function applyNextConventionDay(&$schDay, &$schStartDate, &$cntrDays, $schedulingsD): bool
	{
		$numberOfDays = max(1, (int)($schedulingsD->number_of_days ?? 1));
		if ($cntrDays >= $numberOfDays) {
			return false;
		}

		$schStartDate = date('Y-m-d', strtotime($schStartDate . ' +1 day'));
		$schDay = $this->getWeekDayFromDate($schStartDate);
		$cntrDays++;

		return true;
	}


	/**
	 * Day strategy from the wizard (earliest, round_robin or balanced). Seasons saved
	 * before the strategy setting existed keep their round-robin checkbox behaviour.
	 */
	private function getScheduleBandMode($schedulingsD): string
	{
		return DayStrategy::normalise($schedulingsD->schedule_band_mode ?? null, !empty($schedulingsD->round_robin_day_distribution_yes_no));
	}

	private function buildBandsForDateRow(array $dateRow, $lunchStart, $lunchEnd): array
	{
		$dayStart = $dateRow['day_start'];
		$dayEnd = $dateRow['day_end'];

		if ($dayStart === null || $dayEnd === null) {
			return [];
		}

		$bands = [];
		if (strtotime((string)$dayStart) < strtotime((string)$lunchStart)) {
			$morningEnd = strtotime((string)$lunchStart) < strtotime((string)$dayEnd) ? $lunchStart : $dayEnd;
			if (strtotime((string)$dayStart) < strtotime((string)$morningEnd)) {
				$bands[] = [
					'date' => $dateRow['date'],
					'day' => $dateRow['day'],
					'band' => 'AM',
					'start' => $dayStart,
					'end' => $morningEnd,
				];
			}
		}

		$afternoonStart = strtotime((string)$lunchEnd) > strtotime((string)$dayStart) ? $lunchEnd : $dayStart;
		if (strtotime((string)$afternoonStart) < strtotime((string)$dayEnd)) {
			$bands[] = [
				'date' => $dateRow['date'],
				'day' => $dateRow['day'],
				'band' => 'PM',
				'start' => $afternoonStart,
				'end' => $dayEnd,
			];
		}

		return $bands;
	}

	/**
	 * Convention bands (AM/PM per day) in date order. Day strategies reorder them per event.
	 */
	private function buildConventionBands($schedulingsD): array
	{
		$numberOfDays = max(1, (int)($schedulingsD->number_of_days ?? 1));
		$baseDate = new \DateTime(date('Y-m-d', strtotime((string)$schedulingsD->start_date)));
		$normalStart = $this->normalizeWizardTime($schedulingsD->normal_starting_time);
		$normalEnd = $this->normalizeWizardTime($schedulingsD->normal_finish_time);
		$lunchStart = $this->normalizeWizardTime($schedulingsD->lunch_time_start);
		$lunchEnd = $this->normalizeWizardTime($schedulingsD->lunch_time_end);
		$hasFirstDayOverride = !empty($schedulingsD->starting_different_time_first_day_yes_no);
		$firstDayStart = $hasFirstDayOverride ? $this->normalizeWizardTime($schedulingsD->different_first_day_start_time) : $normalStart;
		$firstDayEnd = $hasFirstDayOverride ? $this->normalizeWizardTime($schedulingsD->different_first_day_end_time) : $normalEnd;

		$dates = [];
		for ($index = 0; $index < $numberOfDays; $index++) {
			$currentDate = clone $baseDate;
			if ($index > 0) {
				$currentDate->modify('+'.$index.' day');
			}

			$dayStart = $index === 0 ? $firstDayStart : $normalStart;
			$dayEnd = $index === 0 ? $firstDayEnd : $normalEnd;
			$dates[] = [
				'date' => $currentDate->format('Y-m-d'),
				'day' => $currentDate->format('l'),
				'day_start' => $dayStart,
				'day_end' => $dayEnd,
			];
		}

		$bandsByDate = [];
		foreach ($dates as $dateRow) {
			$bandsByDate[] = $this->buildBandsForDateRow($dateRow, $lunchStart, $lunchEnd);
		}

		$bands = [];
		foreach ($bandsByDate as $dayBands) {
			foreach ($dayBands as $band) {
				$bands[] = $band;
			}
		}

		return $bands;
	}

	private function buildCategory4EventBands($schedulingsD, $eventTweak4): array
	{
		$bands = $this->buildConventionBands($schedulingsD);
		if (empty($bands)) {
			return [];
		}

		if ($eventTweak4 && !empty($eventTweak4->pinned_day)) {
			$firstPinnedIndex = null;
			$firstPinnedDate = null;
			foreach ($bands as $index => $band) {
				if ($band['day'] === $eventTweak4->pinned_day) {
					$firstPinnedIndex = $index;
					$firstPinnedDate = $band['date'] ?? null;
					break;
				}
			}

			if ($firstPinnedIndex !== null) {
				$bands = array_slice($bands, $firstPinnedIndex);
				if ($firstPinnedDate !== null) {
					$bands = array_values(array_filter($bands, function ($band) use ($firstPinnedDate) {
						return isset($band['date']) && (string)$band['date'] >= (string)$firstPinnedDate;
					}));
				}
			}
		}

		if ($eventTweak4 && !empty($eventTweak4->pinned_start_time)) {
			$pinnedStart = date('H:i:s', strtotime($eventTweak4->pinned_start_time));
			$pinnedDay = !empty($eventTweak4->pinned_day) ? trim((string)$eventTweak4->pinned_day) : null;
			$startBandIndex = null;
			$startBand = null;

			foreach ($bands as $idx => $band) {
				if ($pinnedDay !== null && $pinnedDay !== '' && $band['day'] !== $pinnedDay) {
					continue;
				}

				if (strtotime($pinnedStart) >= strtotime($band['end'])) {
					continue;
				}

				if (strtotime($pinnedStart) > strtotime($band['start'])) {
					$band['start'] = $pinnedStart;
				}

				$startBandIndex = $idx;
				$startBand = $band;
				break;
			}

			if ($startBandIndex !== null && $startBand !== null) {
				$bands = array_merge([$startBand], array_slice($bands, $startBandIndex + 1));
			} else {
				$bands = [];
			}
		}

		return array_values($bands);
	}

	/*
	 * For elimination events, pinned day/time means "start from here" and continue
	 * through later bands/days. It does not mean "schedule only on that day".
	 */
	private function buildEliminationEventBands($schedulingsD, $eventTweak): array
	{
		$bands = $this->buildConventionBands($schedulingsD);
		if (empty($bands)) {
			return [];
		}

		if ($eventTweak && !empty($eventTweak->pinned_day)) {
			$pinnedDay = (string)$eventTweak->pinned_day;
			$firstPinnedIndex = null;
			$firstPinnedDate = null;
			foreach ($bands as $idx => $band) {
				if ($band['day'] === $pinnedDay) {
					$firstPinnedIndex = $idx;
					$firstPinnedDate = $band['date'] ?? null;
					break;
				}
			}

			if ($firstPinnedIndex !== null) {
				$bands = array_slice($bands, $firstPinnedIndex);
				if ($firstPinnedDate !== null) {
					$bands = array_values(array_filter($bands, function ($band) use ($firstPinnedDate) {
						return isset($band['date']) && (string)$band['date'] >= (string)$firstPinnedDate;
					}));
				}
			}
		}

		if ($eventTweak && !empty($eventTweak->pinned_start_time)) {
			$pinnedStart = date('H:i:s', strtotime((string)$eventTweak->pinned_start_time));
			$pinnedDay = !empty($eventTweak->pinned_day) ? trim((string)$eventTweak->pinned_day) : null;
			$startBandIndex = null;
			$startBand = null;

			foreach ($bands as $idx => $band) {
				if ($pinnedDay !== null && $pinnedDay !== '' && $band['day'] !== $pinnedDay) {
					continue;
				}

				if (strtotime($pinnedStart) >= strtotime($band['end'])) {
					continue;
				}

				if (strtotime($pinnedStart) > strtotime($band['start'])) {
					$band['start'] = $pinnedStart;
				}

				$startBandIndex = $idx;
				$startBand = $band;
				break;
			}

			if ($startBandIndex !== null && $startBand !== null) {
				$bands = array_merge([$startBand], array_slice($bands, $startBandIndex + 1));
			} else {
				$bands = [];
			}
		}

		return array_values($bands);
	}

	private function getConventionDayNumber(string $startDate, string $slotDate): int
	{
		$start = new \DateTime($startDate);
		$slot = new \DateTime($slotDate);
		$diff = $start->diff($slot);

		return ((int)$diff->days) + 1;
	}

	private function shouldDisableParallelNumberGamesForEliminationRound($eventD, int $roundMatchCount): bool
	{
		if ($roundMatchCount <= 0 || $roundMatchCount > 4 || !$eventD) {
			return false;
		}

		$eventName = strtolower(trim((string)($eventD->event_name ?? '')));
		if ($eventName === '') {
			return false;
		}

		$isChessOrCheckers = (strpos($eventName, 'checkers') !== false || strpos($eventName, 'chess') !== false);
		if (!$isChessOrCheckers) {
			return false;
		}

		$isU16OrOpen = (strpos($eventName, 'u16') !== false || strpos($eventName, 'open') !== false);
		if (!$isU16OrOpen) {
			return false;
		}

		return true;
	}




	private function isValidSlotInsideBand(array $band, array $validSlot): bool
	{
		if (($validSlot['schStartDate'] ?? '') !== $band['date']) {
			return false;
		}

		if (($validSlot['schDay'] ?? '') !== $band['day']) {
			return false;
		}

		if (strtotime((string)($validSlot['start_time'] ?? '')) < strtotime($band['start'])) {
			return false;
		}

		if (strtotime((string)($validSlot['finish_time'] ?? '')) > strtotime($band['end'])) {
			return false;
		}

		return true;
	}

	private function getCategory4ConcurrentBatchSize($eventD, $eventTweak = null): int
	{
		$eventIdNumber = (string)($eventD->event_id_number ?? '');
		if (in_array($eventIdNumber, ['003', '053'], true)) {
			$override = (int)($eventTweak->concurrent_students_at_once ?? 0);
			if ($override > 0) {
				return $override;
			}
			return 40;
		}

		return 1;
	}




	private function slotOverlapsRange($slotStart, $slotFinish, $rangeStart, $rangeEnd): bool
	{
		if (empty($slotStart) || empty($slotFinish) || empty($rangeStart) || empty($rangeEnd)) {
			return false;
		}

		return strtotime((string)$slotStart) < strtotime((string)$rangeEnd)
			&& strtotime((string)$slotFinish) > strtotime((string)$rangeStart);
	}
	
	
	/**
	 * Validate a time slot against all break periods (lunch, judging, sports day, events-after-sport).
	 * Loops until the slot is clean — fixes the "blind jump" bug where sequential break checks
	 * could skip over a break after being pushed past another one.
	 */
	private function findValidSlot($start_time, $finish_time, $schDay, $schStartDate, $cntrDays, $normal_starting_time, $normal_finish_time, $eventSetupRoundJudTime, $schedulingsD, $lunch_time_start, $lunch_time_end, $roomID = null, $eventID = null)
	{
		$maxIterations = 20;
		$iteration = 0;
		$windowExhausted = false;
		$roomAvailableFrom = null;
		$roomAvailableTo = null;
		$eventAvailableFrom = null;
		$eventAvailableTo = null;

		if (!empty($eventID)) {
			$tweakKey = (int)$schedulingsD->conventionseasons_id.'-'.(int)$eventID;
			if (!array_key_exists($tweakKey, $this->slotTweakCache)) {
				$this->slotTweakCache[$tweakKey] = $this->Schedulingeventtweaks->find()->where([
					'Schedulingeventtweaks.conventionseasons_id' => $schedulingsD->conventionseasons_id,
					'Schedulingeventtweaks.event_id' => (int)$eventID,
				])->first();
			}
			$eventTweak = $this->slotTweakCache[$tweakKey];
			if ($eventTweak) {
				if (!empty($eventTweak->available_from_time)) {
					$eventAvailableFrom = date('H:i:s', strtotime($eventTweak->available_from_time));
				}
				if (!empty($eventTweak->available_to_time)) {
					$eventAvailableTo = date('H:i:s', strtotime($eventTweak->available_to_time));
				}
			}
		}

		if (!empty($roomID)) {
			if (!array_key_exists((int)$roomID, $this->slotRoomCache)) {
				$this->slotRoomCache[(int)$roomID] = $this->Conventionrooms->find()->where(['Conventionrooms.id' => $roomID])->first();
			}
			$roomD = $this->slotRoomCache[(int)$roomID];
			if ($roomD) {
				if (!empty($roomD->available_from)) {
					$roomAvailableFrom = date('H:i:s', strtotime($roomD->available_from));
				}
				if (!empty($roomD->available_to)) {
					$roomAvailableTo = date('H:i:s', strtotime($roomD->available_to));
				}
			}
		}
		
		do {
			$slotChanged = false;
			$iteration++;

			if ($eventAvailableFrom !== null && strtotime($start_time) < strtotime($eventAvailableFrom)) {
				$start_time = $eventAvailableFrom;
				$finish_time = date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($start_time)));
				$slotChanged = true;
			}

			if ($eventAvailableTo !== null && strtotime($finish_time) > strtotime($eventAvailableTo)) {
				if (!$this->applyNextConventionDay($schDay, $schStartDate, $cntrDays, $schedulingsD)) {
					$windowExhausted = true;
					break;
				}
				$normal_starting_time = $this->normalizeWizardTime($schedulingsD->normal_starting_time);
				$normal_finish_time = $this->normalizeWizardTime($schedulingsD->normal_finish_time);
				$start_time = $eventAvailableFrom !== null ? $eventAvailableFrom : $normal_starting_time;
				$finish_time = date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($start_time)));
				$slotChanged = true;
			}
			
			/* Lunch break check */
			if( (strtotime($start_time)>=strtotime($lunch_time_start) && strtotime($start_time)<=strtotime($lunch_time_end)) || 
				(strtotime($finish_time)>=strtotime($lunch_time_start) && strtotime($finish_time)<=strtotime($lunch_time_end)))
			{
				$start_time 	= $lunch_time_end;
				$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($lunch_time_end)));
				$slotChanged = true;
				
				if(strtotime($finish_time)>strtotime($normal_finish_time))
				{
					if (!$this->applyNextConventionDay($schDay, $schStartDate, $cntrDays, $schedulingsD)) {
						$windowExhausted = true;
						break;
					}
					$normal_starting_time 	= $this->normalizeWizardTime($schedulingsD->normal_starting_time);
					$normal_finish_time 	= $this->normalizeWizardTime($schedulingsD->normal_finish_time);
					$start_time 	= $normal_starting_time;
					$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($normal_starting_time)));
				}
			}
			
			/* Judging breaks check */
			if($schedulingsD->judging_breaks_yes_no == 1)
			{
				// Morning break
				$jb_morning_start 	= $this->normalizeWizardTime($schedulingsD->judging_breaks_morning_break_starting_time);
				$jb_morning_end 	= $this->normalizeWizardTime($schedulingsD->judging_breaks_morning_break_finish_time);
				
				if( (strtotime($start_time)>=strtotime($jb_morning_start) && strtotime($start_time)<=strtotime($jb_morning_end)) || 
				(strtotime($finish_time)>=strtotime($jb_morning_start) && strtotime($finish_time)<=strtotime($jb_morning_end)))
				{
					$start_time 	= $jb_morning_end;
					$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($jb_morning_end)));
					$slotChanged = true;
				}
				
				if(strtotime($finish_time)>=strtotime($normal_finish_time))
				{
					if (!$this->applyNextConventionDay($schDay, $schStartDate, $cntrDays, $schedulingsD)) {
						$windowExhausted = true;
						break;
					}
					$normal_starting_time 	= $this->normalizeWizardTime($schedulingsD->normal_starting_time);
					$normal_finish_time 	= $this->normalizeWizardTime($schedulingsD->normal_finish_time);
					$start_time 	= $normal_starting_time;
					$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($normal_starting_time)));
					$slotChanged = true;
				}
				
				// Afternoon break
				$jb_afternoon_start = $this->normalizeWizardTime($schedulingsD->judging_breaks_afternoon_break_start_time);
				$jb_afternoon_end 	= $this->normalizeWizardTime($schedulingsD->judging_breaks_afternoon_break_finish_time);
				
				if( (strtotime($start_time)>=strtotime($jb_afternoon_start) && strtotime($start_time)<=strtotime($jb_afternoon_end)) || 
				(strtotime($finish_time)>=strtotime($jb_afternoon_start) && strtotime($finish_time)<=strtotime($jb_afternoon_end)))
				{
					$start_time 	= $jb_afternoon_end;
					$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($jb_afternoon_end)));
					$slotChanged = true;
				}
				
				if(strtotime($finish_time)>=strtotime($normal_finish_time))
				{
					if (!$this->applyNextConventionDay($schDay, $schStartDate, $cntrDays, $schedulingsD)) {
						$windowExhausted = true;
						break;
					}
					$normal_starting_time 	= $this->normalizeWizardTime($schedulingsD->normal_starting_time);
					$normal_finish_time 	= $this->normalizeWizardTime($schedulingsD->normal_finish_time);
					$start_time 	= $normal_starting_time;
					$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($normal_starting_time)));
					$slotChanged = true;
				}
			}
			
			/* Sports day check */
			if($schedulingsD->sports_day_yes_no == 1)
			{
				$sports_day					= $schedulingsD->sports_day;
				$sports_day_starting_time	= $this->normalizeWizardTime($schedulingsD->sports_day_starting_time);
				$sports_day_finish_time		= $this->normalizeWizardTime($schedulingsD->sports_day_finish_time);
				$hasAfterSportWindow = ((int)($schedulingsD->sports_day_having_events_after_sport_yes_no ?? 0) === 1);
				$sports_day_other_starting_time = $this->normalizeWizardTime($schedulingsD->sports_day_other_starting_time ?? null);
				$sports_day_other_finish_time = $this->normalizeWizardTime($schedulingsD->sports_day_other_finish_time ?? null);
				
				if($sports_day == $schDay)
				{
					if( (strtotime($start_time)>=strtotime($sports_day_starting_time) && strtotime($start_time)<=strtotime($sports_day_finish_time)) || 
					(strtotime($finish_time)>=strtotime($sports_day_starting_time) && strtotime($finish_time)<=strtotime($sports_day_finish_time)))
					{
						$start_time 	= $sports_day_finish_time;
						$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($sports_day_finish_time)));
						$slotChanged = true;
					}

					if (
						$hasAfterSportWindow &&
						$this->isValidTimeRange($sports_day_other_starting_time, $sports_day_other_finish_time)
					) {
						// On sports day, post-sport events can only run inside the configured after-sport window.
						if (strtotime($start_time) < strtotime($sports_day_other_starting_time)) {
							$start_time = $sports_day_other_starting_time;
							$finish_time = date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($start_time)));
							$slotChanged = true;
						}

						if (strtotime($finish_time) > strtotime($sports_day_other_finish_time)) {
							if (!$this->applyNextConventionDay($schDay, $schStartDate, $cntrDays, $schedulingsD)) {
								$windowExhausted = true;
								break;
							}
							$normal_starting_time = $this->normalizeWizardTime($schedulingsD->normal_starting_time);
							$normal_finish_time = $this->normalizeWizardTime($schedulingsD->normal_finish_time);
							$start_time = $normal_starting_time;
							$finish_time = date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($normal_starting_time)));
							$slotChanged = true;
						}
					}
					
					if(strtotime($finish_time)>=strtotime($normal_finish_time))
					{
						if (!$this->applyNextConventionDay($schDay, $schStartDate, $cntrDays, $schedulingsD)) {
							$windowExhausted = true;
							break;
						}
						$normal_starting_time 	= $this->normalizeWizardTime($schedulingsD->normal_starting_time);
						$normal_finish_time 	= $this->normalizeWizardTime($schedulingsD->normal_finish_time);
						$start_time 	= $normal_starting_time;
						$finish_time 	= date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($normal_starting_time)));
						$slotChanged = true;
					}
				}
			}
			
			/* Events after sport are allowed in the configured post-sport window.
			 * The lunch and sports-day checks above already move slots out of blocked periods.
			 */

			/* Room availability window check */
			if (!empty($roomID) && ($roomAvailableFrom || $roomAvailableTo)) {
				$effectiveRoomStart = $roomAvailableFrom ?: $normal_starting_time;
				$effectiveRoomEnd = $roomAvailableTo ?: $normal_finish_time;

				if (strtotime($start_time) < strtotime($effectiveRoomStart)) {
					$start_time = $effectiveRoomStart;
					$finish_time = date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($start_time)));
					$slotChanged = true;
				}

				if (strtotime($finish_time) > strtotime($effectiveRoomEnd)) {
					if (!$this->applyNextConventionDay($schDay, $schStartDate, $cntrDays, $schedulingsD)) {
						$windowExhausted = true;
						break;
					}
					$normal_starting_time = $this->normalizeWizardTime($schedulingsD->normal_starting_time);
					$normal_finish_time = $this->normalizeWizardTime($schedulingsD->normal_finish_time);
					$start_time = (strtotime($effectiveRoomStart) > strtotime($normal_starting_time)) ? $effectiveRoomStart : $normal_starting_time;
					$finish_time = date("H:i:s", strtotime('+ '.$eventSetupRoundJudTime.' minutes', strtotime($start_time)));
					$slotChanged = true;
				}
			}
			
		} while ($slotChanged && $iteration < $maxIterations);
		
		return [
			'start_time' => $start_time,
			'finish_time' => $finish_time,
			'schDay' => $schDay,
			'schStartDate' => $schStartDate,
			'cntrDays' => $cntrDays,
			'normal_starting_time' => $normal_starting_time,
			'normal_finish_time' => $normal_finish_time,
			'window_exhausted' => $windowExhausted,
		];
	}

	/**
	 * Saves rounds 2..Final (plus the 3rd-place playoff) for one elimination
	 * event from its existing round 1 rows. Returns the number of rows created.
	 */
	private function createKnockoutLaterRounds(int $category, $conventionSD, $eventD, int $eventId, ?int $roomId, string $startDate): int
	{
		$roundOneConditions = [
			'Schedulingtimings.schedule_category' => $category,
			'Schedulingtimings.conventionseasons_id' => $conventionSD->id,
			'Schedulingtimings.convention_id' => $conventionSD->convention_id,
			'Schedulingtimings.season_id' => $conventionSD->season_id,
			'Schedulingtimings.season_year' => $conventionSD->season_year,
			'Schedulingtimings.event_id' => $eventId,
			'Schedulingtimings.round_number' => 1,
		];
		$roundOneRows = [];
		$lastMatchNumber = 0;
		$roundOneRecords = $this->Schedulingtimings->find()
			->select(['id', 'is_bye', 'match_number'])
			->where($roundOneConditions)
			->order(['Schedulingtimings.match_number' => 'ASC', 'Schedulingtimings.id' => 'ASC'])
			->all();
		foreach ($roundOneRecords as $roundOneRecord) {
			$roundOneRows[] = ['id' => (int)$roundOneRecord->id, 'bye' => (int)$roundOneRecord->is_bye === 1];
			$lastMatchNumber = max($lastMatchNumber, (int)$roundOneRecord->match_number);
		}

		$plan = KnockoutBracketPlanner::planLaterRounds($roundOneRows, true);
		$savedIds = [];
		$created = 0;
		foreach ($plan as $planned) {
			$feederOne = is_string($planned['feeder1']) ? ($savedIds[$planned['feeder1']] ?? 0) : (int)$planned['feeder1'];
			$feederTwo = $planned['feeder2'] === null ? 0 : (is_string($planned['feeder2']) ? ($savedIds[$planned['feeder2']] ?? 0) : (int)$planned['feeder2']);

			$lastMatchNumber++;
			$row = $this->Schedulingtimings->patchEntity($this->Schedulingtimings->newEntity([]), []);
			$row->schedule_category = $category;
			$row->conventionseasons_id = $conventionSD->id;
			$row->convention_id = $conventionSD->convention_id;
			$row->season_id = $conventionSD->season_id;
			$row->season_year = $conventionSD->season_year;
			$row->conventionregistration_id = null;
			$row->event_id = $eventId;
			$row->event_id_number = $eventD->event_id_number;
			$row->user_id = 0;
			$row->group_name = null;
			$row->room_id = $roomId;
			$row->day = null;
			$row->start_time = null;
			$row->finish_time = null;
			$row->user_id_opponent = 0;
			$row->schtimeautoid1 = $feederOne;
			$row->schtimeautoid2 = $feederTwo;
			$row->round_number = $planned['round'];
			$row->match_number = $lastMatchNumber;
			$row->is_bye = $planned['bye'] ? 1 : 0;
			$row->created = date('Y-m-d H:i:s');
			$row->sch_date_time = $startDate.' 00:00:00';
			if ($this->Schedulingtimings->save($row)) {
				$savedIds[$planned['key']] = (int)$row->id;
				$created++;
			}
		}

		return $created;
	}



	/* ---------------------------------------------------------------------
	 * Shared timetable ledger (Phase 2): every category places its bookings
	 * through SlotPlanner, which checks rooms, lanes, students, blocked
	 * periods and knockout dependencies before anything is saved.
	 * ------------------------------------------------------------------- */

	/** Hard transition buffer between one student's bookings (minutes). */
	private const STUDENT_BUFFER_MINUTES = 0;

	/** Preferred (soft) gap between one student's bookings (minutes). */
	private const PREFERRED_GAP_MINUTES = 5;

	private ?TimetableLedger $timetableLedger = null;
	private ?array $groupMemberMap = null;
	private array $slotTweakCache = [];
	private array $slotRoomCache = [];
	private array $unscheduledReport = [];

	/**
	 * Students of every school group for the season: [school][event][group] => student ids.
	 * Blank group names are stored under 'Ungrouped' to match category 1 rows.
	 */
	private function groupMemberIds($conventionSD, int $schoolId, int $eventId, ?string $groupName): array
	{
		if ($this->groupMemberMap === null) {
			$this->groupMemberMap = [];
			$memberRows = $this->Crstudentevents->find()
				->select(['user_id', 'event_id', 'group_name', 'student_id'])
				->where(['Crstudentevents.conventionseason_id' => $conventionSD->id, 'Crstudentevents.student_id >' => 0])
				->orderBy(['Crstudentevents.id' => 'ASC'])
				->enableHydration(false)
				->all();
			foreach ($memberRows as $memberRow) {
				$group = trim((string)($memberRow['group_name'] ?? ''));
				$this->groupMemberMap[(int)$memberRow['user_id']][(int)$memberRow['event_id']][$group === '' ? 'Ungrouped' : $group][(int)$memberRow['student_id']] = (int)$memberRow['student_id'];
			}
		}
		$group = trim((string)$groupName);

		return array_values($this->groupMemberMap[$schoolId][$eventId][$group === '' ? 'Ungrouped' : $group] ?? []);
	}

	/**
	 * Confirmed students on a saved row. School rows are resolved to their team members.
	 */
	private function rowStudentIds($conventionSD, $row): array
	{
		$ids = $this->scheduleParticipantIds($row);
		if ((string)($row['user_type'] ?? '') === 'School') {
			$eventId = (int)($row['event_id'] ?? 0);
			if ((int)($row['user_id'] ?? 0) > 0) {
				$ids = array_merge($ids, $this->groupMemberIds($conventionSD, (int)$row['user_id'], $eventId, $row['group_name'] ?? null));
			}
			if ((int)($row['user_id_opponent'] ?? 0) > 0) {
				$ids = array_merge($ids, $this->groupMemberIds($conventionSD, (int)$row['user_id_opponent'], $eventId, $row['group_name_opponent'] ?? null));
			}
		}

		return array_values(array_unique(array_map('intval', $ids)));
	}

	/**
	 * Knockout rows of one event keyed by id, with feeders, players and current timing.
	 */
	private function knockoutRowsForEvent($conventionSD, int $category, int $eventId, ?array $rows = null): array
	{
		if ($rows === null) {
			$rows = $this->Schedulingtimings->find()
				->where([
					'Schedulingtimings.conventionseasons_id' => $conventionSD->id,
					'Schedulingtimings.schedule_category' => $category,
					'Schedulingtimings.event_id' => $eventId,
				])
				->enableHydration(false)
				->toArray();
		}

		$knockoutRows = [];
		foreach ($rows as $row) {
			$placed = !empty($row['start_time']) && !empty($row['finish_time']) && !empty($row['sch_date_time']);
			$knockoutRows[(int)$row['id']] = [
				'bye' => (int)($row['is_bye'] ?? 0) === 1,
				'feeders' => array_values(array_filter([(int)($row['schtimeautoid1'] ?? 0), (int)($row['schtimeautoid2'] ?? 0)])),
				'players' => (int)($row['round_number'] ?? 0) <= 1 ? $this->rowStudentIds($conventionSD, $row) : [],
				'date' => $placed ? date('Y-m-d', strtotime((string)$row['sch_date_time'])) : null,
				'finish' => $placed ? date('H:i:s', strtotime((string)$row['finish_time'])) : null,
			];
		}

		return $knockoutRows;
	}

	/**
	 * Loads every timed booking of the season into a fresh ledger.
	 */
	private function buildTimetableLedger($conventionSD): TimetableLedger
	{
		$ledger = new TimetableLedger(self::STUDENT_BUFFER_MINUTES);
		$rows = $this->Schedulingtimings->find()
			->where([
				'Schedulingtimings.conventionseasons_id' => $conventionSD->id,
				'Schedulingtimings.convention_id' => $conventionSD->convention_id,
				'Schedulingtimings.season_id' => $conventionSD->season_id,
				'Schedulingtimings.season_year' => $conventionSD->season_year,
			])
			->enableHydration(false)
			->toArray();

		$knockoutRowsByEvent = [];
		foreach ($rows as $row) {
			if (in_array((int)$row['schedule_category'], [2, 3], true)) {
				$knockoutRowsByEvent[(int)$row['schedule_category'].'-'.(int)$row['event_id']][] = $row;
			}
		}
		$knockoutPeople = [];
		$knockoutCapacity = [];
		$eventNames = [];
		$eventIds = array_unique(array_map(fn ($eventRows) => (int)$eventRows[0]['event_id'], $knockoutRowsByEvent));
		if ($eventIds) {
			$eventNames = $this->Events->find('list', keyField: 'id', valueField: 'event_name')->where(['Events.id IN' => $eventIds])->toArray();
		}
		foreach ($knockoutRowsByEvent as $eventRows) {
			$first = $eventRows[0];
			$knockoutPeople += SlotPlanner::knockoutParticipants($this->knockoutRowsForEvent($conventionSD, (int)$first['schedule_category'], (int)$first['event_id'], $eventRows));
			$roundMatches = [];
			foreach ($eventRows as $row) {
				if ((int)($row['is_bye'] ?? 0) !== 1) {
					$roundMatches[(int)$row['round_number']] = ($roundMatches[(int)$row['round_number']] ?? 0) + 1;
				}
			}
			foreach ($eventRows as $row) {
				if (TimetableLoader::singleBoardRound((string)($eventNames[(int)$row['event_id']] ?? ''), (int)($roundMatches[(int)$row['round_number']] ?? 0))) {
					$knockoutCapacity[(int)$row['id']] = 1;
				}
			}
		}

		foreach ($rows as $row) {
			if (empty($row['start_time']) || empty($row['finish_time']) || empty($row['sch_date_time']) || (int)($row['is_bye'] ?? 0) === 1) {
				continue;
			}
			$date = date('Y-m-d', strtotime((string)$row['sch_date_time']));
			$start = date('H:i:s', strtotime((string)$row['start_time']));
			$finish = date('H:i:s', strtotime((string)$row['finish_time']));
			$category = (int)$row['schedule_category'];
			if (in_array($category, [2, 3], true)) {
				$people = $knockoutPeople[(int)$row['id']] ?? ['confirmed' => $this->rowStudentIds($conventionSD, $row), 'possible' => []];
				$ledger->book((int)$row['room_id'], $date, $start, $finish, (int)$row['event_id'], $people['confirmed'], $people['possible'], 'row-'.$row['id'], $knockoutCapacity[(int)$row['id']] ?? 0);
			} else {
				// Category 1/4 rows that share a slot (combined schools, speller batches) use one lane.
				$sessionKey = 'slot-'.(int)$row['room_id'].'-'.(int)$row['event_id'].'-'.$date.'-'.$start.'-'.$finish;
				$ledger->book((int)$row['room_id'], $date, $start, $finish, (int)$row['event_id'], $this->rowStudentIds($conventionSD, $row), [], $sessionKey);
			}
		}

		return $ledger;
	}

	private function buildBlockedPeriods($schedulingsD): array
	{
		$settings = [];
		foreach ([
			'lunch_time_start', 'lunch_time_end',
			'judging_breaks_morning_break_starting_time', 'judging_breaks_morning_break_finish_time',
			'judging_breaks_afternoon_break_start_time', 'judging_breaks_afternoon_break_finish_time',
			'sports_day_starting_time', 'sports_day_finish_time',
			'sports_day_other_starting_time', 'sports_day_other_finish_time',
		] as $field) {
			$settings[$field] = $this->normalizeWizardTime($schedulingsD->{$field} ?? null);
		}
		foreach (['judging_breaks_yes_no', 'sports_day_yes_no', 'sports_day_having_events_after_sport_yes_no'] as $field) {
			$settings[$field] = (int)($schedulingsD->{$field} ?? 0);
		}
		$settings['sports_day'] = $schedulingsD->sports_day ?? null;

		$dates = [];
		$startDate = date('Y-m-d', strtotime((string)$schedulingsD->start_date));
		$numberOfDays = max(1, (int)($schedulingsD->number_of_days ?? 1));
		for ($index = 0; $index < $numberOfDays; $index++) {
			$date = date('Y-m-d', strtotime($startDate.' +'.$index.' day'));
			$dates[] = ['date' => $date, 'day' => date('l', strtotime($date))];
		}

		return SlotPlanner::blockedPeriodsFromSettings($settings, $dates);
	}

	/**
	 * Places one event's units through the shared ledger.
	 *
	 * @param array<int,int> $roomLanes room id => lanes
	 */
	private function placeWithLedger($conventionSD, $schedulingsD, array $units, array $bands, array $roomLanes, int $duration, int $eventId, int $batchSize = 1, array $knockoutRows = [], ?TimetableLedger $ledger = null): array
	{
		if ($this->timetableLedger === null) {
			$this->timetableLedger = $this->buildTimetableLedger($conventionSD);
		}
		$ledger ??= $this->timetableLedger;
		$startDate = date('Y-m-d', strtotime((string)$schedulingsD->start_date));
		$lunchStart = $this->normalizeWizardTime($schedulingsD->lunch_time_start);
		$lunchEnd = $this->normalizeWizardTime($schedulingsD->lunch_time_end);
		$resolverCache = [];
		$resolver = function (array $band, int $roomId, string $start, string $finish) use ($schedulingsD, $startDate, $duration, $lunchStart, $lunchEnd, $eventId, &$resolverCache) {
			$cacheKey = $band['date'].'|'.$band['start'].'|'.$band['end'].'|'.$roomId.'|'.$start;
			if (!array_key_exists($cacheKey, $resolverCache)) {
				$dayNumber = $this->getConventionDayNumber($startDate, $band['date']);
				$validSlot = $this->findValidSlot($start, $finish, $band['day'], $band['date'], $dayNumber, $band['start'], $band['end'], $duration, $schedulingsD, $lunchStart, $lunchEnd, $roomId, $eventId);
				$resolverCache[$cacheKey] = (!empty($validSlot['window_exhausted']) || !$this->isValidSlotInsideBand($band, $validSlot))
					? null
					: ['start' => $validSlot['start_time'], 'finish' => $validSlot['finish_time']];
			}

			return $resolverCache[$cacheKey];
		};

		$planner = new SlotPlanner($ledger, $resolver, $knockoutRows, $this->buildBlockedPeriods($schedulingsD), self::PREFERRED_GAP_MINUTES);

		return $planner->place($units, $bands, $roomLanes, $duration, $eventId, $batchSize);
	}

	/**
	 * Balanced Days places the heaviest events (bookings x duration) first, so the large
	 * events are spread before the small ones fill the gaps. Other strategies keep the
	 * existing event order.
	 */
	private function orderEventsForStrategy($conventionSD, $schedulingsD, int $category, array $eventIds): array
	{
		if ($this->getScheduleBandMode($schedulingsD) !== DayStrategy::BALANCED || count($eventIds) < 2) {
			return $eventIds;
		}
		$rowCounts = [];
		$rows = $this->Schedulingtimings->find()
			->select(['event_id', 'total' => 'COUNT(*)'])
			->where([
				'Schedulingtimings.conventionseasons_id' => $conventionSD->id,
				'Schedulingtimings.schedule_category' => $category,
				'COALESCE(Schedulingtimings.is_bye, 0) =' => 0,
			])
			->group(['event_id'])
			->enableHydration(false)
			->toArray();
		foreach ($rows as $row) {
			$rowCounts[(int)$row['event_id']] = (int)$row['total'];
		}
		$workload = [];
		foreach ($this->Events->find()->select(['id', 'setup_time', 'round_time', 'judging_time'])->where(['Events.id IN' => $eventIds])->enableHydration(false) as $event) {
			$workload[(int)$event['id']] = ($rowCounts[(int)$event['id']] ?? 0) * ((int)$event['setup_time'] + (int)$event['round_time'] + (int)$event['judging_time']);
		}
		$position = array_flip(array_values($eventIds));
		$ordered = array_values($eventIds);
		usort($ordered, fn ($a, $b) => [($workload[(int)$b] ?? 0), $position[$a]] <=> [($workload[(int)$a] ?? 0), $position[$b]]);

		return $ordered;
	}

	/**
	 * One readable line per Category 1 entry with a blank group name or no submission:
	 * "School - event (group): no group name, no event submission; students 1, 2".
	 */
	private function describeCategoryOneEntryIssues(array $issues): array
	{
		if (!$issues) {
			return [];
		}
		$schoolNames = [];
		$schoolIds = array_values(array_unique(array_column($issues, 'school_id')));
		foreach ($this->fetchTable('Users')->find()->select(['id', 'first_name', 'last_name'])->where(['Users.id IN' => $schoolIds])->enableHydration(false) as $user) {
			$schoolNames[(int)$user['id']] = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
		}
		$lines = [];
		foreach ($issues as $issue) {
			$problems = [];
			if ($issue['missing_group']) {
				$problems[] = 'no group name';
			}
			if ($issue['missing_submission']) {
				$problems[] = 'no event submission (not scheduled)';
			}
			$school = ($schoolNames[$issue['school_id']] ?? '') ?: 'school #'.$issue['school_id'];
			$group = $issue['missing_group'] ? '' : ' (group '.$issue['group'].')';
			$lines[] = $school.' - '.$issue['event'].$group.': '.implode(', ', $problems).'; students '.implode(', ', array_filter($issue['students']));
		}
		sort($lines);

		return $lines;
	}

	/**
	 * Places one event using the season's day strategy (see DayStrategy). Each trial runs on
	 * a copy of the ledger, and only the chosen trial's bookings are kept, so a strategy can
	 * change which day an event starts on but never weakens a hard rule.
	 */
	private function placeEventWithStrategy($conventionSD, $schedulingsD, array $units, array $bands, array $roomLanes, int $duration, int $eventId, int $batchSize = 1, array $knockoutRows = [], $eventTweak = null): array
	{
		if ($this->timetableLedger === null) {
			$this->timetableLedger = $this->buildTimetableLedger($conventionSD);
		}
		$strategy = $this->getScheduleBandMode($schedulingsD);
		// Pinned day/start events keep the date-ordered bands so the pinned start is honoured.
		if (($eventTweak && (!empty($eventTweak->pinned_day) || !empty($eventTweak->pinned_start_time))) || !$units) {
			$strategy = DayStrategy::EARLIEST;
		}

		$studentIds = [];
		foreach ($units as $unit) {
			$studentIds = array_merge($studentIds, $unit['students'] ?? []);
		}
		$conventionBands = $this->buildConventionBands($schedulingsD);
		$capacity = DayStrategy::capacityByDate($conventionBands, $this->buildBlockedPeriods($schedulingsD), 1);
		$dayEnds = DayStrategy::dayEndByDate($conventionBands);

		$run = DayStrategy::run(
			$strategy,
			$bands,
			$this->timetableLedger,
			// Later knockout rounds may not move to an earlier day, so knockouts never wrap.
			!$knockoutRows,
			fn (TimetableLedger $ledger, array $trialBands) => $this->placeWithLedger($conventionSD, $schedulingsD, $units, $trialBands, $roomLanes, $duration, $eventId, $batchSize, $knockoutRows, $ledger),
			fn (TimetableLedger $ledger, array $result) => DayStrategy::cost($result['placed'], count($result['unscheduled']), $ledger->busyMinutesByDate(), $capacity, $dayEnds, $ledger->tightGaps($studentIds, self::PREFERRED_GAP_MINUTES), true)
		);
		$this->timetableLedger = $run['ledger'];

		return $run['result'];
	}

	/**
	 * Category 2/3 units in placement order, with confirmed and possible participants.
	 */
	private function buildKnockoutUnits($eventD, array $pendingRows, array $knockoutRows, array $roundMatchCountMap): array
	{
		$people = SlotPlanner::knockoutParticipants($knockoutRows);
		$units = [];
		foreach ($pendingRows as $row) {
			$rowId = (int)$row->id;
			$roundMatches = (int)($roundMatchCountMap[(int)$row->round_number] ?? 0);
			$units[] = [
				'id' => 'r'.$rowId,
				'row_id' => $rowId,
				'students' => $people[$rowId]['confirmed'] ?? [],
				'possible' => $people[$rowId]['possible'] ?? [],
				'capacity' => $this->shouldDisableParallelNumberGamesForEliminationRound($eventD, $roundMatches) ? 1 : null,
			];
		}

		return $units;
	}

	/**
	 * Saves planner placements for existing rows (unit keys are 'r' + row id).
	 */
	private function savePlacedRows(array $placed): int
	{
		$saved = 0;
		foreach ($placed as $unitKey => $slot) {
			$rowId = (int)substr((string)$unitKey, 1);
			$saved += $this->Schedulingtimings->updateAll([
				'room_id' => $slot['room_id'],
				'day' => $slot['day'],
				'start_time' => $slot['start'],
				'finish_time' => $slot['finish'],
				'sch_date_time' => $slot['date'].' '.$slot['start'],
				'modified' => date('Y-m-d H:i:s'),
			], ['id' => $rowId]);
		}

		return $saved;
	}

	/**
	 * Adds bookings that could not be placed without breaking a hard rule to the run report.
	 */
	private function recordUnscheduled(int $category, $eventD, array $unscheduled, array $unitsByKey, int $duration, array $roomLanes, $eventTweak = null): void
	{
		foreach ($unscheduled as $unitKey => $info) {
			$unit = $unitsByKey[(string)$unitKey] ?? [];
			$reasons = [];
			foreach ($info['reasons'] as $code => $count) {
				$reasons[] = (SlotPlanner::REASON_TEXT[$code] ?? $code).' ('.$count.'x)';
			}
			$this->unscheduledReport[] = [
				'category' => $category,
				'event_id' => (int)$eventD->id,
				'event_number' => (string)$eventD->event_id_number,
				'event_name' => (string)($eventD->event_name ?? ''),
				'booking' => (string)($unit['label'] ?? $unitKey),
				'row_ids' => $unit['row_ids'] ?? [],
				'students' => $unit['students'] ?? [],
				'possible_students' => $unit['possible'] ?? [],
				'duration_minutes' => $duration,
				'rooms' => array_keys($roomLanes),
				'pinned' => $eventTweak ? array_filter([
					'day' => $eventTweak->pinned_day ?? null,
					'room' => $eventTweak->pinned_room_id ?? null,
					'start' => $eventTweak->pinned_start_time ? date('H:i', strtotime((string)$eventTweak->pinned_start_time)) : null,
				]) : [],
				'reasons' => $reasons,
				'blocking_students' => $info['students'] ?? [],
			];
		}
	}

	private function unscheduledReportPath($conventionSD): string
	{
		return LOGS.'scheduling_unscheduled_season_'.(int)$conventionSD->id.'.json';
	}

	/**
	 * Saves this category's unscheduled bookings to the run report and warns the admin.
	 */
	private function flushUnscheduledReport($conventionSD, int $category): void
	{
		$path = $this->unscheduledReportPath($conventionSD);
		$report = is_file($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];
		$report['generated'] = date('Y-m-d H:i:s');
		$report['categories'][$category] = $this->unscheduledReport;
		file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));

		if ($this->unscheduledReport) {
			$byEvent = [];
			foreach ($this->unscheduledReport as $entry) {
				$byEvent[$entry['event_number']] = ($byEvent[$entry['event_number']] ?? 0) + 1;
			}
			$summary = [];
			foreach ($byEvent as $eventNumber => $count) {
				$summary[] = $eventNumber.' ('.$count.')';
			}
			$this->Flash->warning('Category '.$category.': '.count($this->unscheduledReport).' booking(s) were left unscheduled because no slot met every rule (room, students, hours, breaks, pinned settings, earlier rounds). Events: '.implode(', ', $summary).'. Details: logs/'.basename($path).'.');
			\Cake\Log\Log::warning('Category '.$category.' unscheduled bookings season '.(int)$conventionSD->id.': '.json_encode($this->unscheduledReport));
		}
		$this->unscheduledReport = [];
	}

	private function getConventionSeasonBySlug($conventionSeasonSlug)
	{
		return $this->Conventionseasons
			->find()
			->where(['Conventionseasons.slug' => $conventionSeasonSlug])
			->contain(['Conventions'])
			->first();
	}

}

?>
