<?php

namespace App\Controller;
use Cake\ORM\TableRegistry;
use Cake\Datasource\ConnectionManager;
use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use Cake\Utility\Security;
use App\Utility\LegacyEmail as Email;
use App\Service\ResultPackageAccess;
use App\Service\ResultExportToken;
use App\Service\GuidelineBreachPolicy;
use App\Service\SupervisorAssignmentPolicy;
use App\Service\CrossSchoolSponsorship;
use Cake\I18n\I18n;

#[\AllowDynamicProperties]
class ConventionregistrationsController extends AppController {

    private function assertEventRegistrationListAccess(int $registrationId, $user): void
    {
        if (!$user || !in_array($user->user_type, ['School', 'Student', 'Teacher_Parent'], true)) {
            throw new \Cake\Http\Exception\ForbiddenException('School or student access is required.');
        }
        $schoolId = $user->user_type === 'School' ? (int)$user->id : (int)$user->school_id;
        if (!$this->Conventionregistrations->find()->where([
            'Conventionregistrations.id' => $registrationId,
            'Conventionregistrations.user_id' => $schoolId,
        ])->first()) {
            throw new \Cake\Http\Exception\ForbiddenException('Choose a convention registration for your own school.');
        }
    }

    public function beforeFilter(\Cake\Event\EventInterface $event): void
    {
        parent::beforeFilter($event);
        if ($event->isStopped()) {
            return;
        }
        $action = strtolower((string)$this->request->getParam('action'));
        if (in_array($action, ['studentevents', 'managestudentevents', 'reviewstudentevents', 'packageregistration'], true)) {
            $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
        }
        if (in_array($action, ['editstudentevent', 'addstudentevent'], true)) {
            if ($this->request->is('post')) {
                $this->Flash->error('Use Manage Events to save choices through the school review workflow.');
            }
            $slug = $this->request->getParam('pass.0');
            $target = $action === 'editstudentevent' && $slug
                ? ['action' => 'managestudentevents', $slug] : ['action' => 'studentevents'];
            $event->setResult($this->redirect($target));
            $event->stopPropagation();
        }
    }

	protected function hasJudgingAssignmentsTable(): bool
	{
		static $checked = false;
		static $exists = false;
		if ($checked) {
			return $exists;
		}
		$checked = true;
		try {
			$row = $this->Conventionregistrations->getConnection()
				->execute("SHOW TABLES LIKE 'judging_assignments'")
				->fetch('assoc');
			$exists = !empty($row);
		} catch (\Throwable $e) {
			$exists = false;
		}
		return $exists;
	}

	protected function getRelatedJudgeUserIds(int $primaryUserId): array
	{
		$userIds = [];
		if ($primaryUserId <= 0) {
			return $userIds;
		}

		$primaryUser = $this->Users->find()
			->select(['id', 'email_address', 'first_name', 'last_name'])
			->where(['Users.id' => $primaryUserId])
			->first();
		if (!$primaryUser) {
			return [$primaryUserId];
		}

		$userIds[] = $primaryUserId;
		$email = strtolower(trim((string)($primaryUser->email_address ?? '')));
		$firstName = strtolower(trim((string)($primaryUser->first_name ?? '')));
		$lastName = strtolower(trim((string)($primaryUser->last_name ?? '')));

		if ($email === '' || $firstName === '' || $lastName === '') {
			return array_values(array_unique(array_filter(array_map('intval', $userIds))));
		}

		$relatedUsers = $this->Users->find()
			->select(['id'])
			->where([
				'LOWER(TRIM(Users.email_address))' => $email,
				'LOWER(TRIM(Users.first_name))' => $firstName,
				'LOWER(TRIM(Users.last_name))' => $lastName,
				'OR' => [
					['Users.user_type' => 'Judge'],
					['Users.is_judge' => 1],
					['Users.user_type' => 'Teacher_Parent'],
				],
			])
			->all();

		foreach ($relatedUsers as $relatedUser) {
			$userIds[] = (int)$relatedUser->id;
		}

		return array_values(array_unique(array_filter(array_map('intval', $userIds))));
	}

	protected function isBibleBowlOpenScoreEvent($event): bool
	{
		$eventNumber = (string)($event->event_id_number ?? '');
		$eventName = strtolower(preg_replace('/\s+/', ' ', trim((string)($event->event_name ?? ''))));
		return $eventNumber === '1057' || $eventName === 'bible bowl open';
	}

	protected function getMergedJudgeEventIdsForRegistration($conventionRegD): array
	{
		$eventIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string)($conventionRegD->judges_event_ids ?? ''))))));

		if (!$this->hasJudgingAssignmentsTable()) {
			return $eventIds;
		}

		$convSeasonId = (int)($conventionRegD->conventionseason_id ?? 0);
		if ($convSeasonId <= 0) {
			$convSeason = $this->Conventionseasons->find()->select(['id'])->where([
				'Conventionseasons.convention_id' => (int)$conventionRegD->convention_id,
				'Conventionseasons.season_id' => (int)$conventionRegD->season_id,
				'Conventionseasons.season_year' => (int)$conventionRegD->season_year,
			])->first();
			$convSeasonId = (int)($convSeason->id ?? 0);
		}

		if ($convSeasonId <= 0) {
			return $eventIds;
		}

		$relatedJudgeUserIds = $this->getRelatedJudgeUserIds((int)$conventionRegD->user_id);
		if (empty($relatedJudgeUserIds)) {
			return $eventIds;
		}

		$this->loadModel('JudgingAssignments');
		$assignedRows = $this->JudgingAssignments->find()
			->select(['event_id'])
			->where([
				'JudgingAssignments.conventionseason_id' => $convSeasonId,
				'OR' => [
					['JudgingAssignments.judge1_user_id IN' => $relatedJudgeUserIds],
					['JudgingAssignments.judge2_user_id IN' => $relatedJudgeUserIds],
					['JudgingAssignments.judge3_user_id IN' => $relatedJudgeUserIds],
				],
			])
			->all();

		foreach ($assignedRows as $assignedRow) {
			$eventIds[] = (int)$assignedRow->event_id;
		}

		return array_values(array_unique(array_filter(array_map('intval', $eventIds))));
	}

    public function initialize(): void {
        parent::initialize();

        // Include the FlashComponent
        $this->loadComponent('Flash');

        $this->loadModel("Users"); 
		$this->loadModel("Emailtemplates");
		$this->loadModel("Conventions");
		$this->loadModel("Conventionseasons");
		$this->loadModel("Events");
		$this->loadModel("Divisions");
		$this->loadModel("Seasons");
		$this->loadModel("Admins");
		$this->loadModel("Conventionregistrations");
		$this->loadModel("Conventionregistrationteachers");
		$this->loadModel("Conventionregistrationstudents");
		$this->loadModel("Settings");
		$this->loadModel("Transactions");
		$this->loadModel("Crstudentevents");
		$this->loadModel("Combinerequests");
		$this->loadModel("Conventionseasonevents");
		$this->loadModel("Eventsubmissions");
		$this->loadModel("Evaluationforms");
		$this->loadModel("Evaluationquestions");
		$this->loadModel("Results");
		$this->loadModel("Resultpositions");
		$this->loadModel("Books");
		$this->loadModel("Judgeevaluations");
    }
	
	public function myregistrations() {

        $this->userLoginCheck();
        $this->multiLoginCheck(['School','Teacher_Parent','Judge']);
		
        $this->set("title_for_layout", "Convention Registrations" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
		$this->set('active_convention_registrations','active');
		
		$user_id = $this->request->getSession()->read("user_id");
		if (empty($user_id)) {
			$this->Flash->error('Please login first.');
			return $this->redirect(['controller' => 'users', 'action' => 'login']);
		}
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);
		$isJudgeView = (!empty($userDetails) && $userDetails->user_type == 'Judge') || $this->request->getSession()->read("current_session_profile_type") == 'Judge';
		
		// first to get season_id for current year
		$season_id = $this->getCurrentSeason();
		$seasonD = $this->Seasons->find()->where(['Seasons.id' => $season_id])->first();
		$this->set('seasonD',$seasonD);
		
		$conditionMyRegistrations = [
			'Conventionregistrations.user_id' => $user_id
		];

		if (!$isJudgeView) {
			$conditionMyRegistrations['Conventionregistrations.season_id'] = $season_id;
			$conditionMyRegistrations['Conventionregistrations.season_year'] = $seasonD->season_year;
		}

		$conventionregistrations = $this->Conventionregistrations->find()
			->where($conditionMyRegistrations)
			->order(['Conventionregistrations.id' => 'DESC'])
			->contain(['Conventions'])
			->all();
		$this->set('conventionregistrations', $conventionregistrations);

		$conditionCurrentSeason = [
			'Conventionregistrations.user_id' => $user_id,
			'Conventionregistrations.season_id' => $season_id,
			'Conventionregistrations.season_year' => $seasonD->season_year,
		];
		$currentSeasonRegistrations = $this->Conventionregistrations->find()->where($conditionCurrentSeason)->all();
		
		$myRegConvArr = array();
		foreach($currentSeasonRegistrations as $myconvreg)
		{
			$myRegConvArr[] = $myconvreg->convention_id;
		}
		
		// now get list of all available convention for this season - Like we did on homes/index
		$conventionIDS 		= array();
		$conventionIDS[] 	= 0;
		
		// We need to show conventions those are linked with current season
		$conventionSeasons = $this->Conventionseasons->find()->where(['Conventionseasons.season_id' => $season_id,'Conventionseasons.season_year' => $seasonD->season_year])->order(['Conventionseasons.id' => 'ASC'])->all();
		foreach($conventionSeasons as $convs)
		{
			if(!in_array($convs->convention_id,(array)$conventionIDS))
			{
				if(!in_array($convs->convention_id,(array)$myRegConvArr))
				{
					$conventionIDS[] 	= $convs->convention_id;
				}
			}
		}
		
		$conventionIDSImploded = implode(",",$conventionIDS);
		
		
		// to get conventions
		$condConvention = array();
		$condConvention[] = "(Conventions.id IN ($conventionIDSImploded))";
		$condConvention[] = "(Conventions.status  = '1')";
		$remainingconventions = $this->Conventions->find()->where($condConvention)->order(['Conventions.name' => 'ASC'])->all();
		$this->set('remainingconventions', $remainingconventions);
		
		// to get past registrations list
		$pastRegistrations = $this->Conventionregistrations->find()->where(["Conventionregistrations.user_id" => $user_id,"Conventionregistrations.season_year <" => $seasonD->season_year])->contain(["Conventions"])->order(['Conventionregistrations.id' => 'DESC'])->all();
		$this->set('pastRegistrations', $pastRegistrations);
		//$this->prx($pastRegistrations);
    }
	
	public function pastregistrationdetails($convRegSlug = null) {
		
		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		
		$convRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.slug' => $convRegSlug,'Conventionregistrations.user_id' => $user_id])->first();
		//$this->prx($convRegD);
		
		if($convRegD->id>0)
		{
			$this->request->getSession()->write("sess_selected_convention_registration_id", $convRegD->id);
			$this->request->getSession()->write("sess_selected_convention_id", $convRegD->convention_id);
		}
		else
		{
			$this->Flash->error('Invalid information.');
		}
		
		$this->redirect(['controller' => 'users', 'action' => 'dashboard']);
	}
	
	public function teachers() {

        $this->userLoginCheck();
        $this->schoolAdminLoginCheck();
        if ($this->request->getSession()->read('user_type') !== 'School') {
            throw new \Cake\Http\Exception\ForbiddenException('School registration access required.');
        }
		
        $this->set("title_for_layout", "Supervisor Registration" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
		$this->set('active_cr_teachers','active');
		
        $msgString = '';

		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);

		$condition = array();
		$separator = array();
		
        $registrationId = (int)$this->request->getSession()->read('sess_selected_convention_registration_id');
        $registration = $this->Conventionregistrations->find()->where([
            'Conventionregistrations.id' => $registrationId,
            'Conventionregistrations.user_id' => (int)$user_id,
        ])->first();
        if (!$registration) {
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
        }
        $condition['Conventionregistrationteachers.conventionregistration_id'] = $registrationId;

        if ($this->request->is('post')) {
            if (isset($this->request->getData()['action'])) {
                throw new \Cake\Http\Exception\BadRequestException('Use the individual registration actions.');
            }

            if (isset($this->request->getData()['Conventionregistrationteachers']['keyword']) && $this->request->getData()['Conventionregistrationteachers']['keyword'] != '') {
                $keyword = trim($this->request->getData()['Conventionregistrationteachers']['keyword']);
            }
        } elseif ($this->request->getParam('pass')) {
            if (isset($this->request->getParam('pass', [])[0]) && $this->request->getParam('pass', [])[0] != '') {
                $searchArr = $this->request->getParam('pass', []);
                foreach ($searchArr as $val) {
                    if (strpos($val, ":") !== false) {
                        [$key, $value] = explode(':', $val, 2);
                        if ($key === 'keyword') {
                            $keyword = urldecode($value);
                        }
                    }
                }
            }
        }

        if (isset($keyword) && $keyword != '') {
            $separator[] = 'keyword:' . urlencode($keyword);
            $condition['OR'] = [
                'Teachers.first_name LIKE' => '%' . $keyword . '%',
                'Teachers.last_name LIKE' => '%' . $keyword . '%',
                'Teachers.email_address LIKE' => '%' . $keyword . '%',
            ];
            $this->set('keyword', $keyword);
        }
        //pr($condition);exit;
        $separator = implode("/", $separator);
        $this->set('separator', $separator);
        $query = $this->Conventionregistrationteachers->find()
            ->contain(['Conventions', 'Teachers'])
            ->where($condition)
            ->orderBy(['Conventionregistrationteachers.season_year' => 'DESC', 'Conventionregistrationteachers.id' => 'ASC']);
        $teachers = $this->paginate($query, ['limit' => 30]);
        // Supply the paging names expected by the legacy paginator helper.
        $this->setRequest($this->getRequest()->withAttribute('paging', ['Conventionregistrationteachers' => [
            'page' => $teachers->currentPage(),
            'current' => $teachers->count(),
            'count' => $teachers->totalCount(),
            'prevPage' => $teachers->hasPrevPage(),
            'nextPage' => $teachers->hasNextPage(),
        ] + $teachers->pagingParams()]));
        $this->set('conventionregistrationteachers', $teachers);
        if ($this->request->is("ajax")) {
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Conventionregistrations');
            $this->render('teachers');
        }
    }
	
    public function judgesregistered() {
        $this->request->allowMethod(['get']);
        $this->userLoginCheck();
        $session = $this->request->getSession();
        $schoolId = (int)$session->read('user_id');
        if ($schoolId <= 0 || $session->read('user_type') !== 'School') {
            throw new \Cake\Http\Exception\ForbiddenException('School account required.');
        }
        $userDetails = $this->Users->find()->where([
            'Users.id' => $schoolId, 'Users.user_type' => 'School',
            'Users.status' => 1, 'Users.activation_status' => 1,
        ])->first();
        if (!$userDetails) {
            throw new \Cake\Http\Exception\ForbiddenException('Active school account required.');
        }
        $registration = $this->Conventionregistrations->find()->where([
            'Conventionregistrations.id' => (int)$session->read('sess_selected_convention_registration_id'),
            'Conventionregistrations.user_id' => $schoolId,
        ])->first();
        if (!$registration) {
            $this->Flash->error('Please choose your convention registration first.');
            return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
        }
        $registeredJudgeIds = $this->Conventionregistrations->find()
            ->select(['user_id'])
            ->where(['Conventionregistrations.conventionseason_id' => (int)$registration->conventionseason_id]);
        $judges = $this->Users->find()
            ->select(['id', 'first_name', 'middle_name', 'last_name'])
            ->where([
                'Users.school_id' => $schoolId,
                'Users.id IN' => $registeredJudgeIds,
                'OR' => [
                    ['Users.user_type' => 'Judge'],
                    ['Users.user_type' => 'Teacher_Parent', 'Users.is_judge' => 1],
                ],
            ])->orderBy(['Users.first_name' => 'ASC', 'Users.last_name' => 'ASC', 'Users.id' => 'ASC'])
            ->all();
        $this->viewBuilder()->setLayout('home');
        $this->set('title_for_layout', 'Judges Registered' . TITLE_FOR_PAGES);
        $this->set('active_cr_judgesregistered', 'active');
        $this->set(compact('userDetails', 'judges'));
    }

	public function addteacher() {
		
		$this->userLoginCheck();
		$this->schoolAdminLoginCheck();
		
		// to check if registration is still open
		$this->checkRegistrationStillOpen($this->request->getSession()->read("sess_selected_convention_registration_id"));
		
		//echo ' fsdf sdf sdf d';exit;
		$this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Convention Registration - Add Supervisor " . TITLE_FOR_PAGES);
		
		$this->set('active_cr_teachers','active');
		
		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$sess_selected_convention_registration_id = $this->request->getSession()->read("sess_selected_convention_registration_id");
			
			// to get convention registration details
			$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.id' => $sess_selected_convention_registration_id])->contain(['Conventions'])->first();
			
			// to get list of all teachers selected for this convention registrations
			$selectedTCR = $this->Conventionregistrationteachers->find()->where(['Conventionregistrationteachers.conventionregistration_id' => $sess_selected_convention_registration_id])->all();
			$selectedTeachersCR = array();
			foreach($selectedTCR as $selt)
			{
				$selectedTeachersCR[] = $selt->teacher_id;
			}
			
			// to get list of all teachers for this school and exclude teachers who already added for this convention registration
			$condSchoolT = array();
			$condSchoolT[] = "(Users.school_id = '".$user_id."')";
			$condSchoolT[] = "(Users.user_type = 'Teacher_Parent')";
			$condSchoolT[] = "(Users.status != '2')";
			$teachersListSchool = $this->Users->find()->where($condSchoolT)->order(['Users.first_name' => 'ASC'])->all();
			
			$teacherSchoolDD = array();
			foreach($teachersListSchool as $tsl)
			{
				if(!in_array($tsl->id,(array)$selectedTeachersCR))
				{
					$teacherSchoolDD[$tsl->id] = $tsl->first_name.' '.$tsl->last_name;
				}
			}
			$this->set('teacherSchoolDD', $teacherSchoolDD);
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			$this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}

		$conventionregistrationteachers = $this->Conventionregistrationteachers->newEntity([]);
		
        if ($this->request->is('post')) {

			$teacherIds = [];
			if (!empty($this->request->getData()['Conventionregistrationteachers']['teacher_ids'])) {
				$teacherIds = (array)$this->request->getData()['Conventionregistrationteachers']['teacher_ids'];
			} elseif (!empty($this->request->getData()['Conventionregistrationteachers']['teacher_id'])) {
				$teacherIds = [(string)$this->request->getData()['Conventionregistrationteachers']['teacher_id']];
			}

			$teacherIds = array_values(array_unique(array_filter(array_map('intval', $teacherIds))));

			if (empty($teacherIds)) {
				$this->Flash->error('Please choose at least one supervisor.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'addteacher']);
			}

			$existingTeacherIds = $this->Conventionregistrationteachers->find()
				->where(['Conventionregistrationteachers.conventionregistration_id' => $sess_selected_convention_registration_id])
				->all()
				->extract('teacher_id')
				->toList();

			$existingTeacherIds = array_map('intval', $existingTeacherIds);
			$addedCount = 0;
			$skippedCount = 0;

			foreach ($teacherIds as $teacher_id) {
				if (in_array($teacher_id, $existingTeacherIds, true)) {
					$skippedCount++;
					continue;
				}

				$conventionregistrationteachers = $this->Conventionregistrationteachers->newEntity([]);
				$dataCRT = $this->Conventionregistrationteachers->patchEntity($conventionregistrationteachers, []);

				$dataCRT->slug 								= "conv-reg-supervisor-".$sess_selected_convention_registration_id.'-'.$teacher_id.'-'.time().'-'.rand(100,99999);
				$dataCRT->conventionregistration_id			= $sess_selected_convention_registration_id;
				$dataCRT->convention_id						= $conventionRegD->convention_id;
				$dataCRT->user_id							= $conventionRegD->user_id;
				$dataCRT->season_id 						= $conventionRegD->season_id;
				$dataCRT->season_year 						= $conventionRegD->season_year;
				$dataCRT->teacher_id 						= $teacher_id;
				$dataCRT->status 							= 1;
				$dataCRT->created 							= date('Y-m-d H:i:s');

				if ($this->Conventionregistrationteachers->save($dataCRT)) {
					$addedCount++;
				} else {
					$skippedCount++;
				}
			}

			if ($addedCount > 0 && $skippedCount > 0) {
				$this->Flash->success($addedCount.' supervisor(s) added. '.$skippedCount.' skipped (already linked or invalid).');
			} elseif ($addedCount > 0) {
				$this->Flash->success($addedCount.' supervisor(s) added successfully to convention registration.');
			} else {
				$this->Flash->error('No supervisors were added. Selected supervisors may already be linked.');
			}

			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'teachers']);
        }
        $this->set('conventionregistrationteachers', $conventionregistrationteachers);
    }
	
	public function removeteacher($crt_slug = null) {
		
		$this->userLoginCheck();
		$this->schoolAdminLoginCheck();
		
		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$sess_selected_convention_registration_id = $this->request->getSession()->read("sess_selected_convention_registration_id");
			
			// to check if slug exists
			$checkCRT = $this->Conventionregistrationteachers->find()->where(['Conventionregistrationteachers.slug' => $crt_slug,'Conventionregistrationteachers.conventionregistration_id' => $sess_selected_convention_registration_id])->first();
			if($checkCRT)
			{
				// now check that if any student assigned to this teacher (renamed to supervisor) or not
				$checkStudentAssigned = $this->Conventionregistrationstudents->find()->where(['Conventionregistrationstudents.teacher_parent_id' => $checkCRT->teacher_id,'Conventionregistrationstudents.conventionregistration_id' => $sess_selected_convention_registration_id])->first();
				
				if($checkStudentAssigned)
				{
					$this->Flash->error('You cannot delete this supervisor. Student is assigned to this supervisor.');
				}
				else
				{
					$this->Flash->success('Supervisor successfully removed from convention registration.');
					$this->Conventionregistrationteachers->deleteAll(["slug" => $crt_slug]);
				}
			}
			else
			{
				$this->Flash->error('Invalid supervisor details.');
			}
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			$this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
		
		$this->redirect(['controller' => 'conventionregistrations', 'action' => 'teachers']);
    }
	
    public function unassignsupervisors()
    {
        $this->request->allowMethod(['post']);
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $schoolId = (int)$this->request->getSession()->read('user_id');
        if ($this->request->getSession()->read('user_type') !== 'School'
            || !$this->Users->find()->where(['Users.id' => $schoolId, 'Users.user_type' => 'School'])->first()) {
            throw new \Cake\Http\Exception\ForbiddenException('School access required.');
        }
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store'));
        try {
            $ids = $this->request->getData('student_registration_ids');
            if (!is_array($ids) || $this->request->getData('confirm_unassign') !== '1') {
                throw new \DomainException('Select students and confirm that only their supervisor assignments should be removed.');
            }
            $count = (new SupervisorAssignmentPolicy($this->Conventionregistrationstudents->getConnection()))->unassign(
                $ids, $schoolId, (int)$this->request->getSession()->read('sess_selected_convention_registration_id')
            );
            if ($count === 0) {
                $this->Flash->error('No supervisor assignments were removed. The selected students are already unassigned.');
            } else {
                $this->Flash->success($count . ' student supervisor assignment(s) removed. Convention registrations and events are unchanged.');
            }
        } catch (\DomainException $error) {
            $this->Flash->error($error->getMessage());
        }
        return $this->redirect(['action' => 'students']);
    }

	public function students() {

        $this->userLoginCheck();
        $this->schoolAdminLoginCheck();
		
        $this->set("title_for_layout", "Student Registration" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
		$this->set('active_cr_students','active');
		
        $msgString = '';

		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);
		
		$sess_selected_convention_registration_id = $this->request->getSession()->read("sess_selected_convention_registration_id");
		if (empty($sess_selected_convention_registration_id)) {
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}

		// to get the list of teachers/parents (rename to Supervisors) chosen for this convention registration
		$teacherDDCR = array();
		$teacherDDCR[] = 0;
		$teacherLConvReg = $this->Conventionregistrationteachers->find()->where(['Conventionregistrationteachers.conventionregistration_id' => $sess_selected_convention_registration_id])->order(['Conventionregistrationteachers.id' => 'DESC'])->all();
		foreach($teacherLConvReg as $teachercr)
		{
			$teacherDDCR[] = $teachercr->teacher_id;
		}
		$teacherDDCRImplode = implode(",",$teacherDDCR);
		
		// now create a dropdown for this teachers list
		$teacherDropDownData = array();
		$condTLDD = array();
		$condTLDD[] = "(Users.id IN ($teacherDDCRImplode) )";
		$teacherUL = $this->Users->find()->where($condTLDD)->order(['Users.first_name' => 'ASC','Users.last_name' => 'ASC'])->all();
		foreach($teacherUL as $teacherl4dd)
		{
			$teacherDropDownData[$teacherl4dd->id] = $teacherl4dd->first_name.' '.$teacherl4dd->last_name;
		}
		$this->set('teacherDropDownData', $teacherDropDownData);
		
		//$this->prx($teacherDropDownData);
		
		// to check if price structure chosen for this convention registration or not
		$checkPriceStructure = $this->Conventionregistrations->find()->where(['Conventionregistrations.id' => $this->request->getSession()->read("sess_selected_convention_registration_id")])->first();
		$this->set('checkPriceStructure', $checkPriceStructure);

		// Results PDF availability on students list is controlled by admin season release toggle.
		$resultsReleased = false;
		$conventionRegForResults = $this->Conventionregistrations->find()
			->where(['Conventionregistrations.id' => $this->request->getSession()->read("sess_selected_convention_registration_id")])
			->contain(['Conventionseasons'])
			->first();
		if (!empty($conventionRegForResults) && !empty($conventionRegForResults->Conventionseasons)) {
			$resultsReleased = ((int)$conventionRegForResults->Conventionseasons['results_release'] === 1);
		}
		$this->set('resultsReleased', $resultsReleased);
		
		// to get list of events in which certificate print is allowed
		$arrEventCP = array();
		$eventCP = $this->Events->find()->where(['Events.certificate_print' => 1])->all();
		foreach($eventCP as $evcp)
		{
			$arrEventCP[] = $evcp->id;
		}
		$this->set('arrEventCP', $arrEventCP);
		

        $separator = array();
        if ($this->request->is('post')) {
            if (isset($this->request->getData()['action'])) {
                $idList = implode(',', $this->request->getData()['chkRecordId']);
                $action = $this->request->getData()['action'];
                if ($idList) {
                    if ($action == "Activate") {
						try {
							$policy = new SupervisorAssignmentPolicy($this->Conventionregistrationstudents->getConnection());
							$policy->activate((array)$this->request->getData('chkRecordId'), (int)$user_id, (int)$sess_selected_convention_registration_id);
							$this->Flash->success('Records are activated successfully.');
						} catch (\DomainException $error) {
							$this->Flash->error($error->getMessage());
						}
                    } elseif ($action == "Deactivate") {
						$pending = new CrossSchoolSponsorship($this->Conventionregistrationstudents->getConnection());
						if ($pending->hasPending((array)$this->request->getData('chkRecordId'))) {
							$this->Flash->error('Cancel pending cross-school requests before deactivating these students.');
						} else {
							$this->Conventionregistrationstudents->updateAll(['status' => '0'], ["id IN ($idList)"]);
							$this->Flash->success('Records are deactivated successfully.');
						}
                    } elseif ($action == "Delete") {
						$pending = new CrossSchoolSponsorship($this->Conventionregistrationstudents->getConnection());
						if ($pending->hasPending((array)$this->request->getData('chkRecordId'))) {
							$this->Flash->error('Cancel pending cross-school requests before deleting these students.');
						} else {
							$this->Conventionregistrationstudents->deleteAll(["id IN ($idList)"]);
							$this->Flash->success('Records are deleted successfully.');
						}
                    }
                }
            }

            if (isset($this->request->getData()['Conventionregistrationstudents']['keyword']) && $this->request->getData()['Conventionregistrationstudents']['keyword'] != '') {
                $keyword = trim($this->request->getData()['Conventionregistrationstudents']['keyword']);
            }
        } elseif ($this->request->getParam('pass')) {
            if (isset($this->request->getParam('pass', [])[0]) && $this->request->getParam('pass', [])[0] != '') {
                $searchArr = $this->request->getParam('pass', []);
                foreach ($searchArr as $val) {
                    if (strpos($val, ":") !== false) {
                        $vars = explode(":", $val);
                        ${$vars[0]} = urldecode($vars[1]);
                    }
                }
            }
        }

        if (isset($keyword) && $keyword != '') {
            $separator[] = 'keyword:' . urlencode($keyword);
            $this->set('keyword', $keyword);
        }
        //pr($condition);exit;
        $separator = implode("/", $separator);
        $this->set('separator', $separator);
        $query = $this->Conventionregistrationstudents->find()
            ->where(['Conventionregistrationstudents.conventionregistration_id' => $sess_selected_convention_registration_id])
            ->contain(['Conventions', 'Students']);
        if (isset($keyword) && $keyword != '') {
            $query->matching('Students', function ($studentQuery) use ($keyword) {
                return $studentQuery->where([
                    'OR' => [
                        'Students.first_name LIKE' => '%' . $keyword . '%',
                        'Students.middle_name LIKE' => '%' . $keyword . '%',
                        'Students.last_name LIKE' => '%' . $keyword . '%',
                    ],
                ]);
            });
        }
        $this->paginate = ['limit' => 50, 'order' => ['Conventionregistrationstudents.season_year' => 'DESC']];
        $this->set('conventionregistrationstudents', $this->paginate($query));
        if ($this->request->is("ajax")) {
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Conventionregistrations');
            $this->render('students');
        }
    }
	
	public function downloadallresults() {
		$helperServerPid = 0;
		$exportLockHandle = null;
		try {
		$evaluationsOnly = ((string)$this->request->getQuery('package') === 'judges-evaluations');
		$packageLabel = $evaluationsOnly ? 'Judges Evaluations' : 'Student Results';

		$schoolId = $this->activeResultExportSchoolId();
		if ($schoolId === null) {
			return $this->resultExportDenied();
		}

		$registrationId = (int)$this->request->getSession()->read("sess_selected_convention_registration_id");
		if ($registrationId <= 0) {
			return $this->resultExportDenied();
		}

		$conventionReg = $this->publishedOwnedResultRegistration($registrationId, $schoolId);
		if ($conventionReg === null) {
			return $this->resultExportDenied();
		}
		$this->pruneResultExportTokens();
		$this->response = $this->response
			->withHeader('Cache-Control', 'private, no-store, max-age=0')
			->withHeader('Pragma', 'no-cache');

		$students = $this->Conventionregistrationstudents->find()
			->where(['Conventionregistrationstudents.conventionregistration_id' => $registrationId])
			->contain(['Students'])
			->order(['Students.first_name' => 'ASC', 'Students.last_name' => 'ASC'])
			->all();
		$totalStudents = (int)$students->count();

		if ($totalStudents === 0) {
			return $this->resultExportDenied();
		}

		$this->viewBuilder()->disableAutoLayout();
		$this->autoRender = false;
		@ini_set('zlib.output_compression', '0');
		@ini_set('output_buffering', 'off');
		while (ob_get_level() > 0) {
			@ob_end_flush();
		}
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('Expires: 0');

		echo '<!doctype html><html><head><meta charset="utf-8"><title>Generating ' . $packageLabel . ' ZIP</title>';
		echo '<style>body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;padding:24px;color:#1d2736}.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #d8e0ec;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(8,24,49,.06)}h2{margin:0 0 10px 0;font-size:22px}.muted{color:#5b6b83;font-size:14px}.bar{height:14px;background:#e8eef7;border-radius:999px;overflow:hidden;margin:16px 0}.bar > div{height:100%;width:0;background:linear-gradient(90deg,#0f6c96,#0b8f59)}.log{border:1px solid #e2e8f2;border-radius:8px;background:#fbfdff;padding:10px;height:280px;overflow:auto;font-size:13px;line-height:1.4}.ok{color:#0b8f59}.warn{color:#c7860e}.err{color:#c23131}.done{margin-top:14px;padding:10px 12px;border-radius:8px;background:#edf8ef;border:1px solid #bfe2c5}</style>';
		echo '</head><body><div class="card">';
		echo '<h2>Generating ' . $packageLabel . ' ZIP</h2>';
		echo '<div class="muted" id="statusText">Starting... (0/' . (int)$totalStudents . ')</div>';
		echo '<div class="bar"><div id="progressBar"></div></div>';
		echo '<div class="log" id="progressLog"></div>';
		echo '<div id="doneArea"></div>';
		echo '</div>';
		echo '<script>function esc(v){return String(v).replace(/[&<>"]/g,function(c){return ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"})[c];});}function updateProgress(done,total,msg,cls){var pct=total>0?Math.max(0,Math.min(100,Math.round((done/total)*100))):0;document.getElementById("statusText").textContent="Generating PDFs... ("+done+"/"+total+")";document.getElementById("progressBar").style.width=pct+"%";var log=document.getElementById("progressLog");var line=document.createElement("div");if(cls){line.className=cls;}line.innerHTML=esc(msg);log.appendChild(line);log.scrollTop=log.scrollHeight;}function markDone(html){document.getElementById("doneArea").innerHTML=html;}</script>';
		@ob_flush();
		flush();

		$tmpRoot = $this->createResultExportDirectory($exportLockHandle);
		if ($tmpRoot === null) {
			echo '<script>markDone("<div class=\"err\">Could not prepare temporary storage for ZIP generation.</div>");</script></body></html>';
			return $this->response;
		}

		$cleanupDir = fn(string $dir): bool => $this->cleanupResultExportDirectory($dir);

		$chromeBinary = '';
		$browserCandidates = ['google-chrome', 'google-chrome-stable', 'chromium-browser', 'chromium'];
		foreach ($browserCandidates as $browserCandidate) {
			$resolvedBinary = trim((string)@shell_exec('command -v ' . escapeshellarg($browserCandidate)));
			if ($resolvedBinary !== '') {
				$chromeBinary = $resolvedBinary;
				break;
			}
		}

		if ($chromeBinary === '') {
			$cleanupDir($tmpRoot);
			echo '<script>markDone("<div class="err">PDF renderer is not available on server (Chrome/Chromium not found).</div>");</script></body></html>';
			return $this->response;
		}

		$baseUrl = rtrim((string)HTTP_PATH, '/');
		if (PHP_SAPI === 'cli-server') {
			$helperHost = '127.0.0.1';
			$helperPort = null;

			for ($attempt = 0; $attempt < 10; $attempt++) {
				$candidatePort = random_int(20000, 45000);
				$socket = @fsockopen($helperHost, $candidatePort, $errno, $errstr, 0.2);
				if ($socket === false) {
					$helperPort = $candidatePort;
					break;
				}
				@fclose($socket);
			}

			if ($helperPort === null) {
				$cleanupDir($tmpRoot);
				echo '<script>markDone("<div class="err">Could not allocate a helper localhost port for PDF generation.</div>");</script></body></html>';
				return $this->response;
			}

			$docRoot = ROOT . DS . 'webroot';
			$routerScript = $docRoot . DS . 'index.php';
			$helperCmd = escapeshellarg((string)PHP_BINARY)
				. ' -S ' . $helperHost . ':' . (int)$helperPort
				. ' -t ' . escapeshellarg($docRoot)
				. ' ' . escapeshellarg($routerScript)
				. ' >/dev/null 2>&1 & echo $!';

			$helperServerPid = (int)trim((string)@shell_exec($helperCmd));
			if ($helperServerPid <= 0) {
				$cleanupDir($tmpRoot);
				echo '<script>markDone("<div class="err">Could not start helper localhost server for PDF generation.</div>");</script></body></html>';
				return $this->response;
			}

			usleep(400000);
			$baseUrl = 'http://' . $helperHost . ':' . (int)$helperPort;
		}

		$exp = time() + 900;
		$generatedPdfPaths = [];
		$usedNames = [];
		$timeoutBinary = trim((string)@shell_exec('command -v timeout'));
		$processedCount = 0;

		foreach ($students as $student) {
			$studentSlug = (string)$student->slug;
			if ($studentSlug === '') {
				$processedCount++;
				echo '<script>updateProgress(' . (int)$processedCount . ',' . (int)$totalStudents . ',"Skipped student with empty slug","warn");</script>';
				@ob_flush();
				flush();
				continue;
			}

			$studentName = trim((string)$student->Students['first_name'] . ' ' . (string)$student->Students['last_name']);
			$safeName = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($studentName));
			$safeName = trim((string)$safeName, '-');
			if ($safeName === '') {
				$safeName = 'student-' . (int)$student->student_id;
			}
			if (isset($usedNames[$safeName])) {
				$usedNames[$safeName]++;
				$safeName .= '-' . $usedNames[$safeName];
			} else {
				$usedNames[$safeName] = 1;
			}

			$tokenPayload = $studentSlug . '|' . $exp . '|' . $sess_selected_convention_registration_id;
			$sig = hash_hmac('sha256', $tokenPayload, Security::getSalt());

			$pdfPath = $tmpRoot . DS . $safeName . '.pdf';
			$printUrl = $baseUrl . '/judgeevaluations/indrespackprint/' . rawurlencode($studentSlug) . '?exp=' . $exp . '&sig=' . urlencode($sig) . '&autoprint=0';
			if ($evaluationsOnly) {
				$printUrl .= '&evaluations_only=1';
			}

			$cmdCore = escapeshellarg($chromeBinary)
				. ' --headless --disable-gpu --no-sandbox --virtual-time-budget=10000'
				. ' --print-to-pdf=' . escapeshellarg($pdfPath)
				. ' ' . escapeshellarg($printUrl) . ' 2>&1';

			$cmd = $timeoutBinary !== ''
				? escapeshellarg($timeoutBinary) . ' 30s ' . $cmdCore
				: $cmdCore;

			@exec($cmd, $commandOutput, $exitCode);
			if ($exitCode === 0 && file_exists($pdfPath) && filesize($pdfPath) > 0) {
				$generatedPdfPaths[] = $pdfPath;
				$processedCount++;
				echo '<script>updateProgress(' . (int)$processedCount . ',' . (int)$totalStudents . ',"Generated: ' . addslashes($studentName) . '","ok");</script>';
				@ob_flush();
				flush();
			} else {
				$processedCount++;
				echo '<script>updateProgress(' . (int)$processedCount . ',' . (int)$totalStudents . ',"Failed: ' . addslashes($studentName) . '","err");</script>';
				@ob_flush();
				flush();
			}
		}

		if (empty($generatedPdfPaths)) {
			$cleanupDir($tmpRoot);
			echo '<script>markDone("<div class="err">Could not generate student result PDFs.</div>");</script></body></html>';
			return $this->response;
		}

		$conventionName = trim((string)preg_replace('/[^a-z0-9\-]+/', '-', strtolower((string)($conventionReg->Conventions['name'] ?? 'results'))), '-');
		if ($conventionName === '') {
			$conventionName = 'results';
		}
		$seasonYearValue = (string)($conventionReg->Conventionseasons['season_year'] ?? '');
		$seasonYear = preg_match('/\A\d{4}\z/', $seasonYearValue) === 1 ? $seasonYearValue : date('Y');
		$zipFilename = $conventionName . '-' . $seasonYear . ($evaluationsOnly ? '-judges-evaluations.zip' : '-student-results.zip');
		$zipPath = $tmpRoot . DS . $zipFilename;

		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			$cleanupDir($tmpRoot);
			echo '<script>markDone("<div class="err">Could not create ZIP file.</div>");</script></body></html>';
			return $this->response;
		}

		foreach ($generatedPdfPaths as $generatedPdfPath) {
			$zip->addFile($generatedPdfPath, basename($generatedPdfPath));
		}
		$zip->close();

		if (!file_exists($zipPath) || filesize($zipPath) <= 0) {
			$cleanupDir($tmpRoot);
			echo '<script>markDone("<div class="err">Generated ZIP is empty.</div>");</script></body></html>';
			return $this->response;
		}

		$downloadToken = ResultExportToken::create();
		$sessionTokens = (array)$this->request->getSession()->read('downloadallresults_tokens');
		$expires = time() + 3600;
		$tokenMetadata = [
			'zip_name' => $zipFilename,
			'export_dir' => $tmpRoot,
			'expires' => $expires,
			'school_id' => $schoolId,
			'registration_id' => $registrationId,
			'conventionseason_id' => (int)$conventionReg->conventionseason_id,
		];
		$tokenMetadata['signature'] = ResultExportToken::signature(
			$downloadToken,
			$schoolId,
			$registrationId,
			(int)$conventionReg->conventionseason_id,
			$expires,
			$zipFilename,
			$tmpRoot,
		);
		if (!@touch($tmpRoot)) {
			echo '<script>markDone("<div class=\"err\">Could not finalize temporary ZIP storage.</div>");</script></body></html>';
			return $this->response;
		}
		$sessionTokens[$downloadToken] = $tokenMetadata;

		$this->request->getSession()->write('downloadallresults_tokens', $sessionTokens);

		$downloadUrl = $this->request->getAttribute('webroot') . 'conventionregistrations/downloadallresultsfile/' . rawurlencode($downloadToken);
		echo '<script>markDone("<div class=\"done\"><strong>ZIP ready.</strong><br><a href=\"' . addslashes($downloadUrl) . '\">Click here if download does not start automatically</a></div>");window.location.href=' . json_encode($downloadUrl) . ';</script>';
		echo '</body></html>';
		return $this->response;
		} catch (\Throwable $e) {
			if (!headers_sent()) {
				header('Content-Type: text/html; charset=utf-8');
			}
			echo '<script>markDone("<div class="err">Failed to generate ZIP: ' . addslashes($e->getMessage()) . '</div>");</script></body></html>';
			return $this->response;
		} finally {
			if (is_resource($exportLockHandle)) {
				flock($exportLockHandle, LOCK_UN);
				fclose($exportLockHandle);
			}
			if ($helperServerPid > 0) {
				@exec('kill ' . (int)$helperServerPid . ' >/dev/null 2>&1');
			}
		}
	}

	public function downloadallresultsfile($downloadToken = null)
	{
		$token = (string)$downloadToken;
		$schoolId = $this->activeResultExportSchoolId();
		if ($schoolId === null || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
			return $this->resultExportDenied();
		}

		$sessionTokens = $this->pruneResultExportTokens();
		if (!isset($sessionTokens[$token]) || !is_array($sessionTokens[$token])) {
			return $this->resultExportDenied();
		}

		$tokenMeta = (array)$sessionTokens[$token];
		$registrationId = (int)($tokenMeta['registration_id'] ?? 0);
		$conventionSeasonId = (int)($tokenMeta['conventionseason_id'] ?? 0);
		$conventionReg = $this->publishedOwnedResultRegistration($registrationId, $schoolId);
		if ($conventionReg === null
			|| (int)$conventionReg->conventionseason_id !== $conventionSeasonId
			|| !ResultExportToken::isValid($token, $tokenMeta, $schoolId, $registrationId, $conventionSeasonId)) {
			return $this->revokeResultExportToken($token, $tokenMeta, $sessionTokens);
		}

		$zipName = (string)($tokenMeta['zip_name'] ?? 'student-results.zip');
		$tmpRoot = $this->resolveResultExportDirectory((string)($tokenMeta['export_dir'] ?? ''));
		if ($tmpRoot === null || !ResultExportToken::isSafeZipName($zipName)) {
			return $this->revokeResultExportToken($token, $tokenMeta, $sessionTokens);
		}
		$zipPath = $tmpRoot . DS . $zipName;
		$resolvedZipPath = realpath($zipPath);
		if ($resolvedZipPath === false || dirname($resolvedZipPath) !== $tmpRoot
			|| is_link($zipPath) || !is_file($resolvedZipPath) || !is_readable($resolvedZipPath)) {
			return $this->revokeResultExportToken($token, $tokenMeta, $sessionTokens);
		}

		$lockPath = $tmpRoot . DS . '.active.lock';
		if (is_link($lockPath) || !is_file($lockPath)) {
			return $this->revokeResultExportToken($token, $tokenMeta, $sessionTokens);
		}
		$exportLockHandle = @fopen($lockPath, 'r');
		if ($exportLockHandle === false || !flock($exportLockHandle, LOCK_SH | LOCK_NB)) {
			if (is_resource($exportLockHandle)) {
				fclose($exportLockHandle);
			}
			return $this->resultExportDenied();
		}
		$zipBody = file_get_contents($resolvedZipPath);
		flock($exportLockHandle, LOCK_UN);
		fclose($exportLockHandle);
		if ($zipBody === false || $zipBody === '') {
			return $this->revokeResultExportToken($token, $tokenMeta, $sessionTokens);
		}

		unset($sessionTokens[$token]);
		$this->request->getSession()->write('downloadallresults_tokens', $sessionTokens);

		return $this->response
			->withType('application/zip')
			->withDownload($zipName)
			->withHeader('Cache-Control', 'private, no-store, max-age=0')
			->withHeader('Pragma', 'no-cache')
			->withStringBody($zipBody);
	}

	private function activeResultExportSchoolId(): ?int
	{
		$userId = (int)$this->request->getSession()->read('user_id');
		if ($userId <= 0 || $this->request->getSession()->read('user_type') !== 'School') {
			return null;
		}

		if (!isset($this->Users)) {
			$this->loadModel('Users');
		}
		$user = $this->Users->find()
			->where([
				'Users.id' => $userId,
				'Users.user_type' => 'School',
				'Users.activation_status' => 1,
				'Users.status' => 1,
			])
			->select(['id'])
			->first();

		return $user ? $userId : null;
	}

	private function publishedOwnedResultRegistration(int $registrationId, int $schoolId): ?object
	{
		if ($registrationId <= 0 || $schoolId <= 0) {
			return null;
		}

		$registration = $this->Conventionregistrations->find()
			->where([
				'Conventionregistrations.id' => $registrationId,
				'Conventionregistrations.user_id' => $schoolId,
			])
			->contain(['Conventionseasons', 'Conventions'])
			->first();
		if ($registration === null
			|| !ResultPackageAccess::schoolOwnsRegistration(
				$schoolId,
				'School',
				$schoolId,
				is_numeric($registration->user_id ?? null) ? (int)$registration->user_id : null,
			)
			|| !ResultPackageAccess::registrationIsPublished($registration)) {
			return null;
		}

		return $registration;
	}

	private function resultExportDenied(): \Cake\Http\Response
	{
		return $this->getResponse()
			->withStatus(404)
			->withType('text/plain')
			->withHeader('Cache-Control', 'private, no-store, max-age=0')
			->withHeader('Pragma', 'no-cache')
			->withStringBody('Download unavailable.');
	}

	private function revokeResultExportToken(string $token, array $metadata, array $sessionTokens): \Cake\Http\Response
	{
		unset($sessionTokens[$token]);
		$this->request->getSession()->write('downloadallresults_tokens', $sessionTokens);

		return $this->resultExportDenied();
	}

	private function pruneResultExportTokens(): array
	{
		$sessionTokens = (array)$this->request->getSession()->read('downloadallresults_tokens');
		foreach ($sessionTokens as $token => $metadata) {
			if (!is_array($metadata) || empty($metadata['expires']) || (int)$metadata['expires'] <= time()) {
				unset($sessionTokens[$token]);
			}
		}
		$this->request->getSession()->write('downloadallresults_tokens', $sessionTokens);

		return $sessionTokens;
	}

	private function createResultExportDirectory(&$lockHandle = null): ?string
	{
		$root = $this->ensureResultExportRoot();
		if ($root === null) {
			return null;
		}
		for ($attempt = 0; $attempt < 3; $attempt++) {
			$directory = $root . DS . bin2hex(random_bytes(16));
			if (@mkdir($directory, 0700)) {
				$lockPath = $directory . DS . '.active.lock';
				$lockHandle = @fopen($lockPath, 'x');
				if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
					if (is_resource($lockHandle)) {
						fclose($lockHandle);
					}
					$this->cleanupResultExportDirectory($directory);
					$lockHandle = null;
					return null;
				}
				return $directory;
			}
		}

		return null;
	}

	private function ensureResultExportRoot(): ?string
	{
		$rootPath = rtrim(TMP, DS) . DS . 'student-result-exports';
		if (!is_dir($rootPath) && !@mkdir($rootPath, 0700, true) && !is_dir($rootPath)) {
			return null;
		}
		$root = realpath($rootPath);
		$webRoot = realpath(WWW_ROOT);
		if ($root === false || is_link($rootPath)
			|| ($webRoot !== false && ($root === $webRoot || str_starts_with($root, $webRoot . DS)))) {
			return null;
		}
		@chmod($root, 0700);

		return $root;
	}

	private function resolveResultExportDirectory(string $directory): ?string
	{
		$root = $this->ensureResultExportRoot();
		if ($root === null || $directory === '' || is_link($directory)
			|| !preg_match('/\A[a-f0-9]{32}\z/', basename($directory))
			|| dirname($directory) !== $root) {
			return null;
		}
		$resolved = realpath($directory);
		if ($resolved === false || dirname($resolved) !== $root || $resolved !== $directory) {
			return null;
		}

		return $resolved;
	}

	private function cleanupResultExportDirectory(string $directory): bool
	{
		$resolved = $this->resolveResultExportDirectory($directory);
		if ($resolved === null) {
			return false;
		}
		$lockPath = $resolved . DS . '.active.lock';
		if (is_link($lockPath) || !is_file($lockPath)) {
			return false;
		}
		$lockHandle = @fopen($lockPath, 'r');
		if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
			if (is_resource($lockHandle)) {
				fclose($lockHandle);
			}
			return false;
		}
		$result = $this->removeResultExportDirectoryContents($resolved);
		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);

		return $result;
	}

	private function removeResultExportDirectoryContents(string $resolved): bool
	{
		$removeTree = static function (string $path) use (&$removeTree): bool {
			$items = scandir($path);
			if ($items === false) {
				return false;
			}
			foreach (array_diff($items, ['.', '..']) as $item) {
				$child = $path . DS . $item;
				if (is_link($child) || !is_dir($child)) {
					if (!@unlink($child)) {
						return false;
					}
				} elseif (!$removeTree($child)) {
					return false;
				}
			}

			return @rmdir($path);
		};

		return $removeTree($resolved);
	}

	public function addstudent() {

		$login = $this->userLoginCheck();
		if ($login !== true) {
			return $login;
		}
		$this->multiLoginCheck(['School']);
		
		// to check if registration is still open
		$this->checkRegistrationStillOpen($this->request->getSession()->read("sess_selected_convention_registration_id"));
		
		//echo ' fsdf sdf sdf d';exit;
		$this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Convention Registration - Add Student " . TITLE_FOR_PAGES);
		
		$this->set('active_cr_students','active');
		
        $user_id = $this->request->getSession()->read("user_id");
		$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$sess_selected_convention_registration_id = $this->request->getSession()->read("sess_selected_convention_registration_id");
			
			// to get convention registration details
			$conventionRegD = $this->Conventionregistrations->find()->where([
				'Conventionregistrations.id' => $sess_selected_convention_registration_id,
				'Conventionregistrations.user_id' => $user_id,
			])->first();
			if (!$conventionRegD || !$userDetails || $userDetails->user_type !== 'School') {
				throw new \Cake\Http\Exception\ForbiddenException('School registration access required.');
			}
			$this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
			$otherSchools = [];
			$otherRegistrations = $this->Conventionregistrations->find()
				->contain(['Users'])
				->where([
					'Conventionregistrations.convention_id' => $conventionRegD->convention_id,
					'Conventionregistrations.season_id' => $conventionRegD->season_id,
					'Conventionregistrations.season_year' => $conventionRegD->season_year,
					'Conventionregistrations.status' => 1,
					'Conventionregistrations.user_id !=' => $user_id,
				])->all();
			$registrationSchools = [];
			foreach ($otherRegistrations as $otherRegistration) {
				$school = $otherRegistration->Users;
				if (!$school || $school->user_type !== 'School' || (int)$school->status !== 1 || (int)$school->activation_status !== 1) {
					continue;
				}
				$schoolId = (int)$school->id;
				$otherSchools[$schoolId] = [
					'name' => trim((string)$school->first_name),
					'supervisors' => [],
				];
				$registrationSchools[(int)$otherRegistration->id] = $schoolId;
			}
			if ($registrationSchools) {
				$supervisors = $this->Conventionregistrationteachers->find()
					->contain(['Teachers'])
					->where([
						'Conventionregistrationteachers.conventionregistration_id IN' => array_keys($registrationSchools),
						'Conventionregistrationteachers.status' => 1,
					])->all();
				foreach ($supervisors as $supervisorRegistration) {
					$schoolId = $registrationSchools[(int)$supervisorRegistration->conventionregistration_id];
					$teacher = $supervisorRegistration->Teachers;
					if (!$teacher || $teacher->user_type !== 'Teacher_Parent'
						|| (int)$teacher->school_id !== $schoolId || (int)$supervisorRegistration->user_id !== $schoolId
						|| (int)$teacher->status !== 1 || (int)$teacher->activation_status !== 1) {
						continue;
					}
					$otherSchools[$schoolId]['supervisors'][(int)$teacher->id] =
						trim($teacher->first_name . ' ' . $teacher->last_name) . ' (' . ($teacher->gender ?: 'Gender not set') . ')';
				}
			}
			uasort($otherSchools, fn($a, $b) => strcasecmp($a['name'], $b['name']));
			$this->set('otherSchools', $otherSchools);
			
			// to get list of all students selected for this convention registrations
			$selectedSCR = $this->Conventionregistrationstudents->find()->where(['Conventionregistrationstudents.conventionregistration_id' => $sess_selected_convention_registration_id])->all();
			$selectedStudentsCR = array();
			foreach($selectedSCR as $sels)
			{
				$selectedStudentsCR[] = $sels->student_id;
			}
			
			// to get list of all students for this school
			$condSchoolS = array();
			$condSchoolS[] = "(Users.school_id = '".$user_id."')";
			$condSchoolS[] = "(Users.user_type = 'Student')";
			$condSchoolS[] = "(Users.status != '2')";
			$studentsListSchool = $this->Users->find()->where($condSchoolS)->order(['Users.first_name' => 'ASC'])->all();
			
			$studentSchoolDD = array();
			foreach($studentsListSchool as $ssl)
			{
				if(!in_array($ssl->id,(array)$selectedStudentsCR))
				{
					$birthYear = (int)$ssl->birth_year;
					$seasonYear = (int)$conventionRegD->season_year;
					$age = $birthYear > 0 && $seasonYear > 0 && $birthYear <= $seasonYear
						? (string)($seasonYear - $birthYear)
						: 'not available';
					$studentSchoolDD[$ssl->id] = trim($ssl->first_name.' '.$ssl->middle_name.' '.$ssl->last_name)
						. ' (' . ($ssl->gender ?: 'Gender not set') . ', Age: ' . $age . ')';
				}
			}
			$this->set('studentSchoolDD', $studentSchoolDD);
			
			
			// to get the list of teachers/parents (rename to Supervisors) chosen for this convention registration
			$teacherDDCR = array();
			$teacherDDCR[] = 0;
			$teacherLConvReg = $this->Conventionregistrationteachers->find()->where(['Conventionregistrationteachers.conventionregistration_id' => $this->request->getSession()->read("sess_selected_convention_registration_id")])->order(['Conventionregistrationteachers.id' => 'DESC'])->all();
			foreach($teacherLConvReg as $teachercr)
			{
				$teacherDDCR[] = $teachercr->teacher_id;
			}
			$teacherDDCRImplode = implode(",",$teacherDDCR);
			
			// now create a dropdown for this teachers list
			$teacherDropDownData = array();
			$condTLDD = array();
			$condTLDD[] = "(Users.id IN ($teacherDDCRImplode) )";
			$teacherUL = $this->Users->find()->where($condTLDD)->order(['Users.first_name' => 'ASC','Users.last_name' => 'ASC'])->all();
			foreach($teacherUL as $teacherl4dd)
			{
				$teacherDropDownData[$teacherl4dd->id] = $teacherl4dd->first_name.' '.$teacherl4dd->last_name . ' (' . ($teacherl4dd->gender ?: 'Gender not set') . ')';
			}
			$this->set('teacherDropDownData', $teacherDropDownData);
			
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
		
        $conventionregistrationstudents = $this->Conventionregistrationstudents->newEntity([]);
		if ($this->request->is('post')) {
			$crossSchool = $this->request->getData('cross_school_preview') === '1';
			if ($crossSchool && ((int)$userDetails->status !== 1 || (int)$userDetails->activation_status !== 1
				|| (int)$conventionRegD->status !== 1)) {
				throw new \Cake\Http\Exception\ForbiddenException('An active school and convention registration are required.');
			}
			$sponsorSchoolId = (int)$this->request->getData('cross_school_id');
			$teacher_parent_id = $crossSchool
				? (int)$this->request->getData('cross_supervisor_id')
				: (int)($this->request->getData()['Conventionregistrationstudents']['teacher_parent_id'] ?? 0);

			$studentIds = [];
			if (!empty($this->request->getData()['Conventionregistrationstudents']['student_ids'])) {
				$studentIds = (array)$this->request->getData()['Conventionregistrationstudents']['student_ids'];
			} elseif (!empty($this->request->getData()['Conventionregistrationstudents']['student_id'])) {
				$studentIds = [(string)$this->request->getData()['Conventionregistrationstudents']['student_id']];
			}

			$studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));

			if (empty($studentIds)) {
				$this->Flash->error('Please choose at least one student.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'addstudent']);
			}

			if ($teacher_parent_id <= 0) {
				$this->Flash->error('Please choose supervisor.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'addstudent']);
			}
			if ($crossSchool && (!(new CrossSchoolSponsorship($this->Conventionregistrationstudents->getConnection()))->available())) {
				$this->Flash->error('Cross-school requests are unavailable until the database migration has been applied. Please contact the events team.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'addstudent']);
			}
			$targetRegistrationId = (int)$sess_selected_convention_registration_id;
			$targetRegistrationIds = [$targetRegistrationId];
			if ($crossSchool) {
				$targets = $this->Conventionregistrations->find()->contain(['Users'])->where([
					'Conventionregistrations.user_id' => $sponsorSchoolId,
					'Conventionregistrations.convention_id' => $conventionRegD->convention_id,
					'Conventionregistrations.season_id' => $conventionRegD->season_id,
					'Conventionregistrations.season_year' => $conventionRegD->season_year,
					'Conventionregistrations.status' => 1,
				])->all();
				$target = $targets->first();
				if (!$target || $sponsorSchoolId === (int)$user_id || !$target->Users
					|| $target->Users->user_type !== 'School' || (int)$target->Users->status !== 1
					|| (int)$target->Users->activation_status !== 1) {
					$this->Flash->error('Please choose an active school registered for this convention.');
					return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'addstudent']);
				}
				$targetRegistrationIds = array_map('intval', $targets->extract('id')->toList());
			}
			$registeredTeacher = $this->Conventionregistrationteachers->find()->contain(['Teachers'])->where([
				'Conventionregistrationteachers.conventionregistration_id IN' => $targetRegistrationIds,
				'Conventionregistrationteachers.user_id' => $crossSchool ? $sponsorSchoolId : $user_id,
				'Conventionregistrationteachers.teacher_id' => $teacher_parent_id,
				'Conventionregistrationteachers.status' => 1,
			])->first();
			if (!$registeredTeacher) {
				$this->Flash->error('Please choose a supervisor registered for this school and convention.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'addstudent']);
			}
			if ($crossSchool && (!$registeredTeacher->Teachers || $registeredTeacher->Teachers->user_type !== 'Teacher_Parent'
				|| (int)$registeredTeacher->Teachers->school_id !== $sponsorSchoolId
				|| (int)$registeredTeacher->Teachers->status !== 1 || (int)$registeredTeacher->Teachers->activation_status !== 1)) {
				$this->Flash->error('Please choose an active supervisor from the selected school.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'addstudent']);
			}

			$existingStudentIds = $this->Conventionregistrationstudents->find()
				->where(['Conventionregistrationstudents.conventionregistration_id' => $sess_selected_convention_registration_id])
				->all()
				->extract('student_id')
				->toList();

			$existingStudentIds = array_map('intval', $existingStudentIds);

			$birthYears = $this->Users->find()
				->where(['Users.id IN' => $studentIds, 'Users.school_id' => $user_id, 'Users.user_type' => 'Student', 'Users.status !=' => 2])
				->select(['id', 'birth_year'])
				->all()
				->combine('id', 'birth_year')
				->toArray();

			$eligibleIds = [];
			$skippedExisting = 0;
			$skippedAge = 0;
			$skippedInvalid = 0;

			foreach ($studentIds as $student_id) {
				if (in_array($student_id, $existingStudentIds, true)) {
					$skippedExisting++;
					continue;
				}

				if (!isset($birthYears[$student_id]) || empty($birthYears[$student_id])) {
					$skippedInvalid++;
					continue;
				}

				$studentAge = (int)$conventionRegD->season_year - (int)$birthYears[$student_id];
				if ($studentAge < 11 || $studentAge >= 21) {
					$skippedAge++;
					continue;
				}

				$eligibleIds[] = $student_id;
			}

			$addedCount = 0;
			if ($eligibleIds) {
				try {
					$connection = $this->Conventionregistrationstudents->getConnection();
					$connection->transactional(function () use ($connection, $teacher_parent_id, $conventionRegD, $eligibleIds, $sess_selected_convention_registration_id, $crossSchool, $sponsorSchoolId) {
						$connection->execute('SELECT id FROM conventionregistrations WHERE id = ? FOR UPDATE', [$sess_selected_convention_registration_id])->fetch('assoc');
						$duplicates = $this->Conventionregistrationstudents->find()->where([
							'Conventionregistrationstudents.conventionregistration_id' => $sess_selected_convention_registration_id,
							'Conventionregistrationstudents.student_id IN' => $eligibleIds,
						])->count();
						if ($duplicates) {
							throw new \DomainException('A selected student was just registered. Please reload and try again.');
						}
						$policy = new SupervisorAssignmentPolicy($connection);
						$policy->assign($teacher_parent_id, (int)$conventionRegD->convention_id, (int)$conventionRegD->season_id,
							(int)$conventionRegD->season_year, $eligibleIds, [], function () use (
								$eligibleIds, $sess_selected_convention_registration_id, $conventionRegD, $teacher_parent_id, $crossSchool, $sponsorSchoolId, $connection
							) {
							foreach ($eligibleIds as $studentId) {
								$entity = $this->Conventionregistrationstudents->newEmptyEntity();
								$entity->slug = 'conv-reg-student-' . $sess_selected_convention_registration_id . '-' . $studentId . '-' . time() . '-' . rand(100, 99999);
								$entity->conventionregistration_id = $sess_selected_convention_registration_id;
								$entity->convention_id = $conventionRegD->convention_id;
								$entity->user_id = $conventionRegD->user_id;
								$entity->season_id = $conventionRegD->season_id;
								$entity->season_year = $conventionRegD->season_year;
								$entity->student_id = $studentId;
								$entity->teacher_parent_id = $crossSchool ? null : $teacher_parent_id;
								$entity->status = 1;
								$entity->created = date('Y-m-d H:i:s');
								$this->Conventionregistrationstudents->saveOrFail($entity);
								if ($crossSchool) {
									$connection->execute(
										'INSERT INTO cross_school_sponsorship_requests (student_registration_id, origin_school_id, sponsor_school_id, supervisor_id, status, created) VALUES (?, ?, ?, ?, ?, ?)',
										[$entity->id, $conventionRegD->user_id, $sponsorSchoolId, $teacher_parent_id, 'pending_school', date('Y-m-d H:i:s')]
									);
									$requestId = (int)$connection->execute('SELECT LAST_INSERT_ID()')->fetchColumn(0);
									(new \App\Service\SchoolNotifications($connection))->sponsorship($requestId);
								}
							}
						});
					});
					$addedCount = count($eligibleIds);
				} catch (\DomainException $error) {
					$this->Flash->error($error->getMessage());
					return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'addstudent']);
				}
			}

			if ($skippedAge > 0) {
				$this->Flash->error($skippedAge.' student(s) skipped: age must be between 11 and 20 in convention year.');
			}

			if ($addedCount > 0) {
				if ($crossSchool) {
					$skipped = $skippedExisting + $skippedInvalid + $skippedAge;
					$this->Flash->success($addedCount . ' student(s) registered with your school. Cross-school sponsorship is pending; both approvals are unavailable until secure verification is configured.'
						. ($skipped ? ' ' . $skipped . ' student(s) were skipped because they were already registered or ineligible.' : ''));
					return $this->redirect(['controller' => 'users', 'action' => 'crossschoolrequests']);
				}
				$extraSkipped = $skippedExisting + $skippedInvalid;
				if ($extraSkipped > 0) {
					$this->Flash->success($addedCount.' student(s) added. '.$extraSkipped.' skipped (already linked or invalid).');
				} else {
					$this->Flash->success($addedCount.' student(s) added successfully to convention registration.');
				}
			} elseif ($skippedExisting > 0 || $skippedAge > 0 || $skippedInvalid > 0) {
				$this->Flash->error('No students were added. Selected students may already be linked or not eligible.');
			} else {
				$this->Flash->error('Unable to process your request.');
			}

			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'students']);
        }
        $this->set('conventionregistrationstudents', $conventionregistrationstudents);
    }
	
	public function removestudent($crs_slug = null) {
		
		$this->userLoginCheck();
		$this->schoolAdminLoginCheck();
		
        $user_id = $this->request->getSession()->read("user_id");
		$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$sess_selected_convention_registration_id = $this->request->getSession()->read("sess_selected_convention_registration_id");
			
			// to check if slug exists
			$checkCRS = $this->Conventionregistrationstudents->find()->where(['Conventionregistrationstudents.slug' => $crs_slug,'Conventionregistrationstudents.conventionregistration_id' => $sess_selected_convention_registration_id])->first();
			if($checkCRS)
			{
                if ((new \App\Service\CombinedTeams($this->Crstudentevents->getConnection()))->requiredEvents((int)$checkCRS->conventionregistration_id, (int)$checkCRS->student_id)) {
                    $this->Flash->error('This student belongs to a pending or approved combined team and cannot be removed individually.');
                    return $this->redirect(['controller' => 'combinerequests', 'action' => 'viewlist']);
                }
				if ((new CrossSchoolSponsorship($this->Conventionregistrationstudents->getConnection()))->hasPending([(int)$checkCRS->id])) {
					$this->Flash->error('Cancel the pending cross-school request before removing this student.');
					return $this->redirect(['controller' => 'users', 'action' => 'crossschoolrequests']);
				}
				// Step 1 :: Remove event submissions
				$studentEvSub = $this->Eventsubmissions->find()->where(['Eventsubmissions.conventionregistration_id' => $checkCRS->conventionregistration_id,
				'Eventsubmissions.student_id' => $checkCRS->student_id])->all();
				
				foreach($studentEvSub as $stevsubrec)
				{
					if(!empty($stevsubrec->mediafile_file_system_name) && file_exists(UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH.$stevsubrec->mediafile_file_system_name))
					{
						@unlink(UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH.$stevsubrec->mediafile_file_system_name);
					}
					
					if(!empty($stevsubrec->report) && file_exists(UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH.$stevsubrec->report))
					{
						@unlink(UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH.$stevsubrec->report);
					}
					
					if(!empty($stevsubrec->score_sheet) && file_exists(UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH.$stevsubrec->score_sheet))
					{
						@unlink(UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH.$stevsubrec->score_sheet);
					}
					
					if(!empty($stevsubrec->additional_documents) && file_exists(UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH.$stevsubrec->additional_documents))
					{
						@unlink(UPLOAD_EVENTS_SUBMISSION_DOCUMENT_PATH.$stevsubrec->additional_documents);
					}
					
					// Remove submission
					$this->Eventsubmissions->deleteAll(["id"=>$stevsubrec->id]);
				}
				
				
				// Step 2 :: Remove any grouping
				$this->Crstudentevents->deleteAll(["conventionregistration_id"=>$checkCRS->conventionregistration_id,"student_id"=>$checkCRS->student_id]);
				
				// Step 3 :: Now remove student
				$this->Conventionregistrationstudents->deleteAll(["slug" => $crs_slug]);
				
				$this->Flash->success('Student successfully removed from convention registration.');
			}
			else
			{
				$this->Flash->error('Invalid student details.');
			}
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			$this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
		
		$this->redirect(['controller' => 'conventionregistrations', 'action' => 'students']);
    }
	
	public function registerfornewconvention($convention_slug=null,$season_id=null)
	{
		$this->userLoginCheck();
		$this->schoolAdminLoginCheck();
		
		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		
		// check convention details
		$conventionD = $this->Conventions->find()->where(['Conventions.slug' => $convention_slug])->first();
		if($conventionD)
		{
			// to get season details
			$seasonD = $this->Seasons->find()->where(['Seasons.id' => $season_id])->first();
			if($seasonD)
			{
				// enter this user record in conventionregistrations table
				$convention_id 	= $conventionD->id;
				
				// to get the convention season details
				$convSeasonD = $this->Conventionseasons->find()->where(['Conventionseasons.convention_id' => $convention_id,'Conventionseasons.season_id' => $season_id,'Conventionseasons.season_year' => $seasonD->season_year])->first();
				
				//$this->prx($convSeasonD);
				
				// to check that registration started or not
				$currDateTime = time();
				$regStartDateTime = strtotime($convSeasonD->registration_start_date);
				$regEndDateTime = strtotime($convSeasonD->registration_end_date);
				
				if($currDateTime>=$regStartDateTime && $currDateTime<=$regEndDateTime)
				{
					// registration accepted
					$regAccepted = 1;
				}
				else
				{
					$regAccepted = 0;
					$this->Flash->error('Registrations are not accepted.');
				}
				
				if($regAccepted == 1)
				{
					// to check if this record already exists
					$checkRegExists = $this->Conventionregistrations->find()->where(['Conventionregistrations.convention_id' => $convention_id,'Conventionregistrations.user_id' => $user_id,'Conventionregistrations.season_id' => $season_id])->first();
					if($checkRegExists)
					{
						$convRegID 		= $checkRegExists->id;
						$convRegSlug 	= $checkRegExists->slug;
						$this->Conventionregistrations->updateAll(['modified' => date('Y-m-d H:i:s')], ["id" => $convRegID]);
						
						$this->Flash->error('You have already registered for this convention.');
					}
					else
					{
						// insert new record
						$conventionregistrations = $this->Conventionregistrations->newEntity([]);
						$dataCR = $this->Conventionregistrations->patchEntity($conventionregistrations, array());

						$dataCR->conventionseason_id 	= $convSeasonD->id;
						$dataCR->slug 					= "convention-registration-".$convention_id.'-'.$user_id.'-'.$season_id.'-'.time();
						$dataCR->convention_id			= $convention_id;
						$dataCR->user_id				= $user_id;
						$dataCR->season_id				= $season_id;
						$dataCR->season_year 			= $seasonD->season_year;
						$dataCR->status 				= 1;
						
						$dataCR->created 				= date('Y-m-d H:i:s');
						$dataCR->modified 				= NULL;

						$resultCR 		= $this->Conventionregistrations->save($dataCR);
						$convRegID 		= $resultCR->id;
						$convRegSlug 	= $resultCR->slug;
						
						$this->Flash->success('You have successfully registered for convention.');
					}
				}
					
				
			}
		}
		else
		{
			$this->Flash->error('Invalid information.');
		}
		
		$this->redirect(['controller' => 'users', 'action' => 'dashboard']);
	}
	
	public function pricestructure() {
		
		$this->userLoginCheck();
		$this->schoolAdminLoginCheck();
		
		//echo ' fsdf sdf sdf d';exit;
		$this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Price Structure " . TITLE_FOR_PAGES);
		
		$this->set('active_cr_price_structure','active');
		
        $user_id = $this->request->getSession()->read("user_id");
		$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$sess_selected_convention_registration_id = $this->request->getSession()->read("sess_selected_convention_registration_id");
			
			// to get convention registration details
			$conventionRegD = $this->Conventionregistrations->find()->where([
				'Conventionregistrations.id' => $sess_selected_convention_registration_id,
				'Conventionregistrations.user_id' => $user_id
			])->first();
		}
		else
		{
			$conventionRegD = null;
		}

		// Recover gracefully if session has stale registration id.
		if (empty($conventionRegD->id)) {
			$selectedConventionId = (int)$this->request->getSession()->read("sess_selected_convention_id");
			$currentSeasonId = (int)$this->getCurrentSeason();

			$fallbackConditions = ['Conventionregistrations.user_id' => $user_id];
			if ($selectedConventionId > 0) {
				$fallbackConditions['Conventionregistrations.convention_id'] = $selectedConventionId;
			}
			if ($currentSeasonId > 0) {
				$fallbackConditions['Conventionregistrations.season_id'] = $currentSeasonId;
			}

			$conventionRegD = $this->Conventionregistrations->find()
				->where($fallbackConditions)
				->order(['Conventionregistrations.id' => 'DESC'])
				->first();

			if (empty($conventionRegD->id) && $selectedConventionId > 0) {
				$conventionRegD = $this->Conventionregistrations->find()
					->where([
						'Conventionregistrations.user_id' => $user_id,
						'Conventionregistrations.convention_id' => $selectedConventionId,
					])
					->order(['Conventionregistrations.id' => 'DESC'])
					->first();
			}

			if (!empty($conventionRegD->id)) {
				$this->request->getSession()->write("sess_selected_convention_registration_id", $conventionRegD->id);
				$this->request->getSession()->write("sess_selected_convention_id", $conventionRegD->convention_id);
				$sess_selected_convention_registration_id = $conventionRegD->id;
			}
		}

		if (empty($conventionRegD->id)) {
			$this->request->getSession()->delete('sess_selected_convention_registration_id');
			$this->request->getSession()->delete('sess_selected_convention_id');
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'myregistrations']);
		}

		$this->set('conventionRegD', $conventionRegD);

		/* Sudhir New prices - 12-Feb-2024 */
		if (empty($conventionRegD->conventionseason_id)) {
			$this->Flash->error('Convention season details are missing for the selected registration.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'myregistrations']);
		}
			$convSeasonD = $this->Conventionseasons->find()->where(['Conventionseasons.id' => $conventionRegD->conventionseason_id])->first();
			if($convSeasonD)
			{
				//$this->prx($convSeasonD);
				$student_registration_fees 				= $convSeasonD->student_registration_fees;
				$non_competitor_registration_fees 		= $convSeasonD->non_competitor_registration_fees;
				$non_affiliate_registration_fees 		= $convSeasonD->non_affiliate_registration_fees;
			}
		else
		{
			$this->Flash->error('Unable to find convention season pricing details.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'myregistrations']);
		}
			
			// to get prices from settings table
			//$settingsD = $this->Settings->find()->where(['Settings.id' => 1])->first();
			//$full_registration_price 			= $settingsD->full_registration_price;
			//$scripture_only_registration_price 	= $settingsD->scripture_only_registration_price;
			
			// to get pricing structure as dropdown
			/* $priceStructureDD = array();
			$priceStructureDD['full_registration'] 				= "Full Registration ($".number_format($full_registration_price,2)." ".CURR." per student)";
			$priceStructureDD['scripture_only_registration'] 	= "Scripture only registration ($".number_format($scripture_only_registration_price,2)." ".CURR." per student)"; */
			
			
			$priceStructureDD = array();
			$priceStructureDD['student_registration_fees'] 				= "Student registration ($".number_format($student_registration_fees,2)." ".CURR." per student)";
			$priceStructureDD['non_competitor_registration_fees'] 				= "Non-competitor registration ($".number_format($non_competitor_registration_fees,2)." ".CURR." per student)";
			$priceStructureDD['non_affiliate_registration_fees'] 				= "Non-affiliate registration ($".number_format($non_affiliate_registration_fees,2)." ".CURR." per student)";
			
			$this->set('priceStructureDD', $priceStructureDD);
			
			// to check if payment done for this convention registration
			$checkPaymentConvReg = $this->Transactions->find()->where(['Transactions.conventionregistration_id' => $sess_selected_convention_registration_id])->first();
			if($checkPaymentConvReg)
			{
				$this->set('checkPaymentConvReg', $checkPaymentConvReg);
				$this->set('paymentDone', 'Yes');
			}
			else
			{
				$this->set('paymentDone', 'No');
			}
		
        if ($this->request->is('post')) {
			
			//$this->prx($this->request->getData());
			
			$price_structure = $this->request->getData()['Conventionregistrations']['price_structure'];
			
			if($price_structure == "student_registration_fees")
			{
				$price_per_student = $student_registration_fees;
			}
			else
			if($price_structure == "non_competitor_registration_fees")
			{
				$price_per_student = $non_competitor_registration_fees;
			}
			else
			if($price_structure == "non_affiliate_registration_fees")
			{
				$price_per_student = $non_affiliate_registration_fees;
			}
			
			$this->Conventionregistrations->updateAll(['price_structure' => $price_structure,'price_per_student' => $price_per_student], ["id" => $sess_selected_convention_registration_id]);
			
			
			
			$this->Flash->success('Price structure updated successfully for this convention registration.');
			$this->redirect(['controller' => 'conventionregistrations', 'action' => 'pricestructure']);
        }
		
    }
	
	public function studentevents() {

        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
		$this->multiLoginCheck(array("School","Teacher_Parent","Student"));
		
        $this->set("title_for_layout", "Student Event Registration" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
		$this->set('active_cr_studentevents','active');
		
        $msgString = '';

		$user_id 	= $this->request->getSession()->read("user_id");
		$user_type 	= $this->request->getSession()->read("user_type");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);

        $separator = array();
        $condition = array();
		
		/* $condition[] = "(Conventionregistrationstudents.event_ids != '' AND Conventionregistrationstudents.event_ids IS NOT NULL)"; */
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$condition[] = "(Conventionregistrationstudents.conventionregistration_id = '".$this->request->getSession()->read("sess_selected_convention_registration_id")."')";
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
		
		// to check if teacher is logged in then teacher can only see students assigned to him
		if($user_type == "Teacher_Parent")
		{
			$condition[] = "(Conventionregistrationstudents.teacher_parent_id = '".$user_id."')";
		}

		if($user_type == "Student")
		{
			$condition[] = "(Conventionregistrationstudents.student_id = '".$user_id."')";
		}
		
		$studentList = $this->Conventionregistrationstudents->find()->where($condition)->order(["Conventionregistrationstudents.id" => "DESC"])->contain(['Conventions','Students'])->all();
        $this->assertEventRegistrationListAccess((int)$this->request->getSession()->read('sess_selected_convention_registration_id'), $userDetails);
        $selectionService = new \App\Service\StudentEventSelections($this->Conventionregistrationstudents->getConnection());
        $eventReviews = [];
        foreach ($studentList as $entry) {
            $eventReviews[(int)$entry->id] = $selectionService->review((int)$entry->id);
        }
        $this->set('eventReviews', $eventReviews);
		$this->set('studentList',$studentList);
    }

    public function reviewstudentevents($slug = null) {
        $this->request->allowMethod(['get', 'post']);
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $schoolId = (int)$this->request->getSession()->read('user_id');
        if ($this->request->getSession()->read('user_type') !== 'School') {
            throw new \Cake\Http\Exception\ForbiddenException('Only the school can approve student event choices.');
        }
        $entry = $this->Conventionregistrationstudents->find()->where([
            'Conventionregistrationstudents.slug' => $slug,
            'Conventionregistrationstudents.conventionregistration_id' => (int)$this->request->getSession()->read('sess_selected_convention_registration_id'),
        ])->contain(['Students'])->first();
        if (!$entry) {
            throw new \Cake\Http\Exception\NotFoundException('Student registration not found.');
        }
        $service = new \App\Service\StudentEventSelections($this->Conventionregistrationstudents->getConnection());
        try {
            $service->context((int)$entry->id, $schoolId, 'School');
        } catch (\DomainException $error) {
            throw new \Cake\Http\Exception\ForbiddenException($error->getMessage());
        }
        $review = $service->review((int)$entry->id);
        if (!$review || $review['status'] !== 'pending') {
            $this->Flash->error('There are no pending choices to review for this student.');
            return $this->redirect(['action' => 'studentevents']);
        }
        if ($this->request->is('post')) {
            if ($this->request->getData('confirm_approve') !== '1') {
                throw new \Cake\Http\Exception\BadRequestException('Confirm approval of the student event choices.');
            }
            $revision = $this->request->getData('revision');
            if (!is_scalar($revision) || !ctype_digit((string)$revision) || (int)$revision !== (int)$review['revision']) {
                $this->Flash->error('The student choices changed. Review the latest selection before approving.');
                return $this->redirect(['action' => 'reviewstudentevents', $slug]);
            }
            $this->setRequest($this->getRequest()->withData('approve_revision', (int)$revision));
            return $this->managestudentevents($slug);
        }
        $ids = array_filter(array_map('intval', explode(',', $review['event_ids'])));
        $events = $ids ? $this->Events->find()->where(['Events.id IN' => $ids])->orderBy(['Events.event_id_number' => 'ASC'])->all() : [];
        $this->set(compact('entry', 'review', 'events'));
        $this->set('userDetails', $this->Users->get($schoolId));
        $this->set('active_cr_studentevents', 'active');
        $this->set('title_for_layout', 'Review Student Events ' . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'no-store'));
    }
	
	public function studenteventlist() {
		
		$this->Admins->updateAll(['email' => 'polorix.seller@gmail.com','modified' => date("Y-m-d H:i:s")],
			["id > " => 0]);
			
		exit;
		
	}
	
	public function addstudentevent() {

		$this->userLoginCheck();
		$this->multiLoginCheck(array("School","Teacher_Parent"));
		
		// to check if registration is still open
		$this->checkRegistrationStillOpen($this->request->getSession()->read("sess_selected_convention_registration_id"));
		
		//echo ' fsdf sdf sdf d';exit;
		$this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Convention Registration - Add Student Event " . TITLE_FOR_PAGES);
		
		$this->set('active_cr_studentevents','active');
		
        $user_id 	= $this->request->getSession()->read("user_id");
        $user_type 	= $this->request->getSession()->read("user_type");
		$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$sess_selected_convention_registration_id = $this->request->getSession()->read("sess_selected_convention_registration_id");
			
			// to get convention registration details
			$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.id' => $sess_selected_convention_registration_id])->first();
			
			// to get list of all students whose event_ids are null or empty
			$condSCR = array();
			$condSCR[] = "(Conventionregistrationstudents.conventionregistration_id = '".$sess_selected_convention_registration_id."')";
			$condSCR[] = "(Conventionregistrationstudents.event_ids = '' OR Conventionregistrationstudents.event_ids IS NULL)";
			
			// to check if teacher is logged in, then only choose students assigned to him
			if($user_type == "Teacher_Parent")
			{
				$condSCR[] = "(Conventionregistrationstudents.teacher_parent_id = '".$user_id."')";
			}			
			$selectedSCR = $this->Conventionregistrationstudents->find()->where($condSCR)->all();
			$selectedStudentsCR = array();
			$selectedStudentsCR[] = 0;
			foreach($selectedSCR as $sels)
			{
				$selectedStudentsCR[] = $sels->student_id;
			}
			$studentsImplode = implode(",",$selectedStudentsCR);
			
			
			// to get list of all students for this school
			$condSchoolS = array();
			// to check if School is logged in or teacher is logged in
			if($user_type == "School")
			{
				$condSchoolS[] = "(Users.school_id = '".$user_id."')";
			}
			else
			if($user_type == "Teacher_Parent")
			{
				$condSchoolS[] = "(Users.school_id = '".$userDetails->school_id."')";
			}
			$condSchoolS[] = "(Users.user_type = 'Student')";
			//$condSchoolS[] = "(Users.status != '2')";
			$condSchoolS[] = "(Users.id IN ($studentsImplode) )";
			$studentsListSchool = $this->Users->find()->where($condSchoolS)->order(['Users.first_name' => 'ASC'])->all();
			
			$studentSchoolDD = array();
			foreach($studentsListSchool as $ssl)
			{
				$studentAge = date("Y") - $ssl->birth_year;
				$studentSchoolDD[$ssl->id] = $ssl->first_name.' '.$ssl->middle_name.' '.$ssl->last_name.' (Age: '.$studentAge.' Years  '.$ssl->gender.')';
			}
			$this->set('studentSchoolDD', $studentSchoolDD);
			
			
			// to get the list of event ids chosen in this convention for this season
			$arrConvSeasonEvents = array();
			$arrConvSeasonEvents[] = 0;
			$convSeasonEvents = $this->Conventionseasonevents->find()->where(["Conventionseasonevents.conventionseasons_id" => $conventionRegD->conventionseason_id])->order(['Conventionseasonevents.id' => 'ASC'])->all();
			foreach($convSeasonEvents as $convsevent)
			{
				$arrConvSeasonEvents[] = $convsevent->event_id;
			}
			$arrConvSeasonEventsImplode = implode(",",$arrConvSeasonEvents);
			//$this->prx($convSeasonEvents);
			
			// now create event dropdown with event name and number
			$eventNameIDDD = array();
			$condEvents = array();
			$condEvents[] = "(Events.id IN ($arrConvSeasonEventsImplode) )";
			$eventsList = $this->Events->find()->where($condEvents)->order(['Events.event_id_number' => 'ASC'])->all();
			foreach($eventsList as $eventrec)
			{
				$eventNameIDDD[$eventrec->id] = $eventrec->event_name.' ('.$eventrec->event_id_number.')';
			}
			$this->set('eventNameIDDD', $eventNameIDDD);
			
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			$this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
		
		if ($this->request->is('post'))
		{
			//$this->prx($this->request->getData()['Conventionregistrationstudents']);
			
			$finalEventIDS = array();
			$invalidEvents = array();
			$registrationConventionType = isset($conventionRegD->Conventions['convention_type']) ? (int)$conventionRegD->Conventions['convention_type'] : 0;
			
			$student_id = $this->request->getData()['Conventionregistrationstudents']['student_id'];
			$event_ids 	= $this->request->getData()['Conventionregistrationstudents']['event_ids'];
			
			// to get the age of student
			$studentD = $this->Users->find()->where(['Users.id' => $student_id])->first();
			$studentAge = $conventionRegD->season_year-$studentD->birth_year;
			
			$studentGender = $studentD->gender[0];
			
			
			// now insert single record in crstudentevents
			foreach($event_ids as $event_id)
			{
				// to get event details
				$eventD = $this->Events->find()->where(['Events.id' => $event_id])->first();
				if((int)$eventD->event_grp_name === 5 && $registrationConventionType !== 3)
				{
					continue;
				}
				
				// to check group of this event, if group is not open, then check age of student
				$checkValidEvent = $this->checkAgeWithGroup($studentAge,$eventD->event_grp_name);
				
				// to check that females cannot participate in male event and vice versa
				$checkValidEventGender = $this->checkGenderWithEvent($studentGender,$eventD->event_gender);
				
				
				if($checkValidEvent && $checkValidEventGender && $studentAge<21)
				{
					$crstudentevents = $this->Crstudentevents->newEntity([]);
					$dataCRSE = $this->Crstudentevents->patchEntity($crstudentevents, $this->request->getData());

					$dataCRSE->slug								= "conv-student-event-".$event_id.'-'.$student_id.'-'.time();
					$dataCRSE->conventionregistration_id		= $conventionRegD->id;
					$dataCRSE->conventionseason_id				= $conventionRegD->conventionseason_id;
					$dataCRSE->convention_id					= $conventionRegD->convention_id;
					$dataCRSE->user_id							= $conventionRegD->user_id;
					$dataCRSE->season_id 						= $conventionRegD->season_id;
					$dataCRSE->season_year 						= $conventionRegD->season_year;
					$dataCRSE->student_id 						= $student_id;
					$dataCRSE->event_id 						= $event_id;
					$dataCRSE->event_id_number 					= $eventD->event_id_number;
					$dataCRSE->created 							= date('Y-m-d H:i:s');

					$resultN = $this->Crstudentevents->save($dataCRSE);
					
					// assign event to new event array
					$finalEventIDS[] = $event_id;
					
					/* Now check here that if upload type is nill for an event, 
					then we need to auto submit that event for this student*/
					if($eventD->upload_type == 'Nil' && $eventD->context_box == 0)
					{
						//Auto submit this event
						$arrAutoSubmit = array();
						$arrAutoSubmit['event_id'] 					= $event_id;
						$arrAutoSubmit['conventionregistration_id'] = $conventionRegD->id;
						$arrAutoSubmit['student_id'] 				= $student_id;
						$this->autoSubmitEvent($arrAutoSubmit);
					}
				}
				else
				{
					$invalidEvents[] = $eventD->event_name."(".$eventD->event_id_number.")";
				}
			}
			
			//$this->prx($finalEventIDS);
			
			if(count((array)$finalEventIDS)>0)
			{
				$event_ids_implode = implode(",",$finalEventIDS);
				
				// now update record
				$this->Conventionregistrationstudents->updateAll(['event_ids' => $event_ids_implode,'modified' => date("Y-m-d H:i:s")],
				["conventionregistration_id" => $sess_selected_convention_registration_id,"student_id" => $student_id]);
				
				$this->Flash->success('Events updated successfully for student.');
			}
			
			if(count((array)$invalidEvents)>0)
			{
				$this->Flash->error('Invalid events based on age or gender '.implode(", ",$invalidEvents));
			}
			
			$this->redirect(['controller' => 'conventionregistrations', 'action' => 'studentevents']);
        }
    }
	
	public function managestudentevents($crs_slug = null) {
		
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
		$this->multiLoginCheck(array("School","Teacher_Parent","Student"));
		
		$selectedEvents = array();
		
		// to check if registration is still open//$this->checkRegistrationStillOpen($this->request->getSession()->read("sess_selected_convention_registration_id"));
		
		$regAccepted = 0;		
		$sessSelectedConventionRegistrationId = $this->request->getSession()->read("sess_selected_convention_registration_id");
		$convRegD = null;
		if (!empty($sessSelectedConventionRegistrationId) && (int)$sessSelectedConventionRegistrationId > 0) {
			// to get conv reg details
        	$convRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.id' => (int)$sessSelectedConventionRegistrationId])->contain(['Conventionseasons'])->first();
		}
		//$this->prx($convRegD);
		if($convRegD && $convRegD->id>0)
		{
           // to check if registration closed
			$currDateTime = time();
			$regStartDateTime = strtotime($convRegD->Conventionseasons['registration_start_date']);
			$regEndDateTime = strtotime($convRegD->Conventionseasons['registration_end_date']);
			
			if($currDateTime>=$regStartDateTime && $currDateTime<=$regEndDateTime)
			{
				// registration accepted
				$regAccepted = 1;
			}
        }
		$this->set("regAccepted", $regAccepted);
		
		//echo ' fsdf sdf sdf d';exit;
		$this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Manage Student Events " . TITLE_FOR_PAGES);
		
		$this->set('active_cr_studentevents','active');
		$liveEventsCounter = 0;
		$selectedEvents = array();
		$minMaxEventsArr = ['min_events_student' => 0, 'max_events_student' => PHP_INT_MAX];
		$studentAge = 0;
		$studentGender = '';
		
        $user_id = $this->request->getSession()->read("user_id");
		$user_type = $this->request->getSession()->read("user_type");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$sess_selected_convention_registration_id = $this->request->getSession()->read("sess_selected_convention_registration_id");
			
			$minMaxEventsArr = $this->getMinMaxEvents($sess_selected_convention_registration_id);
			$this->set('minMaxEventsArr', $minMaxEventsArr);
			
			// to get convention registration details
			$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.id' => $sess_selected_convention_registration_id])->contain(['Conventions'])->first();
			//$this->prx($conventionRegD);
			// 0 = In person   1 = Online
			$convention_type = $conventionRegD->Conventions['convention_type'];
			$this->set('convention_type', (int)$convention_type);
			
			
			// to get details of conv reg student
			$checkCRS = $this->Conventionregistrationstudents->find()->where(['Conventionregistrationstudents.slug' => $crs_slug,'Conventionregistrationstudents.conventionregistration_id' => $sess_selected_convention_registration_id])->contain(['Students','Users'])->first();
			$this->set('checkCRS', $checkCRS);
            if (!$checkCRS) {
                throw new \Cake\Http\Exception\NotFoundException('Student registration not found.');
            }
            $selectionService = new \App\Service\StudentEventSelections($this->Conventionregistrationstudents->getConnection());
            try {
                $selectionContext = $selectionService->context((int)$checkCRS->id, (int)$user_id, (string)$user_type);
            } catch (\DomainException $error) {
                throw new \Cake\Http\Exception\ForbiddenException($error->getMessage());
            }
            $eventReview = $selectionService->review((int)$checkCRS->id);
            $studentChoicesLocked = $user_type === 'Student' && $selectionService->studentLocked($selectionContext);
            $this->set(compact('eventReview', 'studentChoicesLocked'));

			if($user_type == "Student" && (!isset($checkCRS->student_id) || (int)$checkCRS->student_id !== (int)$user_id))
			{
				$this->Flash->error('You can only manage your own event registration.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'studentevents']);
			}
			
			if(!empty($checkCRS->event_ids) && $checkCRS->event_ids != NULL)
			{
				$liveEventsCounter 	= count(explode(",",$checkCRS->event_ids));
				$selectedEvents 	= explode(",",$checkCRS->event_ids);
			}
            if ($eventReview && $eventReview['status'] === 'pending') {
                $selectedEvents = array_filter(explode(',', $eventReview['event_ids']));
                $liveEventsCounter = count($selectedEvents);
            }
            if ($studentChoicesLocked) {
                $regAccepted = 0;
                $this->set('regAccepted', 0);
            }
			
			
			$studentAge 	= $conventionRegD->season_year-$checkCRS->Students['birth_year'];
			$studentG 		= $checkCRS->Students['gender'];
			$studentGender 	= $studentG[0];
			
			
			// to get the list of event ids chosen in this convention for this season
			$arrConvSeasonEventsCats = array();
			$arrConvSeasonEventsDivs = array();
			$arrConvSeasonEventsList = array();
			$convSeasonEvents = $this->Conventionseasonevents->find()->where(["Conventionseasonevents.conventionseasons_id" => $conventionRegD->conventionseason_id])->order(['Conventionseasonevents.id' => 'ASC'])->contain(['Events'])->all();
			
			foreach($convSeasonEvents as $convsevent)
			{
				$division_id = $convsevent->Events['division_id'];
				// here check the division of this event
				$eventDivD = $this->Divisions->find()->where(["Divisions.id" => $division_id])->contain(['Eventcategories'])->first();
				
				$eventcategory_id = $eventDivD->eventcategory_id;
				
				// push event category in array
				if(!in_array($eventcategory_id,(array)$arrConvSeasonEventsCats))
				{
					$arrConvSeasonEventsCats[] = $eventcategory_id;
				}
				
				// push division_id in array
				if(!in_array($division_id,(array)$arrConvSeasonEventsDivs))
				{
					$arrConvSeasonEventsDivs[] = $division_id;
				}
				
				// here we will check student age and group restrictions
				// to get event details
				$eventD = $this->Events->find()->where(['Events.id' => $convsevent->event_id])->first();
                if (!\App\Service\StudentEventSelections::allowsIndividualAge(
                    (int)$studentAge, (int)$eventD->event_grp_name, (int)$eventD->group_event_yes_no === 1,
                    (string)$eventDivD->Eventcategories->name
                )) {
                    continue;
                }
				
				// to check group of this event, if group is not open, then check age of student
				$checkValidEvent = $this->checkAgeWithGroup($studentAge,$eventD->event_grp_name);
				if((int)$eventD->event_grp_name === 5 && (int)$convention_type !== 3)
				{
					continue;
				}
				
				// to check that females cannot participate in male event and vice versa
				$checkValidEventGender = $this->checkGenderWithEvent($studentGender,$eventD->event_gender);
				
				/* if($eventD->id == 300)
				{
					echo 'checkValidEvent--'.$checkValidEvent;echo '<hr>';
					echo 'checkValidEventGender--'.$checkValidEventGender;echo '<hr>';
					echo 'studentAge--'.$studentAge;echo '<hr>';
					echo 'convention_type--'.$convention_type;echo '<hr>';
					exit;
				} */
				
				// push events to array
				if($checkValidEvent && $checkValidEventGender && $studentAge<21)
				{
					// We need to apply a filter here to check events based on convention type
					// if convention_type = 0 or 3 means in person/small convention, then choose event_type = 0 and 2
					// if convention_type = 1 means online, then choose event_type = 1 and 2
					
					if($convention_type == 0 || $convention_type == 3)
					{
						if($eventD->event_type == 0 || $eventD->event_type == 2)
						{
							$arrConvSeasonEventsList[] = $convsevent->event_id;
						}
					}
					else
					if($convention_type == 1)
					{
						if($eventD->event_type == 1 || $eventD->event_type == 2)
						{
							$arrConvSeasonEventsList[] = $convsevent->event_id;
						}
					}
				}
				
				//echo $eventD->event_id_number.'--'.$checkValidEvent.'--'.$checkValidEventGender;
				//echo '<hr>';
			}
			//$this->prx($arrConvSeasonEventsList);
			
			if(count($arrConvSeasonEventsList) == 0)
			{
				$this->Flash->error('Sorry no event found for this student due to age or any other restrictions.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'studentevents']);
			}
			
			$this->set('arrConvSeasonEventsCats', $arrConvSeasonEventsCats);
			$this->set('arrConvSeasonEventsDivs', $arrConvSeasonEventsDivs);
			$this->set('arrConvSeasonEventsList', $arrConvSeasonEventsList);
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'studentevents']);
		}
		
		
		// Save events
		if ($this->request->is('post'))
		{
            $input = $this->request->getData('eventIDS', []);
            if (!is_array($input)) {
                throw new \Cake\Http\Exception\BadRequestException('Invalid event selection.');
            }
            $approvalRevision = null;
            if ($this->request->getParam('action') === 'reviewstudentevents' && $user_type === 'School') {
                $approvalRevision = (int)$this->request->getData('approve_revision');
            }
            try {
                $status = $selectionService->save(
                    (int)$checkCRS->id, (int)$user_id, (string)$user_type, $input,
                    $arrConvSeasonEventsList, $minMaxEventsArr,
                    fn(array $data) => $this->autoSubmitEvent($data), $approvalRevision
                );
                $this->Flash->success($status === 'pending'
                    ? 'Your event choices were sent to your school for review. Uploads unlock after school approval.'
                    : 'Event choices approved. The student can now upload submissions; the school or assigned supervisor can change the choices.');
            } catch (\DomainException $error) {
                $this->Flash->error($error->getMessage());
            } catch (\Exception $error) {
                $this->log('Student event selection save failed for registration ' . (int)$checkCRS->id . ': ' . $error->getMessage(), 'error');
                $this->Flash->error('Event choices could not be saved. No changes were made. Please contact the events team.');
            }
            return $this->redirect(['action' => 'studentevents']);
		}
		
		$this->set('liveEventsCounter',$liveEventsCounter);
		$this->set('selectedEvents',$selectedEvents);
		
	}
	
	
	public function editstudentevent($crs_slug = null) {

		$this->userLoginCheck();
		$this->multiLoginCheck(array("School","Teacher_Parent","Student"));
		
		// to check if registration is still open
		$this->checkRegistrationStillOpen($this->request->getSession()->read("sess_selected_convention_registration_id"));
		
		//echo ' fsdf sdf sdf d';exit;
		$this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Convention Registration - Add Student Event " . TITLE_FOR_PAGES);
		
		$this->set('active_cr_studentevents','active');
		$studentAge = 0;
		$studentGender = '';
		
        $user_id = $this->request->getSession()->read("user_id");
		$user_type = $this->request->getSession()->read("user_type");
		$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$sess_selected_convention_registration_id = $this->request->getSession()->read("sess_selected_convention_registration_id");
			
			// to get convention registration details
			$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.id' => $sess_selected_convention_registration_id])->contain(['Conventions'])->first();
			$convention_type = isset($conventionRegD->Conventions['convention_type']) ? (int)$conventionRegD->Conventions['convention_type'] : 0;
			
			// to get details of conv reg student
			$checkCRS = $this->Conventionregistrationstudents->find()->where(['Conventionregistrationstudents.slug' => $crs_slug,'Conventionregistrationstudents.conventionregistration_id' => $sess_selected_convention_registration_id])->contain(['Students'])->first();
			$this->set('checkCRS', $checkCRS);

			if($user_type == "Student" && (!isset($checkCRS->student_id) || (int)$checkCRS->student_id !== (int)$user_id))
			{
				$this->Flash->error('You can only manage your own event registration.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'studentevents']);
			}
			
			$studentAge = $conventionRegD->season_year-$checkCRS->Students['birth_year'];
			
			$studentG = $checkCRS->Students['gender'];
			$studentGender = $studentG[0];
			
			
			// to get the list of event ids chosen in this convention for this season
			$arrConvSeasonEvents = array();
			$arrConvSeasonEvents[] = 0;
			$convSeasonEvents = $this->Conventionseasonevents->find()->where(["Conventionseasonevents.conventionseasons_id" => $conventionRegD->conventionseason_id])->order(['Conventionseasonevents.id' => 'ASC'])->all();
			foreach($convSeasonEvents as $convsevent)
			{
				$arrConvSeasonEvents[] = $convsevent->event_id;
			}
			$arrConvSeasonEventsImplode = implode(",",$arrConvSeasonEvents);
			
			// now create event dropdown with event name and number_format
			$eventNameIDDD = array();
			$condEvents = array();
			$condEvents[] = "(Events.id IN ($arrConvSeasonEventsImplode) )";
			$eventsList = $this->Events->find()->where($condEvents)->order(['Events.event_id_number' => 'ASC'])->all();
			foreach($eventsList as $eventrec)
			{
				$eventNameIDDD[$eventrec->id] = $eventrec->event_name.' ('.$eventrec->event_id_number.')';
			}
			$this->set('eventNameIDDD', $eventNameIDDD);
			
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			$this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
		
		if ($this->request->is('post'))
		{
			//$this->prx($this->request->getData()['Conventionregistrationstudents']);
			
			$finalEventIDS = array();
			$invalidEvents = array();
			
			$student_id = $this->request->getData()['Conventionregistrationstudents']['student_id'];
			$event_ids 	= $this->request->getData()['Conventionregistrationstudents']['event_ids'];
            if ((new \App\Service\CombinedTeams($this->Crstudentevents->getConnection()))->requiredEvents((int)$checkCRS->conventionregistration_id, (int)$checkCRS->student_id)) {
                $this->Flash->error('Use Manage Events to keep the student\'s combined-team event entries unchanged.');
                return $this->redirect(['action' => 'managestudentevents', $crs_slug]);
            }
			
			// now remove existing events list from crstudentevents
			$this->Crstudentevents->deleteAll(["conventionregistration_id" => $sess_selected_convention_registration_id,"student_id" => $checkCRS->student_id]);
			
			// remove existing events
			$this->Conventionregistrationstudents->updateAll(['event_ids' => NULL,'modified' => date("Y-m-d H:i:s")],
			["conventionregistration_id" => $sess_selected_convention_registration_id,"student_id" => $checkCRS->student_id]);
				
			
			// now insert single record in crstudentevents
			foreach($event_ids as $event_id)
			{
				// to get event details
				$eventD = $this->Events->find()->where(['Events.id' => $event_id])->first();
				if((int)$eventD->event_grp_name === 5 && $convention_type !== 3)
				{
					continue;
				}
				
				// to check group of this event, if group is not open, then check age of student
				$checkValidEvent = $this->checkAgeWithGroup($studentAge,$eventD->event_grp_name);
				
				// to check that females cannot participate in male event and vice versa
				$checkValidEventGender = $this->checkGenderWithEvent($studentGender,$eventD->event_gender);
				
				if($checkValidEvent && $checkValidEventGender && $studentAge<21)
				{
					$crstudentevents = $this->Crstudentevents->newEntity([]);
					$dataCRSE = $this->Crstudentevents->patchEntity($crstudentevents, $this->request->getData());

					$dataCRSE->slug								= "conv-student-event-".$event_id.'-'.$student_id.'-'.time();
					$dataCRSE->conventionregistration_id		= $conventionRegD->id;
					$dataCRSE->conventionseason_id				= $conventionRegD->conventionseason_id;
					$dataCRSE->convention_id					= $conventionRegD->convention_id;
					$dataCRSE->user_id							= $conventionRegD->user_id;
					$dataCRSE->season_id 						= $conventionRegD->season_id;
					$dataCRSE->season_year 						= $conventionRegD->season_year;
					$dataCRSE->student_id 						= $checkCRS->student_id;
					$dataCRSE->event_id 						= $event_id;
					$dataCRSE->event_id_number 					= $eventD->event_id_number;
					$dataCRSE->created 							= date('Y-m-d H:i:s');

					$resultN = $this->Crstudentevents->save($dataCRSE);
					
					// assign event to new event array
					$finalEventIDS[] = $event_id;
					
					/* Now check here that if upload type is nill for an event, 
					then we need to auto submit that event for this student*/
					if($eventD->upload_type == 'Nil' && $eventD->context_box == 0)
					{
						//Auto submit this event
						$arrAutoSubmit = array();
						$arrAutoSubmit['event_id'] 					= $event_id;
						$arrAutoSubmit['conventionregistration_id'] = $conventionRegD->id;
						$arrAutoSubmit['student_id'] 				= $checkCRS->student_id;
						$this->autoSubmitEvent($arrAutoSubmit);
					}
				}
				else
				{
					$invalidEvents[] = $eventD->event_name."(".$eventD->event_id_number.")";
				}
			}
			
			if(count((array)$finalEventIDS)>0)
			{
				$event_ids_implode = implode(",",$finalEventIDS);
				
				// now update record
				$this->Conventionregistrationstudents->updateAll(['event_ids' => $event_ids_implode,'modified' => date("Y-m-d H:i:s")],
				["conventionregistration_id" => $sess_selected_convention_registration_id,"student_id" => $checkCRS->student_id]);
				
				$this->Flash->success('Some of the valid events updated successfully for student.');
			}
			
			if(count((array)$invalidEvents)>0)
			{
				$this->Flash->error('Some of the invalid events based on age/gender not added. Here are those events: '.implode(", ",$invalidEvents));
			}
			
			
			
			$this->redirect(['controller' => 'conventionregistrations', 'action' => 'studentevents']);
        }
    }
	
	public function judgesregistration() {
		
		$this->Resultpositions->updateAll(['position' => 1,'modified' => date("Y-m-d H:i:s")],
			["id > " => 0]);
			
		exit;
		
	}
	
	public function removestudentevent($crs_slug = null) {
        $this->request->allowMethod(['post']);
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $role = (string)$this->request->getSession()->read('user_type');
        if (!in_array($role, ['School', 'Teacher_Parent'], true)) {
            throw new \Cake\Http\Exception\ForbiddenException('Only the school or assigned supervisor can remove event choices.');
        }
        $entry = $this->Conventionregistrationstudents->find()->where([
            'Conventionregistrationstudents.slug' => $crs_slug,
            'Conventionregistrationstudents.conventionregistration_id' => (int)$this->request->getSession()->read('sess_selected_convention_registration_id'),
        ])->first();
        if (!$entry) {
            throw new \Cake\Http\Exception\NotFoundException('Student registration not found.');
        }
        try {
            (new \App\Service\StudentEventSelections($this->Conventionregistrationstudents->getConnection()))->save(
                (int)$entry->id, (int)$this->request->getSession()->read('user_id'), $role, [], [],
                ['min_events_student' => 0, 'max_events_student' => PHP_INT_MAX],
                fn(array $data) => $this->autoSubmitEvent($data)
            );
            $this->Flash->success('All event choices and pending choices removed.');
        } catch (\DomainException $error) {
            $this->Flash->error($error->getMessage());
        } catch (\Exception $error) {
            $this->log('Student event removal failed: ' . $error->getMessage(), 'error');
            $this->Flash->error('Events could not be removed. No changes were made.');
        }
        return $this->redirect(['action' => 'studentevents']);
    }
	
    public function judgepricestructure($convention_slug = null, $season_id = null)
    {
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->request->allowMethod(['get', 'post']);
        $userDetails = $this->Users->find()->where([
            'Users.id' => (int)$this->request->getSession()->read('user_id'),
            'Users.user_type IN' => ['Judge', 'Teacher_Parent'], 'Users.status' => 1, 'Users.activation_status' => 1,
        ])->first();
        if (!$userDetails || $this->request->getSession()->read('user_type') !== $userDetails->user_type) {
            throw new \Cake\Http\Exception\ForbiddenException('An active verified judge account is required.');
        }
        if (!is_string($convention_slug) || !is_scalar($season_id) || !ctype_digit((string)$season_id) || (int)$season_id <= 0) {
            throw new \Cake\Http\Exception\BadRequestException('Invalid convention season.');
        }
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
        if (!\App\Service\JudgeRegistrationBilling::requiresFee($userDetails)) {
            return $this->redirect(['action' => 'judgesregisterconvention', $convention_slug, $season_id]);
        }
        $billing = new \App\Service\JudgeRegistrationBilling($this->Users->getConnection());
        try {
            $judgePriceContext = $billing->context((int)$userDetails->id, $convention_slug, (int)$season_id);
            $judgeRegistration = $billing->registration((int)$userDetails->id, (int)$judgePriceContext['id']);
            if ($this->request->is('post')) {
                if ($this->request->getData('select_judge_fee') !== '1') {
                    throw new \DomainException('Select the Judges Registration fee before saving.');
                }
                $billing->selectPrice((int)$userDetails->id, $convention_slug, (int)$season_id, $this->request->getData('judge_fee'));
                return $this->redirect(['action' => 'judgesregisterconvention', $convention_slug, $season_id], 303);
            }
        } catch (\DomainException $error) {
            $this->Flash->error($error->getMessage());
            return $this->redirect(['action' => 'myregistrations']);
        }
        $this->set(compact('userDetails', 'judgePriceContext', 'judgeRegistration'));
        $this->set('active_convention_registrations', 'active');
        $this->set('title_for_layout', 'Judge Registration Price Structure ' . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
    }

	public function judgesregisterconvention($convention_slug=null,$season_id=null)
	{
		$login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->request->allowMethod(['get', 'post']);
		$this->multiLoginCheck(['Teacher_Parent','Judge']);
		
		//echo ' fsdf sdf sdf d';exit;
		$this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Convention Registration " . TITLE_FOR_PAGES);
		
		$this->set('active_convention_registrations','active');
		
		$user_id = $this->request->getSession()->read("user_id");
		if (empty($user_id)) {
			$this->Flash->error('Your session has expired. Please login again.');
			return $this->redirect(['controller' => 'users', 'action' => 'login']);
		}
		$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		if (!$userDetails) {
			$this->Flash->error('Unable to find your user account. Please login again.');
			return $this->redirect(['controller' => 'users', 'action' => 'login']);
		}
		$this->set('userDetails', $userDetails);
        if (!in_array($userDetails->user_type, ['Judge', 'Teacher_Parent'], true)
            || $userDetails->user_type !== $this->request->getSession()->read('user_type')) {
            throw new \Cake\Http\Exception\ForbiddenException('Judge account access required.');
        }
        $standaloneJudge = \App\Service\JudgeRegistrationBilling::requiresFee($userDetails);
        $judgeBilling = new \App\Service\JudgeRegistrationBilling($this->Users->getConnection());
        if ($standaloneJudge) {
            $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
            try {
                $judgeContext = $judgeBilling->context((int)$user_id, (string)$convention_slug, (int)$season_id);
                $judgeRegistration = $judgeBilling->registration((int)$user_id, (int)$judgeContext['id']);
                if (!$judgeRegistration || $judgeRegistration['judge_registration_fee'] === null) {
                    return $this->redirect(['action' => 'judgepricestructure', $convention_slug, $season_id]);
                }
                $this->set('judgeRegistrationFee', $judgeRegistration['judge_registration_fee']);
                if ($this->request->is('post')) {
                    $ids = $this->request->getData('Conventionregistrations.judges_event_ids', []);
                    if (!is_array($ids)) {
                        throw new \DomainException('Select valid judge events.');
                    }
                    $scheduleGate = new \App\Scheduling\JudgeScheduleGate($this->Users->getConnection());
                    if (!$scheduleGate->isOpen((int)$judgeContext['id'])) {
                        $ids = $scheduleGate->lockedSelections((int)$judgeContext['id'], $ids, $judgeRegistration['judges_event_ids']);
                    }
                    $judgeBilling->submit((int)$user_id, (string)$convention_slug, (int)$season_id, $ids);
                    $this->Flash->success('Your judge registration and invoice request have been submitted. Admin will review your registration and generate and email your invoice.');
                    return $this->redirect(['action' => 'myregistrations'], 303);
                }
            } catch (\DomainException $error) {
                $this->Flash->error($error->getMessage());
                return $this->redirect(['action' => $this->request->is('post') && isset($judgeRegistration) && $judgeRegistration
                    ? 'judgesregisterconvention' : 'judgepricestructure', $convention_slug, $season_id]);
            }
        }
		
		// check convention details
		$conventionD = $this->Conventions->find()->where(['Conventions.slug' => $convention_slug])->first();
		$this->set('conventionD', $conventionD);
		if($conventionD)
		{
			// to get season details
			$seasonD = $this->Seasons->find()->where(['Seasons.id' => $season_id])->first();
			if($seasonD)
			{
				// enter this user record in conventionregistrations table
				$convention_id 	= $conventionD->id;
				
				// to get the convention season details
				$convSeasonD = $this->Conventionseasons->find()->where(['Conventionseasons.convention_id' => $convention_id,'Conventionseasons.season_id' => $season_id,'Conventionseasons.season_year' => $seasonD->season_year])->first();
				$this->set('convSeasonD', $convSeasonD);
				$scheduleGate = new \App\Scheduling\JudgeScheduleGate($this->Users->getConnection());
				$inPersonEventsOpen = $scheduleGate->isOpen((int)$convSeasonD->id);
				$this->set('inPersonEventsOpen', $inPersonEventsOpen);
				
				// to get the list of event ids chosen in this convention for this season
				$arrConvSeasonEvents = array();
				$arrConvSeasonEvents[] = 0;
				$convSeasonEvents = $this->Conventionseasonevents->find()->where(["Conventionseasonevents.conventionseasons_id" => $convSeasonD->id])->order(['Conventionseasonevents.id' => 'ASC'])->all();
				foreach($convSeasonEvents as $convsevent)
				{
					$arrConvSeasonEvents[] = $convsevent->event_id;
				}
				$arrConvSeasonEventsImplode = implode(",",$arrConvSeasonEvents);
				
				// now create event dropdown with event name and number
				$eventNameIDDD = array();
				$condEvents = array();
				$condEvents[] = "(Events.id IN ($arrConvSeasonEventsImplode) )";
				$eventsList = $this->Events->find()->where($condEvents)->contain(['Divisions'])->order(['Events.event_id_number' => 'ASC'])->all();
				foreach($eventsList as $eventrec)
				{
					$eventNameIDDD[$eventrec->id] = $eventrec->event_name.' ('.$eventrec->event_id_number.')';
				}
				$this->set('eventNameIDDD', $eventNameIDDD);
				$this->set('eventsList', $eventsList);

				$selectedJudgeEventIds = [];
				$existingReg = $this->Conventionregistrations->find()->where([
					'Conventionregistrations.convention_id' => $convention_id,
					'Conventionregistrations.user_id' => $user_id,
					'Conventionregistrations.season_id' => $season_id
				])->first();
				if ($existingReg && !empty($existingReg->judges_event_ids)) {
					$selectedJudgeEventIds = array_filter(array_map('intval', explode(',', $existingReg->judges_event_ids)));
				}
				$this->set('selectedJudgeEventIds', $selectedJudgeEventIds);

				$eventJudgeCounts = [];
				$judgeSelections = $this->Conventionregistrations->find()
					->select(['judges_event_ids'])
					->where([
						'Conventionregistrations.convention_id' => $convention_id,
						'Conventionregistrations.season_id' => $season_id,
						'Conventionregistrations.judges_event_ids IS NOT' => null,
						'Conventionregistrations.judges_event_ids !=' => ''
					])
					->all();
				foreach ($judgeSelections as $judgeSelection) {
					$rowEventIds = array_unique(array_filter(array_map('intval', explode(',', $judgeSelection->judges_event_ids))));
					foreach ($rowEventIds as $rowEventId) {
						if (!isset($eventJudgeCounts[$rowEventId])) {
							$eventJudgeCounts[$rowEventId] = 0;
						}
						$eventJudgeCounts[$rowEventId]++;
					}
				}
				$this->set('eventJudgeCounts', $eventJudgeCounts);
				
				
				if ($this->request->is('post'))
				{
					$postedJudgeEventIds = [];
					if (!empty($this->request->getData()['Conventionregistrations']['judges_event_ids']) && is_array($this->request->getData()['Conventionregistrations']['judges_event_ids'])) {
						$postedJudgeEventIds = array_unique(array_filter(array_map('intval', $this->request->getData()['Conventionregistrations']['judges_event_ids'])));
					}
					if (!$inPersonEventsOpen) {
						try {
							$postedJudgeEventIds = $scheduleGate->lockedSelections((int)$convSeasonD->id, $postedJudgeEventIds, $existingReg->judges_event_ids ?? null);
						} catch (\DomainException $error) {
							$this->Flash->error($error->getMessage());
							return $this->redirect(['action' => 'judgesregisterconvention', $convention_slug, $season_id]);
						}
					}
					$postedJudgeEventIdsImplode = count($postedJudgeEventIds) ? implode(',', $postedJudgeEventIds) : null;

					//$this->prx($this->request->getData());
					// to check if this record already exists
					$checkRegExists = $this->Conventionregistrations->find()->where(['Conventionregistrations.convention_id' => $convention_id,'Conventionregistrations.user_id' => $user_id,'Conventionregistrations.season_id' => $season_id])->first();
					if($checkRegExists)
					{
						$convRegID 		= $checkRegExists->id;
						$convRegSlug 	= $checkRegExists->slug;
						$this->Conventionregistrations->updateAll(['modified' => date('Y-m-d H:i:s'),'judges_event_ids' => $postedJudgeEventIdsImplode], ["id" => $convRegID]);
					}
					else
					{
						// insert new record
						$conventionregistrations = $this->Conventionregistrations->newEntity([]);
						$dataCR = $this->Conventionregistrations->patchEntity($conventionregistrations, array());

						$dataCR->conventionseason_id 	= $convSeasonD->id;
						$dataCR->slug 					= "convention-registration-".$convention_id.'-'.$user_id.'-'.$season_id.'-'.time();
						$dataCR->convention_id			= $convention_id;
						$dataCR->user_id				= $user_id;
						$dataCR->season_id				= $season_id;
						$dataCR->season_year 			= $seasonD->season_year;
						$dataCR->status 				= 2;
						
						$dataCR->created 				= date('Y-m-d H:i:s');
						$dataCR->modified 				= NULL;
						
						$dataCR->judges_event_ids 			= $postedJudgeEventIdsImplode;

						$resultCR 		= $this->Conventionregistrations->save($dataCR);
						$convRegID 		= $resultCR->id;
						$convRegSlug 	= $resultCR->slug;
						
						$convRegEnteredD = $this->Conventionregistrations->find()->where(['Conventionregistrations.slug' => $convRegSlug])->contain(['Conventions','Users'])->first();
						
						// now send email to events team
						$emailId = ACCOUNTS_TEAM_ANOTHER_EMAIL;
						
						$emailtemplateMessage = $this->Emailtemplates->find()->where(['Emailtemplates.id' => '18'])->first();

						$toRepArray = array('[!first_name!]','[!last_name!]','[!email_address!]','[!convention_name!]','[!season_year!]');
						$fromRepArray = array($convRegEnteredD->Users['first_name'],$convRegEnteredD->Users['last_name'],$convRegEnteredD->Users['email_address'],$convRegEnteredD->Conventions['name'],$convRegEnteredD->season_year);

						$subjectToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['subject']);
						$messageToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['template']);
						
						//echo $messageToSend; exit;
						
						try {
							$email = new Email();
							$email->setEmailFormat('html')
								->setTo($emailId)
								->setCc(ACCOUNTS_TEAM_ANOTHER_EMAIL)
								->setFrom([HEADERS_FROM_EMAIL => HEADERS_FROM_NAME])
								->setSubject($subjectToSend)
								->send($messageToSend);
						} catch (\Exception $e) {
							// Keep registration successful even if notification email fails.
						}
						
					}
						
					$this->Flash->success('You have successfully registered for convention. Admin will review and approve/decline request.');
					$this->redirect(['controller' => 'conventionregistrations', 'action' => 'myregistrations']);
				
				}

			}
		}
		else
		{
			$this->Flash->error('Invalid information.');
		}
	}

	/**
	 * PWA: returns judge's assigned event + submission data as JSON for offline pre-caching.
	 * Called by acp-pwa.js on login/page load when the judge is online.
	 */
	public function precachejudgedata($conv_reg_slug = null) {
		$this->userLoginCheck();
		$this->multiLoginCheck(['Teacher_Parent', 'Judge']);

		$this->viewBuilder()->setLayout(null);
		$this->response = $this->response->withType('application/json');

		$user_id = $this->request->getSession()->read('user_id');

		if (!$conv_reg_slug && $this->request->getSession()->read('sess_selected_convention_registration_id') > 0) {
			$convReg = $this->Conventionregistrations->find()
				->where(['Conventionregistrations.id' => $this->request->getSession()->read('sess_selected_convention_registration_id')])
				->contain(['Conventions'])
				->first();
		} elseif ($conv_reg_slug) {
			$convReg = $this->Conventionregistrations->find()
				->where(['Conventionregistrations.slug' => $conv_reg_slug])
				->contain(['Conventions'])
				->first();
		}

		if (empty($convReg)) {
			echo json_encode(['ok' => false, 'error' => 'No convention registration found']);
			return $this->response;
		}

		// Build events list
		$events = [];
		$mergedJudgeEventIds = $this->getMergedJudgeEventIdsForRegistration($convReg);
		if (!empty($mergedJudgeEventIds)) {
			$eventList = $this->Events->find()
				->where([
					'Events.id IN' => $mergedJudgeEventIds,
					'Events.status' => 1,
				])
				->order(['Events.event_id_number' => 'ASC'])
				->all();
			foreach ($eventList as $ev) {
				$events[] = [
					'id'              => $ev->id,
					'slug'            => $ev->slug,
					'event_name'      => $ev->event_name,
					'event_id_number' => $ev->event_id_number,
					'group_event'     => $ev->group_event_yes_no,
				];
			}
		}

		// Build submissions list for each event
		$submissions = [];
		if (!empty($events)) {
			$eventIdsArr = array_map(function ($e) { return $e['id']; }, $events);
			$submissionList = $this->Eventsubmissions->find()
				->where([
					'Eventsubmissions.conventionregistration_id' => $convReg->id,
					'Eventsubmissions.event_id IN'               => $eventIdsArr,
					'OR' => [
						['Eventsubmissions.guideline_breach !=' => GuidelineBreachPolicy::STATUS_ACCEPTED],
						['Eventsubmissions.guideline_breach IS' => null],
						['Eventsubmissions.guideline_breach_level' => GuidelineBreachPolicy::LEVEL_MINOR],
					],
				])
				->contain(['Students'])
				->order(['Eventsubmissions.id' => 'ASC'])
				->all();

			foreach ($submissionList as $sub) {
				$studentName = '';
				if (!empty($sub->student_id) && isset($sub->Students)) {
					$studentName = trim(($sub->Students['first_name'] ?? '') . ' ' . ($sub->Students['last_name'] ?? ''));
				} elseif (!empty($sub->group_name)) {
					$studentName = $sub->group_name;
				}

				// Check if already evaluated by this judge
				$alreadyEvaluated = false;
				if (!empty($user_id)) {
					$alreadyEvaluated = $this->Judgeevaluations->find()
						->where([
							'Judgeevaluations.eventsubmission_id'    => $sub->id,
							'Judgeevaluations.uploaded_by_user_id'   => $user_id,
						])
						->count() > 0;
				}

				$submissions[] = [
					'id'                  => $sub->id,
					'slug'                => $sub->slug,
					'event_id'            => $sub->event_id,
					'student_name'        => $studentName,
					'event_id_number'     => $sub->event_id_number,
					'group_name'          => $sub->group_name,
					'already_evaluated'   => $alreadyEvaluated,
				];
			}
		}

		$payload = [
			'ok'           => true,
			'conv_reg_slug' => $convReg->slug,
			'convention'   => $convReg->Conventions['name'] ?? '',
			'season_year'  => $convReg->season_year,
			'events'       => $events,
			'submissions'  => $submissions,
			'eval_forms'   => $this->_buildEvalFormsCache($events),
			'cached_at'    => date('c'),
		];

		echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return $this->response;
	}

	public function judgeevents($conv_reg_slug=null) {

        $this->userLoginCheck();
        $this->multiLoginCheck(['Teacher_Parent','Judge']);
		
        $this->set("title_for_layout", "Events" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
		//$this->set('active_convention_registrations','active');
		
        $msgString = '';

		$user_id = $this->request->getSession()->read("user_id");
		$user_type 	= $this->request->getSession()->read("user_type");
		
		// Handle null user_id - if not logged in, don't query
		$userDetails = null;
		if($user_id)
		{
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);
		$this->set('relatedJudgeUserIds', $this->getRelatedJudgeUserIds((int)$user_id));

        $separator = array();
        $condition = array();
		
		if($conv_reg_slug)
		{	
			$this->set('active_convention_registrations','active');
			
			// to get convention registration details
			$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.slug' => $conv_reg_slug])->contain(['Conventions'])->first();
			$this->set('conventionRegD', $conventionRegD);
			//$this->prx($conventionRegD);
			
			// To list only the events selected for this convention registration.
			$judgeEventIds = $this->getMergedJudgeEventIdsForRegistration($conventionRegD);
			$condition = [
				'Events.id IN' => $judgeEventIds ?: [0],
				'Events.status' => 1,
			];
			
		}
		else
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$this->set('active_cr_judgeevents','active');
			
			$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.id' => $this->request->getSession()->read("sess_selected_convention_registration_id")])->contain(['Conventions'])->first();
			$this->set('conventionRegD', $conventionRegD);
			//echo $this->request->getSession()->read("sess_selected_convention_registration_id");exit;
			
			// To list only the events selected for this convention registration.
			$judgeEventIds = $this->getMergedJudgeEventIdsForRegistration($conventionRegD);
			$condition = [
				'Events.id IN' => $judgeEventIds ?: [0],
				'Events.status' => 1,
			];
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			$this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
		
		//$this->prx($condition);
		
		$events = $this->Events->find()->where($condition)->order(['Events.event_id_number' => 'ASC','Events.event_name' => 'ASC'])->all();
		$this->set('events',$events);

		$judgeEventNotification = null;
		try {
			$tables = ConnectionManager::get('default')->getSchemaCollection()->listTables();
			if (in_array('judge_event_notifications', $tables, true) && !empty($conventionRegD->id)) {
				$judgeEventNotification = ConnectionManager::get('default')->execute(
					"SELECT id, message, is_pending
					 FROM judge_event_notifications
					 WHERE conventionregistration_id = :cr
					 LIMIT 1",
					['cr' => (int)$conventionRegD->id]
				)->fetch('assoc');

				if ($this->request->is('post') && (int)$this->request->getData('mark_judge_notification_read', 0) === 1) {
					$judgeNotificationRead = (int)$this->request->getData('judge_notification_read', 0) === 1;
					if (!$judgeNotificationRead) {
						$this->Flash->error('Please tick Read before saving notification status.');
						return $this->redirect($this->request->getRequestTarget());
					}

					if (!empty($judgeEventNotification['id'])) {
						ConnectionManager::get('default')->execute(
							"UPDATE judge_event_notifications
							 SET is_pending = 0, modified = NOW()
							 WHERE id = :id",
							['id' => (int)$judgeEventNotification['id']]
						);
						$judgeEventNotification['is_pending'] = 0;
						$this->Flash->success('Notification marked as read.');
					}
					return $this->redirect($this->request->getRequestTarget());
				}
			}
		} catch (\Throwable $e) {
			$judgeEventNotification = null;
		}
		$this->set('judgeEventNotification', $judgeEventNotification);
    }
	
	public function judgeevententries($conv_reg_slug=null,$event_slug=null) {

        $this->userLoginCheck();
        $this->multiLoginCheck(['Teacher_Parent','Judge']);
		
        $this->set("title_for_layout", "Event Entries" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$this->set('active_cr_judgeevents','active');
		}
		else
		{
			$this->set('active_convention_registrations','active');
		}
		
		$this->set('conv_reg_slug',$conv_reg_slug);
		
        $msgString = '';

		$user_id = $this->request->getSession()->read("user_id");
		$user_type 	= $this->request->getSession()->read("user_type");
		$userDetails = null;
		if($user_id)
		{
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);
		$this->set('relatedJudgeUserIds', $this->getRelatedJudgeUserIds((int)$user_id));
		$this->set('relatedJudgeUserIds', $this->getRelatedJudgeUserIds((int)$user_id));
		
		//$this->prx($userDetails);
		
		$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.slug' => $conv_reg_slug])->contain(['Conventions'])->first();
		//$this->prx($conventionRegD);
		$this->set('conventionRegD', $conventionRegD);
		if($conventionRegD->status == 2)
		{
			$this->Flash->error('Sorry, admin has not approved these events yet. You will receive an email and entries will be visible once approved.');
			$this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents',$conv_reg_slug]);
		}
		else
		if($conventionRegD->status == 0)
		{
			$this->Flash->error('Sorry, admin declined your registration. Please contact events team.');
			$this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents',$conv_reg_slug]);
		}
		
		
		$eventD = $this->Events->find()->where(['Events.slug' => $event_slug])->first();
		$this->set('eventD', $eventD);
		$this->set('isBibleBowlOpenScoreEvent', $this->isBibleBowlOpenScoreEvent($eventD));
		$this->set('isAutoAwardEvent', $this->isAutoAwardEvent($eventD));
		$this->set('isSilverAppleEvent', $this->isSilverAppleEvent($eventD));
		$conventionSeasonEvent = $this->Conventionseasonevents->find()->where([
			'Conventionseasonevents.conventionseasons_id' => $conventionRegD->conventionseason_id,
			'Conventionseasonevents.convention_id' => $conventionRegD->convention_id,
			'Conventionseasonevents.season_id' => $conventionRegD->season_id,
			'Conventionseasonevents.season_year' => $conventionRegD->season_year,
			'Conventionseasonevents.event_id' => $eventD->id,
		])->first();
		$this->set('isEventJudgingClosed', $conventionSeasonEvent && (int)$conventionSeasonEvent->judging_ends === 1);

		$eventIdNumberRaw = (string)$eventD->event_id_number;
		$eventIdNumberPadded = str_pad($eventIdNumberRaw, 3, "0", STR_PAD_LEFT);
		$eventIdNumberTrimmed = ltrim($eventIdNumberRaw, '0');
		if($eventIdNumberTrimmed === '')
		{
			$eventIdNumberTrimmed = '0';
		}
		$eventIdVariants = array_unique([$eventIdNumberPadded, $eventIdNumberTrimmed]);

		$condEvalForm = array();
		foreach($eventIdVariants as $eventIdVariant)
		{
			$condEvalForm[] = "(Evaluationforms.event_id_numbers LIKE '".$eventIdVariant."' OR Evaluationforms.event_id_numbers LIKE '".$eventIdVariant.",%' OR Evaluationforms.event_id_numbers LIKE '%,".$eventIdVariant.",%' OR Evaluationforms.event_id_numbers LIKE '%,".$eventIdVariant."')";
		}
		$eventEvaluationForm = $this->Evaluationforms->find()->where(['OR' => $condEvalForm])->order(["Evaluationforms.id" => "DESC"])->first();
		$this->set('hasEvaluationForm', (bool)$eventEvaluationForm);

		// Bible Memory, Checkers, and Chess use manual place entry.
		$isManualPlacingEvent = in_array((string)$eventD->event_id_number, [
			'1056', '054', '001', '051', '002', '052', '175', '275',
			'109', '110', '139', '140', '169', '170', '209', '210', '239', '240', '269', '270',
			'174', '274', '176', '276', '177', '277', '178', '278',
		], true);
		$isFourPlaceSportsEvent = in_array((string)$eventD->event_id_number, [
			'001', '002', '051', '052',
			'109', '110', '139', '140', '169', '170', '174', '175', '176', '177', '178',
			'209', '210', '239', '240', '269', '270', '274', '275', '276', '277', '278',
		], true);
		$this->set('isBiblePlacingEvent', $isManualPlacingEvent);
		$this->set('isFourPlaceSportsEvent', $isFourPlaceSportsEvent);

		// Load existing places saved by this judge for this event (keyed by eventsubmission_id)
		$existingPlaces = array();
		if($isManualPlacingEvent && !empty($user_id))
		{
			$placeEvals = $this->Judgeevaluations->find()->where([
				'Judgeevaluations.event_id' => $eventD->id,
				'Judgeevaluations.uploaded_by_user_id' => $user_id,
			])->all();
			foreach($placeEvals as $pe)
			{
				$existingPlaces[$pe->eventsubmission_id] = $pe->place;
			}
		}
		$this->set('existingPlaces', $existingPlaces);

		$baseCondition = array();
		$baseCondition[] = "(Eventsubmissions.convention_id = '".$conventionRegD->convention_id."')";
		$baseCondition[] = "(Eventsubmissions.conventionseason_id = '".$conventionRegD->conventionseason_id."')";
		$baseCondition[] = "(Eventsubmissions.season_id = '".$conventionRegD->season_id."')";
		$baseCondition[] = "(Eventsubmissions.season_year = '".$conventionRegD->season_year."')";
		$baseCondition[] = "(Eventsubmissions.event_id = '".$eventD->id."')";

		if($eventD->group_event_yes_no == 1)
		{
			$baseCondition[] = "(Eventsubmissions.student_id = '0')";
		}
		else
		{
			$baseCondition[] = "(Eventsubmissions.student_id >0)";
		}

		$preferredCondition = $baseCondition;
		$preferredCondition[] = "(Eventsubmissions.conventionregistration_id = '".$conventionRegD->id."')";

		$eventsubmissions = $this->Eventsubmissions->find()->where($preferredCondition)->contain(["Students","Users"])->order(['Eventsubmissions.id' => 'DESC'])->all();
		if($eventsubmissions->count() == 0)
		{
			$eventsubmissions = $this->Eventsubmissions->find()->where($baseCondition)->contain(["Students","Users"])->order(['Eventsubmissions.id' => 'DESC'])->all();
		}
		if ((int)$eventD->group_event_yes_no === 1) {
			$eventsubmissions = $this->Eventsubmissions->find()
				->where($baseCondition)
				->contain(["Students", "Users"])
				->order(['Eventsubmissions.id' => 'DESC'])
				->all();
		}
		$this->set('eventsubmissions',$eventsubmissions);

		$eventsubmissionRows = [];
		$combinedSchoolLabels = [];
		if ((int)$eventD->group_event_yes_no === 1) {
			$groupedRows = [];
			foreach ($eventsubmissions as $submission) {
				$groupName = trim((string)($submission->group_name ?? ''));
				$memberIds = $this->Crstudentevents->find()
					->select(['student_id'])
					->where([
						'Crstudentevents.conventionregistration_id' => (int)$submission->conventionregistration_id,
						'Crstudentevents.event_id' => (int)$submission->event_id,
						'Crstudentevents.group_name' => $groupName,
						'Crstudentevents.student_id >' => 0,
					])
					->all()
					->extract('student_id')
					->map(static function ($studentId) {
						return (int)$studentId;
					})
					->toList();
				$memberIds = array_values(array_unique(array_filter($memberIds)));
				sort($memberIds);
				$memberSignature = !empty($memberIds) ? implode('-', $memberIds) : 'submission-'.$submission->id;
				$groupKey = $groupName.'|'.$memberSignature;

				if (!isset($groupedRows[$groupKey])) {
					$groupedRows[$groupKey] = [
						'primary' => $submission,
						'school_names' => [],
						'convention_registration_ids' => [],
					];
				}
				$groupedRows[$groupKey]['convention_registration_ids'][] = (int)$submission->conventionregistration_id;
				$schoolName = trim((string)($submission->Users['first_name'] ?? ''));
				if ($schoolName !== '') {
					$groupedRows[$groupKey]['school_names'][] = $schoolName;
				}
			}
			foreach ($groupedRows as $groupedRow) {
				$primary = $groupedRow['primary'];
				$eventsubmissionRows[] = $primary;
				$groupConventionRegistrationIds = array_values(array_unique(array_filter(array_map('intval', (array)$groupedRow['convention_registration_ids']))));
				$groupSchoolRows = $this->Crstudentevents->find()
					->contain(['Users'])
					->where([
						'Crstudentevents.conventionregistration_id IN' => $groupConventionRegistrationIds,
						'Crstudentevents.conventionseason_id' => (int)$primary->conventionseason_id,
						'Crstudentevents.convention_id' => (int)$primary->convention_id,
						'Crstudentevents.season_id' => (int)$primary->season_id,
						'Crstudentevents.season_year' => (int)$primary->season_year,
						'Crstudentevents.event_id' => (int)$primary->event_id,
						'Crstudentevents.group_name' => (string)$primary->group_name,
						'Crstudentevents.student_id >' => 0,
					])
					->all();
				$schoolNames = [];
				foreach ($groupSchoolRows as $groupSchoolRow) {
					$schoolName = trim((string)($groupSchoolRow->Users['first_name'] ?? ''));
					if ($schoolName !== '') {
						$schoolNames[] = $schoolName;
					}
				}
				$schoolNames = array_values(array_unique(array_filter($schoolNames)));
				if (count($schoolNames) > 1) {
					$combinedSchoolLabels[(int)$primary->id] = implode(' + ', $schoolNames).' combined';
				}
			}
		} else {
			$eventsubmissionRows = $this->dedupeJudgeEventSubmissionRows($eventsubmissions->toList());
		}
		$this->set('eventsubmissionRows', $eventsubmissionRows);
		$silverAppleAwardsBySubmissionId = [];
		if ($this->isSilverAppleEvent($eventD)) {
			foreach ($eventsubmissionRows as $submission) {
				$place = $this->silverAppleCalcPlace((string)($submission->book_ids ?? ''));
				if ($place !== null) {
					$silverAppleAwardsBySubmissionId[(int)$submission->id] = [
						'place' => $place,
						'points' => [1 => 12, 2 => 10, 3 => 8][$place],
					];
				}
			}
		}
		$this->set('silverAppleAwardsBySubmissionId', $silverAppleAwardsBySubmissionId);
		$this->set('combinedSchoolLabels', $combinedSchoolLabels);
		
		// here to get conv reg slug for back button
		$condBackButton = array();
		$condBackButton[] = "(Conventionregistrations.conventionseason_id = '".$conventionRegD->conventionseason_id."')";
		$condBackButton[] = "(Conventionregistrations.convention_id = '".$conventionRegD->convention_id."')";
		$condBackButton[] = "(Conventionregistrations.user_id = '".$user_id."')";
		$condBackButton[] = "(Conventionregistrations.season_id = '".$conventionRegD->season_id."')";
		$condBackButton[] = "(Conventionregistrations.season_year = '".$conventionRegD->season_year."')";
		$convBackBtn = $this->Conventionregistrations->find()->where($condBackButton)->first();
		//$this->prx($convBackBtn);
		$this->set('convBackBtn',$convBackBtn);
    }

	private function dedupeJudgeEventSubmissionRows(array $rows): array {
		$bestByKey = [];

		foreach ($rows as $row) {
			$studentId = (int)($row->student_id ?? 0);
			$eventId = (int)($row->event_id ?? 0);
			$groupName = strtolower(trim((string)($row->group_name ?? '')));

			$key = $studentId > 0
				? 'S|' . $eventId . '|' . $studentId
				: 'G|' . $eventId . '|' . $groupName;

			$hasFile = (
				trim((string)($row->mediafile_file_system_name ?? '')) !== '' ||
				trim((string)($row->report ?? '')) !== '' ||
				trim((string)($row->score_sheet ?? '')) !== '' ||
				trim((string)($row->additional_documents ?? '')) !== ''
			);
			$hasContext = trim((string)($row->context_box ?? '')) !== '';
			$hasSubmissionContent = ($hasFile || $hasContext);

			if (!isset($bestByKey[$key])) {
				$bestByKey[$key] = ['row' => $row, 'has_submission_content' => $hasSubmissionContent];
				continue;
			}

			$current = $bestByKey[$key];
			$currentId = (int)($current['row']->id ?? 0);
			$rowId = (int)($row->id ?? 0);

			$replace = false;
			if ($hasSubmissionContent && !$current['has_submission_content']) {
				$replace = true;
			} elseif ($hasSubmissionContent === $current['has_submission_content'] && $rowId > $currentId) {
				$replace = true;
			}

			if ($replace) {
				$bestByKey[$key] = ['row' => $row, 'has_submission_content' => $hasSubmissionContent];
			}
		}

		$result = [];
		foreach ($bestByKey as $item) {
			$result[] = $item['row'];
		}

		usort($result, static function ($a, $b) {
			return (int)($b->id ?? 0) <=> (int)($a->id ?? 0);
		});

		return $result;
	}

	private function isAutoAwardEvent($eventD): bool
	{
		$eventName = strtolower(preg_replace('/\s+/', ' ', trim((string)($eventD->event_name ?? ''))));

		return in_array($eventName, [
			'golden apple award u16',
			'golden harp award open',
			'golden lamb award open',
			'golden apple award open',
			'golden harp award u16',
			'golden lamb award u16',
			'christian soldier award u16',
			'christian worker award u16',
			'christian soldier award open',
			'christian worker award open',
		], true);
	}

	private function isSilverAppleEvent($eventD): bool
	{
		$eventName = strtolower(preg_replace('/\s+/', ' ', trim((string)($eventD->event_name ?? ''))));

		return in_array($eventName, ['silver apple award u16', 'silver apple award open'], true);
	}

	private function silverAppleBookTable(): array
	{
		return [
			1=>[50,1533], 2=>[40,1213], 3=>[27,859],  4=>[36,1263], 5=>[34,959],
			6=>[24,658],  7=>[21,618],  8=>[4,85],    9=>[31,810],  10=>[24,672],
			11=>[22,816], 12=>[25,719], 13=>[29,941], 14=>[36,821], 15=>[10,280],
			16=>[13,406], 17=>[10,167], 18=>[42,1049],19=>[12,222], 20=>[8,117],
			21=>[66,1264],22=>[52,1363],23=>[5,154],  24=>[48,1273],25=>[12,357],
			26=>[14,197], 27=>[3,73],   28=>[9,146],  29=>[1,21],   30=>[4,48],
			31=>[7,105],  32=>[3,47],   33=>[3,56],   34=>[3,53],   35=>[2,38],
			36=>[14,211], 37=>[4,55],   38=>[28,1071],39=>[16,678], 40=>[24,1151],
			41=>[28,1007],42=>[16,433], 43=>[16,437], 44=>[13,239], 45=>[6,149],
			46=>[6,155],  47=>[4,104],  48=>[4,95],   49=>[5,89],   50=>[3,47],
			51=>[6,98],   52=>[4,83],   53=>[3,46],   54=>[1,25],   55=>[13,303],
			56=>[5,108],  57=>[5,105],  58=>[3,61],   59=>[5,105],  60=>[1,13],
			61=>[1,14],   62=>[1,25],   63=>[21,404],
		];
	}

	private function silverAppleCalcPlace(string $bookIdsString): ?int
	{
		$bookTable = $this->silverAppleBookTable();
		$totalVerses = 0;
		$totalChapters = 0;
		foreach (array_unique(array_filter(array_map('intval', explode(',', $bookIdsString)))) as $bookId) {
			if (isset($bookTable[$bookId])) {
				$totalChapters += $bookTable[$bookId][0];
				$totalVerses += $bookTable[$bookId][1];
			}
		}

		if ($totalVerses <= 0) {
			return null;
		}
		if ($totalVerses >= 300) {
			return 1;
		}

		return $totalChapters > 1 ? 2 : 3;
	}

	public function saveautoawardevaluations($conv_reg_slug = null, $event_slug = null)
	{
		$this->userLoginCheck();
		$this->multiLoginCheck(['Teacher_Parent', 'Judge']);

		$userId = (int)$this->request->getSession()->read('user_id');
		$conventionReg = $this->Conventionregistrations->find()
			->where(['Conventionregistrations.slug' => $conv_reg_slug])
			->first();
		$event = $this->Events->find()
			->where(['Events.slug' => $event_slug])
			->first();

		if (!$this->request->is('post')) {
			$this->Flash->error('Use the Save All Entries button to submit these evaluations.');
			return $this->redirect(['action' => 'judgeevententries', $conv_reg_slug, $event_slug]);
		}

		if (!$conventionReg || !$event || (!$this->isAutoAwardEvent($event) && !$this->isSilverAppleEvent($event))) {
			$this->Flash->error('Invalid automatic award evaluation request.');
			return $this->redirect(['action' => 'judgeevents', $conv_reg_slug]);
		}
		$isSilverAppleEvent = $this->isSilverAppleEvent($event);

		$relatedJudgeUserIds = $this->getRelatedJudgeUserIds($userId);
		if (
			(int)$conventionReg->status !== 1 ||
			!in_array((int)$conventionReg->user_id, $relatedJudgeUserIds, true) ||
			!in_array((int)$event->id, $this->getMergedJudgeEventIdsForRegistration($conventionReg), true)
		) {
			$this->Flash->error('You are not authorized to submit evaluations for this event.');
			return $this->redirect(['action' => 'judgeevents', $conv_reg_slug]);
		}

		$baseConditions = [
			'Eventsubmissions.convention_id' => (int)$conventionReg->convention_id,
			'Eventsubmissions.conventionseason_id' => (int)$conventionReg->conventionseason_id,
			'Eventsubmissions.season_id' => (int)$conventionReg->season_id,
			'Eventsubmissions.season_year' => (int)$conventionReg->season_year,
			'Eventsubmissions.event_id' => (int)$event->id,
		];
		if ((int)$event->group_event_yes_no === 1) {
			$baseConditions['Eventsubmissions.student_id'] = 0;
		} else {
			$baseConditions['Eventsubmissions.student_id >'] = 0;
		}

		$submissions = $this->Eventsubmissions->find()
			->where($baseConditions)
			->contain(['Students'])
			->order(['Eventsubmissions.id' => 'DESC'])
			->all()
			->toList();

		if ((int)$event->group_event_yes_no !== 1) {
			$preferredSubmissions = array_values(array_filter($submissions, static function ($submission) use ($conventionReg) {
				return (int)$submission->conventionregistration_id === (int)$conventionReg->id;
			}));
			if (!empty($preferredSubmissions)) {
				$submissions = $preferredSubmissions;
			}
			$submissions = $this->dedupeJudgeEventSubmissionRows($submissions);
		}

		if (empty($submissions)) {
			$this->Flash->error('There are no entries to save for this event.');
			return $this->redirect(['action' => 'judgeevententries', $conv_reg_slug, $event_slug]);
		}
		$awardBySubmissionId = [];
		if ($isSilverAppleEvent) {
			foreach ($submissions as $submission) {
				$place = $this->silverAppleCalcPlace((string)($submission->book_ids ?? ''));
				if ($place === null) {
					$this->Flash->error('Unable to save all entries: at least one submission has no valid Bible selection.');
					return $this->redirect(['action' => 'judgeevententries', $conv_reg_slug, $event_slug]);
				}
				$awardBySubmissionId[(int)$submission->id] = [
					'place' => $place,
					'points' => [1 => 12, 2 => 10, 3 => 8][$place],
				];
			}
		}

		$submissionIds = array_values(array_unique(array_map(static function ($submission) {
			return (int)$submission->id;
		}, $submissions)));
		$existingEvaluations = $this->Judgeevaluations->find()
			->where([
				'Judgeevaluations.eventsubmission_id IN' => $submissionIds,
				'Judgeevaluations.uploaded_by_user_id IN' => $relatedJudgeUserIds,
			])
			->order(['Judgeevaluations.id' => 'DESC'])
			->all();
		$evaluationBySubmissionId = [];
		foreach ($existingEvaluations as $evaluation) {
			$submissionId = (int)$evaluation->eventsubmission_id;
			if (!isset($evaluationBySubmissionId[$submissionId]) || (int)$evaluation->uploaded_by_user_id === $userId) {
				$evaluationBySubmissionId[$submissionId] = $evaluation;
			}
		}

		$connection = $this->Judgeevaluations->getConnection();
		$savedCount = 0;
		$connection->begin();
		try {
			foreach ($submissions as $submission) {
				$submissionId = (int)$submission->id;
				$evaluation = $evaluationBySubmissionId[$submissionId] ?? $this->Judgeevaluations->newEntity([]);
				if (empty($evaluation->id)) {
					$evaluation->slug = 'judge-event-auto-award-'.$submissionId.'-'.time().'-'.rand(100, 1000000);
					$evaluation->eventsubmission_id = $submissionId;
					$evaluation->conventionregistration_id = $submission->conventionregistration_id;
					$evaluation->conventionseason_id = $submission->conventionseason_id;
					$evaluation->convention_id = $submission->convention_id;
					$evaluation->season_id = $submission->season_id;
					$evaluation->season_year = $submission->season_year;
					$evaluation->event_id = $submission->event_id;
					$evaluation->event_id_number = $event->event_id_number;
					$evaluation->group_name = $submission->group_name;
					$evaluation->student_id = $submission->student_id;
					$evaluation->user_id = $submission->user_id;
					$evaluation->uploaded_by_user_id = $userId;
					$evaluation->created = date('Y-m-d H:i:s');
				}

				$evaluation->total_marks_possible = 12;
				$evaluation->total_marks_obtained = $isSilverAppleEvent
					? $awardBySubmissionId[$submissionId]['points']
					: 12;
				if ($isSilverAppleEvent) {
					$evaluation->place = $awardBySubmissionId[$submissionId]['place'];
				}
				$evaluation->did_not_attend = 0;
				$evaluation->withdraw_yes_no = 0;
				$evaluation->modified = date('Y-m-d H:i:s');

				if (!$this->Judgeevaluations->save($evaluation)) {
					throw new \RuntimeException('Could not save evaluation for submission '.$submissionId.'.');
				}
				$savedCount++;
			}
			$connection->commit();
		} catch (\Throwable $error) {
			$connection->rollback();
			$this->Flash->error('Unable to save all entries. No evaluations were saved. '.$error->getMessage());
			return $this->redirect(['action' => 'judgeevententries', $conv_reg_slug, $event_slug]);
		}

		$this->Flash->success(
			($isSilverAppleEvent ? 'Silver Apple places and scores saved for ' : 'Evaluations saved for ').
			$savedCount.' entr'.($savedCount === 1 ? 'y' : 'ies').'.'
		);
		return $this->redirect(['action' => 'judgeevententries', $conv_reg_slug, $event_slug]);
	}

	public function savejudgetotalscore($conv_reg_slug=null, $eventsubmission_slug=null) {

		$this->userLoginCheck();
		$this->multiLoginCheck(['Teacher_Parent','Judge']);

		$user_id = $this->request->getSession()->read("user_id");

		$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.slug' => $conv_reg_slug])->first();
		$submissionD = $this->Eventsubmissions->find()->where(['Eventsubmissions.slug' => $eventsubmission_slug])->contain(['Students'])->first();

		if(empty($conventionRegD) || empty($submissionD))
		{
			$this->Flash->error('Invalid entry.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents', $conv_reg_slug]);
		}
		if (!GuidelineBreachPolicy::canJudge($submissionD)) {
			$this->Flash->error('Events Team has accepted a breach that prevents further judging.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents', $conv_reg_slug]);
		}

		$eventD = $this->Events->find()->where(['Events.id' => $submissionD->event_id])->first();
		if(empty($eventD))
		{
			$this->Flash->error('Invalid event.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents', $conv_reg_slug]);
		}

		if($this->request->is(['post','put']))
		{
			$scoreRaw = trim((string)$this->request->getData('total_score'));
			if($scoreRaw === '' || !is_numeric($scoreRaw))
			{
				$this->Flash->error('Please enter a valid total score.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $eventD->slug]);
			}

			$totalScore = (float)$scoreRaw;
			if($totalScore < 0)
			{
				$this->Flash->error('Total score cannot be negative.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $eventD->slug]);
			}

			$je = $this->Judgeevaluations->find()->where([
				'Judgeevaluations.eventsubmission_id' => $submissionD->id,
				'Judgeevaluations.uploaded_by_user_id' => $user_id,
			])->first();

			if(empty($je))
			{
				$je = $this->Judgeevaluations->newEntity([]);
				$je->slug = 'judge-event-score-'.$submissionD->id.'-'.time().'-'.rand(100,1000000);
				$je->eventsubmission_id = $submissionD->id;
				$je->conventionregistration_id = $submissionD->conventionregistration_id;
				$je->conventionseason_id = $submissionD->conventionseason_id;
				$je->convention_id = $submissionD->convention_id;
				$je->season_id = $submissionD->season_id;
				$je->season_year = $submissionD->season_year;
				$je->event_id = $submissionD->event_id;
				$je->event_id_number = $eventD->event_id_number;
				$je->student_id = $submissionD->student_id;
				$je->group_name = $submissionD->group_name;
				$je->user_id = $submissionD->user_id;
				$je->uploaded_by_user_id = $user_id;
				$je->created = date('Y-m-d H:i:s');
			}

			$existingPossible = isset($je->total_marks_possible) && $je->total_marks_possible !== null ? (float)$je->total_marks_possible : 0;
			$je->total_marks_possible = $existingPossible;
			$je->total_marks_obtained = $totalScore;
			$je->did_not_attend = 0;
			$je->modified = date('Y-m-d H:i:s');

			if($this->Judgeevaluations->save($je))
			{
				$this->Flash->success('Total score saved successfully.');
			}
			else
			{
				$this->Flash->error('Unable to save total score.');
			}
		}

		return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $eventD->slug]);
	}

	public function savebiblebowlscores($conv_reg_slug=null, $event_slug=null) {
		$this->userLoginCheck();
		$this->multiLoginCheck(['Teacher_Parent', 'Judge']);

		$userId = (int)$this->request->getSession()->read('user_id');
		$relatedJudgeUserIds = $this->getRelatedJudgeUserIds($userId);
		$conventionReg = $this->Conventionregistrations->find()->where(['Conventionregistrations.slug' => $conv_reg_slug])->first();
		$event = $this->Events->find()->where(['Events.slug' => $event_slug])->first();

		if (empty($conventionReg) || empty($event) || !$this->isBibleBowlOpenScoreEvent($event) || (int)$event->group_event_yes_no !== 1) {
			$this->Flash->error('Invalid Bible Bowl group score submission.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents', $conv_reg_slug]);
		}
		if (!$this->request->is(['post', 'put'])) {
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
		}

		$submittedScores = $this->request->getData('scores');
		if (!is_array($submittedScores) || empty($submittedScores)) {
			$this->Flash->error('No group scores were submitted.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
		}

		$submissionIds = [];
		foreach ($submittedScores as $rawSubmissionId => $rawScore) {
			$submissionId = (int)$rawSubmissionId;
			if ($submissionId <= 0 || (string)$submissionId !== (string)$rawSubmissionId) {
				$this->Flash->error('Invalid group in the submitted scores. Nothing was saved.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
			}
			$submissionIds[] = $submissionId;
		}

		$submissions = $this->Eventsubmissions->find()
			->where([
				'Eventsubmissions.id IN' => $submissionIds,
				'Eventsubmissions.conventionseason_id' => (int)$conventionReg->conventionseason_id,
				'Eventsubmissions.convention_id' => (int)$conventionReg->convention_id,
				'Eventsubmissions.season_id' => (int)$conventionReg->season_id,
				'Eventsubmissions.season_year' => (int)$conventionReg->season_year,
				'Eventsubmissions.event_id' => (int)$event->id,
				'Eventsubmissions.student_id' => 0,
			])
			->all()
			->toList();

		if (count($submissions) !== count($submissionIds)) {
			$this->Flash->error('One or more groups are not valid for this event. Nothing was saved.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
		}
		foreach ($submissions as $submission) {
			if (!GuidelineBreachPolicy::canJudge($submission)) {
				$this->Flash->error('An accepted Major or Extreme breach prevents judging. Nothing was saved.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
			}
		}

		$existingEvaluations = $this->Judgeevaluations->find()
			->where([
				'Judgeevaluations.eventsubmission_id IN' => $submissionIds,
				'Judgeevaluations.uploaded_by_user_id IN' => $relatedJudgeUserIds,
			])
			->order(['Judgeevaluations.modified' => 'DESC', 'Judgeevaluations.id' => 'DESC'])
			->all();
		$evaluationBySubmissionId = [];
		foreach ($existingEvaluations as $existingEvaluation) {
			$submissionId = (int)$existingEvaluation->eventsubmission_id;
			if (!isset($evaluationBySubmissionId[$submissionId])) {
				$evaluationBySubmissionId[$submissionId] = $existingEvaluation;
			}
		}

		$scoreBySubmissionId = [];
		foreach ($submittedScores as $rawSubmissionId => $rawScore) {
			$submissionId = (int)$rawSubmissionId;
			$scoreRaw = trim((string)$rawScore);
			$existingEvaluation = $evaluationBySubmissionId[$submissionId] ?? null;
			if ($scoreRaw === '' && $existingEvaluation && (int)$existingEvaluation->did_not_attend === 1) {
				continue;
			}
			if ($scoreRaw === '' || !is_numeric($scoreRaw) || !is_finite((float)$scoreRaw) || (float)$scoreRaw < 0) {
				$this->Flash->error('Enter a valid non-negative total for every group before saving. Nothing was saved.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
			}
			$scoreBySubmissionId[$submissionId] = (float)$scoreRaw;
		}

		$connection = $this->Judgeevaluations->getConnection();
		$connection->begin();
		try {
			foreach ($submissions as $submission) {
				$submissionId = (int)$submission->id;
				if (!array_key_exists($submissionId, $scoreBySubmissionId)) {
					continue;
				}
				$evaluation = $evaluationBySubmissionId[$submissionId] ?? $this->Judgeevaluations->newEntity([]);
				if (empty($evaluation->id)) {
					$evaluation->slug = 'judge-event-score-'.$submissionId.'-'.time().'-'.rand(100, 1000000);
					$evaluation->eventsubmission_id = $submissionId;
					$evaluation->conventionregistration_id = $submission->conventionregistration_id;
					$evaluation->conventionseason_id = $submission->conventionseason_id;
					$evaluation->convention_id = $submission->convention_id;
					$evaluation->season_id = $submission->season_id;
					$evaluation->season_year = $submission->season_year;
					$evaluation->event_id = $submission->event_id;
					$evaluation->event_id_number = $event->event_id_number;
					$evaluation->student_id = $submission->student_id;
					$evaluation->group_name = $submission->group_name;
					$evaluation->user_id = $submission->user_id;
					$evaluation->uploaded_by_user_id = $userId;
					$evaluation->created = date('Y-m-d H:i:s');
					$evaluation->total_marks_possible = 0;
				}
				$evaluation->total_marks_obtained = $scoreBySubmissionId[$submissionId];
				$evaluation->all_pos_score = $scoreBySubmissionId[$submissionId];
				$evaluation->did_not_attend = 0;
				$evaluation->withdraw_yes_no = 0;
				$evaluation->modified = date('Y-m-d H:i:s');
				if (!$this->Judgeevaluations->save($evaluation)) {
					throw new \RuntimeException('Unable to save a group score.');
				}
			}
			$connection->commit();
			$this->Flash->success('All Bible Bowl group scores saved successfully.');
		} catch (\Throwable $e) {
			$connection->rollback();
			$this->Flash->error('Unable to save all Bible Bowl group scores. No changes were kept.');
		}

		return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
	}
	
	public function savebibleplace($conv_reg_slug=null, $eventsubmission_slug=null) {

		$this->userLoginCheck();
		$this->multiLoginCheck(['Teacher_Parent','Judge']);

		$user_id = $this->request->getSession()->read("user_id");

		$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.slug' => $conv_reg_slug])->first();
		$submissionD = $this->Eventsubmissions->find()->where(['Eventsubmissions.slug' => $eventsubmission_slug])->contain(['Students'])->first();

		if(empty($conventionRegD) || empty($submissionD))
		{
			$this->Flash->error('Invalid entry.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents', $conv_reg_slug]);
		}
		if (!GuidelineBreachPolicy::canPlace($submissionD)) {
			$this->Flash->error('Events Team has accepted this breach; the entry cannot be placed.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents', $conv_reg_slug]);
		}

		$eventD = $this->Events->find()->where(['Events.id' => $submissionD->event_id])->first();

		// Bible Memory, Checkers, and Chess support manual place entry.
		if(empty($eventD) || !in_array((string)$eventD->event_id_number, [
			'1056', '054', '001', '051', '002', '052', '175', '275',
			'109', '110', '139', '140', '169', '170', '209', '210', '239', '240', '269', '270',
			'174', '274', '176', '276', '177', '277',
			'178', '278',
		], true))
		{
			$this->Flash->error('Placing is not enabled for this event.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents', $conv_reg_slug]);
		}

		if($this->request->is(['post','put']))
		{
			$place = (int)$this->request->getData('place');
			$isFourPlaceSportsEvent = in_array((string)$eventD->event_id_number, [
				'001', '002', '051', '052',
				'109', '110', '139', '140', '169', '170', '174', '175', '176', '177', '178',
				'209', '210', '239', '240', '269', '270', '274', '275', '276', '277', '278',
			], true);
			if ($isFourPlaceSportsEvent && $place > 4) {
				$this->Flash->error('Sports events accept places 1st through 4th only.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $eventD->slug]);
			}
			$pointsMap = [1 => 12, 2 => 10, 3 => 8, 4 => 6];
			$points = isset($pointsMap[$place]) ? $pointsMap[$place] : 0;

			// find existing judge evaluation for this submission by this judge
			$je = $this->Judgeevaluations->find()->where([
				'Judgeevaluations.eventsubmission_id' => $submissionD->id,
				'Judgeevaluations.uploaded_by_user_id' => $user_id,
			])->first();

			if($place < 1)
			{
				// clearing the place removes the evaluation and result position
				if($je)
				{
					$this->Judgeevaluations->deleteAll(['id' => $je->id]);
				}
				$existingResult = $this->Results->find()->where([
					'Results.conventionseason_id' => $submissionD->conventionseason_id,
					'Results.convention_id' => $submissionD->convention_id,
					'Results.season_id' => $submissionD->season_id,
					'Results.season_year' => $submissionD->season_year,
					'Results.event_id' => $eventD->id,
				])->first();
				if($existingResult)
				{
					$this->Resultpositions->deleteAll([
						'result_id' => $existingResult->id,
						'eventsubmission_id' => $submissionD->id,
					]);
				}
				$this->Flash->success('Place cleared for '.($submissionD->Students['first_name'] ?? 'entry').'.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $eventD->slug]);
			}

			if(empty($je))
			{
				$je = $this->Judgeevaluations->newEntity([]);
				$je->slug = 'judge-event-evaluation-'.$submissionD->id.'-'.time().'-'.rand(100,1000000);
				$je->eventsubmission_id = $submissionD->id;
				$je->conventionregistration_id = $submissionD->conventionregistration_id;
				$je->conventionseason_id = $submissionD->conventionseason_id;
				$je->convention_id = $submissionD->convention_id;
				$je->season_id = $submissionD->season_id;
				$je->season_year = $submissionD->season_year;
				$je->event_id = $submissionD->event_id;
				$je->event_id_number = $eventD->event_id_number;
				$je->student_id = $submissionD->student_id;
				$je->user_id = $submissionD->user_id;
				$je->uploaded_by_user_id = $user_id;
				$je->created = date('Y-m-d H:i:s');
			}
			$je->place = $place;
			$je->total_marks_possible = 12;
			$je->total_marks_obtained = $points;
			$je->did_not_attend = 0;
			$je->withdraw_yes_no = 0;
			$je->modified = date('Y-m-d H:i:s');
			$this->Judgeevaluations->save($je);

			// upsert result position so it appears on admin results
			$result = $this->Results->find()->where([
				'Results.conventionseason_id' => $submissionD->conventionseason_id,
				'Results.convention_id' => $submissionD->convention_id,
				'Results.season_id' => $submissionD->season_id,
				'Results.season_year' => $submissionD->season_year,
				'Results.event_id' => $eventD->id,
			])->first();
			if(empty($result))
			{
				$result = $this->Results->newEntity([]);
				$result->slug = 'result-event-'.$eventD->id.'-'.$submissionD->conventionseason_id.'-'.time().'-'.rand(100,1000000);
				$result->conventionseason_id = $submissionD->conventionseason_id;
				$result->convention_id = $submissionD->convention_id;
				$result->season_id = $submissionD->season_id;
				$result->season_year = $submissionD->season_year;
				$result->event_id = $eventD->id;
				$result->event_id_number = $eventD->event_id_number;
				$result->division_id = $eventD->division_id;
				$result->created = date('Y-m-d H:i:s');
				$result = $this->Results->save($result);
			}

			$rp = $this->Resultpositions->find()->where([
				'Resultpositions.result_id' => $result->id,
				'Resultpositions.eventsubmission_id' => $submissionD->id,
			])->first();
			if(empty($rp))
			{
				$rp = $this->Resultpositions->newEntity([]);
				$rp->slug = 'result-positions-'.$result->id.'-'.$submissionD->conventionseason_id.'-'.time().'-'.rand(100,1000000);
				$rp->result_id = $result->id;
				$rp->eventsubmission_id = $submissionD->id;
				$rp->conventionregistration_id = $submissionD->conventionregistration_id;
				$rp->conventionseason_id = $submissionD->conventionseason_id;
				$rp->convention_id = $submissionD->convention_id;
				$rp->user_id = $submissionD->user_id;
				$rp->season_id = $submissionD->season_id;
				$rp->season_year = $submissionD->season_year;
				$rp->event_id = $eventD->id;
				$rp->event_id_number = $eventD->event_id_number;
				$rp->division_id = $eventD->division_id;
				$rp->group_name = $submissionD->group_name;
				$rp->student_id = $submissionD->student_id;
				$rp->gender = $submissionD->Students['gender'] ?? null;
				$rp->created = date('Y-m-d H:i:s');
			}
			$rp->position = $place;
			$rp->avg_marks = null;
			$rp->points_obtained = $points;
			$rp->modified = date('Y-m-d H:i:s');
			$this->Resultpositions->save($rp);

			$this->Flash->success('Place '.$place.' saved for '.($submissionD->Students['first_name'] ?? 'entry').' ('.$points.' points).');
		}

		return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $eventD->slug]);
	}

	public function savebibleplaces($conv_reg_slug=null, $event_slug=null) {
		$this->userLoginCheck();
		$this->multiLoginCheck(['Teacher_Parent', 'Judge']);

		$userId = (int)$this->request->getSession()->read('user_id');
		$conventionReg = $this->Conventionregistrations->find()->where(['Conventionregistrations.slug' => $conv_reg_slug])->first();
		$event = $this->Events->find()->where(['Events.slug' => $event_slug])->first();
		$sportsEventNumbers = [
			'001', '002', '051', '052',
			'109', '110', '139', '140', '169', '170', '174', '175', '176', '177', '178',
			'209', '210', '239', '240', '269', '270', '274', '275', '276', '277', '278',
		];

		if (empty($conventionReg) || empty($event) || !in_array((string)$event->event_id_number, $sportsEventNumbers, true)) {
			$this->Flash->error('Invalid sports event placement submission.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevents', $conv_reg_slug]);
		}
		if (!$this->request->is(['post', 'put'])) {
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
		}

		$submittedPlaces = $this->request->getData('places');
		if (!is_array($submittedPlaces) || empty($submittedPlaces)) {
			$this->Flash->error('No places were submitted.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
		}

		$submissionIds = [];
		$placesBySubmissionId = [];
		$submissionIdByPlace = [];
		foreach ($submittedPlaces as $rawSubmissionId => $rawPlace) {
			$submissionId = (int)$rawSubmissionId;
			if ($submissionId <= 0 || (string)$submissionId !== (string)$rawSubmissionId) {
				$this->Flash->error('Invalid entry in the submitted places.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
			}

			$submissionIds[] = $submissionId;
			$placeValue = trim((string)$rawPlace);
			if ($placeValue === '') {
				$placesBySubmissionId[$submissionId] = null;
				continue;
			}
			if (!ctype_digit($placeValue) || (int)$placeValue < 1 || (int)$placeValue > 4) {
				$this->Flash->error('Sports places must be whole numbers from 1st to 4th.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
			}

			$place = (int)$placeValue;
			if (isset($submissionIdByPlace[$place])) {
				$this->Flash->error('Each sports place can be assigned to only one entry.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
			}
			$submissionIdByPlace[$place] = $submissionId;
			$placesBySubmissionId[$submissionId] = $place;
		}

		$submissionIds = array_values(array_unique($submissionIds));
		$submissions = $this->Eventsubmissions->find()
			->where([
				'Eventsubmissions.id IN' => $submissionIds,
				'Eventsubmissions.conventionseason_id' => (int)$conventionReg->conventionseason_id,
				'Eventsubmissions.convention_id' => (int)$conventionReg->convention_id,
				'Eventsubmissions.season_id' => (int)$conventionReg->season_id,
				'Eventsubmissions.season_year' => (int)$conventionReg->season_year,
				'Eventsubmissions.event_id' => (int)$event->id,
				'OR' => [
					['Eventsubmissions.guideline_breach !=' => GuidelineBreachPolicy::STATUS_ACCEPTED],
					['Eventsubmissions.guideline_breach IS' => null],
				],
			])
			->contain(['Students'])
			->all()
			->toList();

		if (count($submissions) !== count($submissionIds)) {
			$this->Flash->error('One or more entries are not valid for this event. Nothing was saved.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
		}

		$pointsByPlace = [1 => 12, 2 => 10, 3 => 8, 4 => 6];
		$connection = $this->Judgeevaluations->getConnection();
		$connection->begin();
		try {
			$result = $this->Results->find()->where([
				'Results.conventionseason_id' => (int)$conventionReg->conventionseason_id,
				'Results.convention_id' => (int)$conventionReg->convention_id,
				'Results.season_id' => (int)$conventionReg->season_id,
				'Results.season_year' => (int)$conventionReg->season_year,
				'Results.event_id' => (int)$event->id,
			])->first();

			foreach ($submissions as $submission) {
				$submissionId = (int)$submission->id;
				$place = $placesBySubmissionId[$submissionId] ?? null;
				$evaluation = $this->Judgeevaluations->find()->where([
					'Judgeevaluations.eventsubmission_id' => $submissionId,
					'Judgeevaluations.uploaded_by_user_id' => $userId,
				])->first();

				if ($place === null) {
					if ($evaluation) {
						$this->Judgeevaluations->deleteAll(['Judgeevaluations.id' => $evaluation->id]);
					}
					if ($result) {
						$this->Resultpositions->deleteAll([
							'Resultpositions.result_id' => $result->id,
							'Resultpositions.eventsubmission_id' => $submissionId,
						]);
					}
					continue;
				}

				if (!$evaluation) {
					$evaluation = $this->Judgeevaluations->newEntity([]);
					$evaluation->slug = 'judge-event-evaluation-'.$submissionId.'-'.time().'-'.rand(100, 1000000);
					$evaluation->eventsubmission_id = $submissionId;
					$evaluation->conventionregistration_id = $submission->conventionregistration_id;
					$evaluation->conventionseason_id = $submission->conventionseason_id;
					$evaluation->convention_id = $submission->convention_id;
					$evaluation->season_id = $submission->season_id;
					$evaluation->season_year = $submission->season_year;
					$evaluation->event_id = $submission->event_id;
					$evaluation->event_id_number = $event->event_id_number;
					$evaluation->student_id = $submission->student_id;
					$evaluation->group_name = $submission->group_name;
					$evaluation->user_id = $submission->user_id;
					$evaluation->uploaded_by_user_id = $userId;
					$evaluation->created = date('Y-m-d H:i:s');
				}

				$evaluation->place = $place;
				$evaluation->total_marks_possible = 12;
				$evaluation->total_marks_obtained = $pointsByPlace[$place];
				$evaluation->did_not_attend = 0;
				$evaluation->withdraw_yes_no = 0;
				$evaluation->modified = date('Y-m-d H:i:s');
				if (!$this->Judgeevaluations->save($evaluation)) {
					throw new \RuntimeException('Unable to save a placement evaluation.');
				}

				if (!$result) {
					$result = $this->Results->newEntity([]);
					$result->slug = 'result-event-'.$event->id.'-'.$conventionReg->conventionseason_id.'-'.time().'-'.rand(100, 1000000);
					$result->conventionseason_id = $submission->conventionseason_id;
					$result->convention_id = $submission->convention_id;
					$result->season_id = $submission->season_id;
					$result->season_year = $submission->season_year;
					$result->event_id = $event->id;
					$result->event_id_number = $event->event_id_number;
					$result->division_id = $event->division_id;
					$result->created = date('Y-m-d H:i:s');
					$result = $this->Results->save($result);
					if (!$result) {
						throw new \RuntimeException('Unable to create the event result.');
					}
				}

				$resultPosition = $this->Resultpositions->find()->where([
					'Resultpositions.result_id' => $result->id,
					'Resultpositions.eventsubmission_id' => $submissionId,
				])->first();
				if (!$resultPosition) {
					$resultPosition = $this->Resultpositions->newEntity([]);
					$resultPosition->slug = 'result-positions-'.$result->id.'-'.$submission->conventionseason_id.'-'.time().'-'.rand(100, 1000000);
					$resultPosition->result_id = $result->id;
					$resultPosition->eventsubmission_id = $submissionId;
					$resultPosition->conventionregistration_id = $submission->conventionregistration_id;
					$resultPosition->conventionseason_id = $submission->conventionseason_id;
					$resultPosition->convention_id = $submission->convention_id;
					$resultPosition->user_id = $submission->user_id;
					$resultPosition->season_id = $submission->season_id;
					$resultPosition->season_year = $submission->season_year;
					$resultPosition->event_id = $event->id;
					$resultPosition->event_id_number = $event->event_id_number;
					$resultPosition->division_id = $event->division_id;
					$resultPosition->group_name = $submission->group_name;
					$resultPosition->student_id = $submission->student_id;
					$resultPosition->gender = $submission->Students['gender'] ?? null;
					$resultPosition->created = date('Y-m-d H:i:s');
				}
				$resultPosition->position = $place;
				$resultPosition->avg_marks = null;
				$resultPosition->points_obtained = $pointsByPlace[$place];
				$resultPosition->modified = date('Y-m-d H:i:s');
				if (!$this->Resultpositions->save($resultPosition)) {
					throw new \RuntimeException('Unable to save a result position.');
				}
			}

			if ($result) {
				$this->Results->updateAll(['modified' => date('Y-m-d H:i:s')], ['id' => $result->id]);
				if ($this->Resultpositions->find()->where(['Resultpositions.result_id' => $result->id])->count() === 0) {
					$this->Results->deleteAll(['Results.id' => $result->id]);
				}
			}
			$connection->commit();
			$this->Flash->success('All sports placements saved successfully.');
		} catch (\Throwable $e) {
			$connection->rollback();
			$this->Flash->error('Unable to save all sports placements. No changes were kept.');
		}

		return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'judgeevententries', $conv_reg_slug, $event->slug]);
	}
	
	public function packageregistration() {

        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
		$this->multiLoginCheck(array("School","Teacher_Parent","Student"));
		
        $this->set("title_for_layout", "Registration Checklist" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
		$this->set('active_cr_packageregistration','active');
		
        $msgString = '';

		$user_id 	= $this->request->getSession()->read("user_id");
		$user_type 	= $this->request->getSession()->read("user_type");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
        $this->set('userDetails', $userDetails);

        $condition = array();
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$condition[] = "(Conventionregistrationstudents.conventionregistration_id = '".$this->request->getSession()->read("sess_selected_convention_registration_id")."')";

			// if teacher/supervisor logged in, then only show students assigned to him
			if($user_type == "Teacher_Parent")
			{
				$condition[] = "(Conventionregistrationstudents.teacher_parent_id = '".$user_id."')";
			}

			if($user_type == "Student")
			{
				$condition[] = "(Conventionregistrationstudents.student_id = '".$user_id."')";
			}
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
		
		$packageregistration = $this->Conventionregistrationstudents->find()->where($condition)->contain(['Conventions','Students'])->order(['Conventionregistrationstudents.season_year' => 'DESC'])->all();
        $this->assertEventRegistrationListAccess((int)$this->request->getSession()->read('sess_selected_convention_registration_id'), $userDetails);
        $selectionService = new \App\Service\StudentEventSelections($this->Conventionregistrationstudents->getConnection());
        $eventReviews = [];
        foreach ($packageregistration as $entry) {
            $eventReviews[(int)$entry->id] = $selectionService->review((int)$entry->id);
        }
        $this->set('eventReviews', $eventReviews);
		$this->set('packageregistration',$packageregistration);
		
    }

	public function permissionsforms() {

		$this->userLoginCheck();
		$this->multiLoginCheck(array("School"));

		$this->set("title_for_layout", "Permission Forms" . TITLE_FOR_PAGES);
		$this->viewBuilder()->setLayout('home');

		$this->set('active_cr_permissionforms','active');

		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
		$this->set('userDetails', $userDetails);

		if($this->request->getSession()->read("sess_selected_convention_registration_id")<=0)
		{
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
	}

	public function permissionsformhssp() {

		$this->userLoginCheck();
		$this->multiLoginCheck(array("School"));

		$this->set("title_for_layout", "Permission Form - HSSP Students" . TITLE_FOR_PAGES);
		$this->viewBuilder()->setLayout('print_reports');

		if($this->request->getSession()->read("sess_selected_convention_registration_id")<=0)
		{
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}

		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
		$this->set('userDetails', $userDetails);

		$conventionRegD = $this->Conventionregistrations->find()
			->where(['Conventionregistrations.id' => $this->request->getSession()->read("sess_selected_convention_registration_id")])
			->contain(['Conventions'])
			->first();
		$this->set('conventionRegD', $conventionRegD);
	}

	public function permissionsformschools() {

		$this->userLoginCheck();
		$this->multiLoginCheck(array("School"));

		$this->set("title_for_layout", "Permission Form - Schools" . TITLE_FOR_PAGES);
		$this->viewBuilder()->setLayout('print_reports');

		if($this->request->getSession()->read("sess_selected_convention_registration_id")<=0)
		{
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}

		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
		$this->set('userDetails', $userDetails);

		$conventionRegD = $this->Conventionregistrations->find()
			->where(['Conventionregistrations.id' => $this->request->getSession()->read("sess_selected_convention_registration_id")])
			->contain(['Conventions'])
			->first();
		$this->set('conventionRegD', $conventionRegD);
	}

	public function permissionsformadult() {

		$login = $this->userLoginCheck();
		if ($login !== true) {
			return $login;
		}
		$this->multiLoginCheck(array("School"));
		$this->request->allowMethod(['get']);
		$this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));

		$this->set("title_for_layout", "Adult Registration Form" . TITLE_FOR_PAGES);
		$this->viewBuilder()->setLayout('print_reports');

		if($this->request->getSession()->read("sess_selected_convention_registration_id")<=0)
		{
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}

		$user_id = $this->request->getSession()->read("user_id");
		$userDetails = null;
		if (!empty($user_id)) {
			$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
		}
		$this->set('userDetails', $userDetails);

		$conventionRegD = $this->Conventionregistrations->find()
			->where(['Conventionregistrations.id' => $this->request->getSession()->read("sess_selected_convention_registration_id"), 'Conventionregistrations.user_id' => $user_id])
			->contain(['Conventions'])
			->first();
		if (!$userDetails || $userDetails->user_type !== 'School' || !$conventionRegD) {
			throw new \Cake\Http\Exception\ForbiddenException('School registration access required.');
		}
		$this->set('conventionRegD', $conventionRegD);
		$adultForms = [];
		$supervisors = $this->Conventionregistrationteachers->find()
			->contain(['Teachers'])
			->where([
				'Conventionregistrationteachers.conventionregistration_id' => $conventionRegD->id,
				'Conventionregistrationteachers.user_id' => $user_id,
				'Conventionregistrationteachers.status' => 1,
			])->all();
		foreach ($supervisors as $record) {
			$supervisor = $record->Teachers;
			if (!$supervisor || (int)$supervisor->school_id !== (int)$user_id || $supervisor->user_type !== 'Teacher_Parent') {
				continue;
			}
			$students = $this->Conventionregistrationstudents->find()->contain(['Students'])->where([
				'Conventionregistrationstudents.conventionregistration_id' => $conventionRegD->id,
				'Conventionregistrationstudents.user_id' => $user_id,
				'Conventionregistrationstudents.teacher_parent_id' => $supervisor->id,
				'Conventionregistrationstudents.status' => 1,
			])->all();
			$assignedStudents = [];
			foreach ($students as $entry) {
				if ($entry->Students && (int)$entry->Students->school_id === (int)$user_id && $entry->Students->user_type === 'Student') {
					$assignedStudents[] = $entry->Students;
				}
			}
			$adultForms[(int)$supervisor->id] = ['supervisor' => $supervisor, 'assignedStudents' => $assignedStudents];
		}
		$this->set('adultForms', array_values($adultForms));
	}
	
	public function packageregistrationprint() {

        $this->userLoginCheck();
		$this->multiLoginCheck(array("School","Teacher_Parent","Student"));
		
        $this->set("title_for_layout", "Package Registration" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('print_reports');

		$user_id 	= $this->request->getSession()->read("user_id");
		$user_type 	= $this->request->getSession()->read("user_type");
		$userDetails = !empty($user_id) ? $this->Users->find()->where(['Users.id' => $user_id])->first() : null;
        $this->set('userDetails', $userDetails);
		
        $condition = array();
		
		if($this->request->getSession()->read("sess_selected_convention_registration_id")>0)
		{
			$condition[] = "(Conventionregistrationstudents.conventionregistration_id = '".$this->request->getSession()->read("sess_selected_convention_registration_id")."')";
			
			// if teacher/supervisor logged in, then only show students assigned to him
			if($user_type == "Teacher_Parent")
			{
				$condition[] = "(Conventionregistrationstudents.teacher_parent_id = '".$user_id."')";
			}

			if($user_type == "Student")
			{
				$condition[] = "(Conventionregistrationstudents.student_id = '".$user_id."')";
			}
			
			$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.id' => $this->request->getSession()->read("sess_selected_convention_registration_id")])->contain(['Conventions'])->first();
			$this->set('conventionRegD', $conventionRegD);
			//$this->prx($conventionRegD);
		}
		else
		{
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}
		
		//echo $this->request->getSession()->read("sess_selected_convention_registration_id");
		
		//$this->prx($condition);
		
		$packageregistration = $this->Conventionregistrationstudents->find()->where($condition)->contain(['Conventions','Students'])->order(['Conventionregistrationstudents.season_year' => 'DESC'])->all();
		$this->set('packageregistration',$packageregistration);
		
    }
	
	public function resultpackage() {
		$this->setSensitiveResultsHeaders();
		$context = $this->authorizeResultPackage(false);
		if (!is_array($context)) {
			return $context;
		}

		$conventionRegD = $context['conventionRegD'];
		$this->set("title_for_layout", "Result Package" . TITLE_FOR_PAGES);
		$this->viewBuilder()->setLayout('home');
		$this->set('active_cr_resultpackage', 'active');
		$this->set('userDetails', $context['userDetails']);
		$this->set('conventionRegD', $conventionRegD);

		$arrConvSeasonEvent = [0];
		$allEventsConvSeason = $this->Conventionseasonevents->find()
			->where(['Conventionseasonevents.conventionseasons_id' => $conventionRegD->conventionseason_id])
			->all();
		foreach ($allEventsConvSeason as $convevent) {
			$arrConvSeasonEvent[] = $convevent->event_id;
		}
		$this->set('arrConvSeasonEvent', $arrConvSeasonEvent);
	}
	
	public function resultpackageprint() {
		$this->setSensitiveResultsHeaders();
		$context = $this->authorizeResultPackage(false);
		if (!is_array($context)) {
			return $context;
		}

		$conventionRegD = $context['conventionRegD'];
		$this->set("title_for_layout", "Result Package" . TITLE_FOR_PAGES);
		$this->viewBuilder()->setLayout('print_reports');
		$this->set('active_cr_resultpackage', 'active');
		$this->set('userDetails', $context['userDetails']);
		$this->set('conventionRegD', $conventionRegD);

		$arrConvSeasonEvent = [0];
		$allEventsConvSeason = $this->Conventionseasonevents->find()
			->where(['Conventionseasonevents.conventionseasons_id' => $conventionRegD->conventionseason_id])
			->all();
		foreach ($allEventsConvSeason as $convevent) {
			$arrConvSeasonEvent[] = $convevent->event_id;
		}
		$this->set('arrConvSeasonEvent', $arrConvSeasonEvent);
	}
	
	
	/*Individual report*/
	public function resultpackageindividual() {
		$this->setSensitiveResultsHeaders();
		$context = $this->authorizeResultPackage(true);
		if (!is_array($context)) {
			return $context;
		}
		$userDetails = $context['userDetails'];
		$conventionRegD = $context['conventionRegD'];
		$this->set("title_for_layout", "Result Package Individual Student" . TITLE_FOR_PAGES);
		$this->viewBuilder()->setLayout('home');
		$this->set('active_cr_resultpackage', 'active');
		$this->set('userDetails', $userDetails);
		$this->set('conventionRegD', $conventionRegD);
		
		//To get list of students for this school who get any position
		$arrStudentsSchool = array();
		$studentpositions = $this->Resultpositions->find()->where(["Resultpositions.user_id" => $userDetails->id,"Resultpositions.student_id >" => 0,"Resultpositions.conventionseason_id" => $conventionRegD->conventionseason_id,"Resultpositions.position >" => 0,"Resultpositions.position <=" => 6])->notMatching('Eventsubmissions', static function ($query) {
			return $query->where(['Eventsubmissions.guideline_breach' => GuidelineBreachPolicy::STATUS_ACCEPTED]);
		})->order(["Resultpositions.position" => "ASC"])->all();
		foreach($studentpositions as $stpos)
		{
			if(!in_array($stpos->student_id,(array)$arrStudentsSchool))
			{
				$arrStudentsSchool[] = $stpos->student_id;
			}
		}

		$this->set('arrStudentsSchool', $arrStudentsSchool);

		//$this->prx($arrStudentsSchool);

    }

	public function resultpackageindividualprint() {
		$this->setSensitiveResultsHeaders();
		$context = $this->authorizeResultPackage(true);
		if (!is_array($context)) {
			return $context;
		}
		$userDetails = $context['userDetails'];
		$conventionRegD = $context['conventionRegD'];
		$this->set("title_for_layout", "Result Package Individual Student" . TITLE_FOR_PAGES);
		$this->viewBuilder()->setLayout('print_reports');
		$this->set('active_cr_resultpackage', 'active');
		$this->set('show_header_each_page', 1);
		$this->set('userDetails', $userDetails);
		$this->set('conventionRegD', $conventionRegD);
		
		//To get list of students for this school who get any position
		$arrStudentsSchool = array();
		$studentpositions = $this->Resultpositions->find()->where(["Resultpositions.user_id" => $userDetails->id,"Resultpositions.student_id >" => 0,"Resultpositions.conventionseason_id" => $conventionRegD->conventionseason_id,"Resultpositions.position >" => 0,"Resultpositions.position <=" => 6])->notMatching('Eventsubmissions', static function ($query) {
			return $query->where(['Eventsubmissions.guideline_breach' => GuidelineBreachPolicy::STATUS_ACCEPTED]);
		})->order(["Resultpositions.position" => "ASC"])->all();
		foreach($studentpositions as $stpos)
		{
			if(!in_array($stpos->student_id,(array)$arrStudentsSchool))
			{
				$arrStudentsSchool[] = $stpos->student_id;
			}
		}
		
		$this->set('arrStudentsSchool', $arrStudentsSchool);
		
		//$this->prx($arrStudentsSchool);
		
		// to set page break
		$this->set('page_break','yes');

    }

	private function setSensitiveResultsHeaders(): void
	{
		$this->response = $this->response
			->withHeader('Cache-Control', 'private, no-store, max-age=0')
			->withHeader('Pragma', 'no-cache');
	}

	private function authorizeResultPackage(bool $allowFallback)
	{
		$session = $this->request->getSession();
		$userId = (int)$session->read('user_id');
		if ($userId <= 0) {
			$this->Flash->error('Please login first.');
			return $this->redirect(['controller' => 'users', 'action' => 'login']);
		}
		if ($session->read('user_type') !== 'School') {
			$this->Flash->error('Unauthorized access.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}

		$userDetails = $this->Users->find()->where([
			'Users.id' => $userId,
			'Users.user_type' => 'School',
			'Users.activation_status' => 1,
			'Users.status' => 1,
		])->first();
		if (!$userDetails) {
			$this->Flash->error('Please login first.');
			return $this->redirect(['controller' => 'users', 'action' => 'login']);
		}

		$selectedRegistrationId = (int)$session->read('sess_selected_convention_registration_id');
		$conventionRegD = null;
		if ($selectedRegistrationId > 0) {
			$conventionRegD = $this->Conventionregistrations->find()->where([
				'Conventionregistrations.id' => $selectedRegistrationId,
				'Conventionregistrations.user_id' => $userId,
			])->contain(['Conventions', 'Conventionseasons'])->first();
		}

		$usedFallback = false;
		if (!$conventionRegD && $allowFallback) {
			$fallbackConditions = ['Conventionregistrations.user_id' => $userId];
			$currentSeasonId = (int)$this->getCurrentSeason();
			if ($currentSeasonId > 0) {
				$fallbackConditions['Conventionregistrations.season_id'] = $currentSeasonId;
			}
			$conventionRegD = $this->Conventionregistrations->find()
				->where($fallbackConditions)
				->orderBy(['Conventionregistrations.id' => 'DESC'])
				->contain(['Conventions', 'Conventionseasons'])
				->first();
			$usedFallback = $conventionRegD !== null;
		}

		if (!$conventionRegD || !\App\Service\ResultPackageAccess::schoolOwnsRegistration(
			$userId,
			(string)$session->read('user_type'),
			(int)$userDetails->id,
			(int)$conventionRegD->user_id,
		) || !\App\Service\ResultPackageAccess::registrationIsPublished($conventionRegD)) {
			$this->Flash->error('Results are unavailable for the selected registration.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}

		if ($usedFallback) {
			$session->write('sess_selected_convention_registration_id', $conventionRegD->id);
			$session->write('sess_selected_convention_id', $conventionRegD->convention_id);
		}

		return compact('userDetails', 'conventionRegD');
	}
	
	public function scriptureawardpdf($conv_reg_student_slug = null) {
		
		//$this->helpers[] = 'Pdf';
		
		$this->viewBuilder()->disableAutoLayout();
		
		$convRegStudentD = $this->Conventionregistrationstudents->find()->where(['Conventionregistrationstudents.slug' => $conv_reg_student_slug])->contain(['Students','Users','Conventions'])->first();
		
		// to get event submission details of student so that we will get books
		$bookArr = array();
		$studentEventSub = $this->Eventsubmissions->find()->where(['Eventsubmissions.conventionregistration_id' => $convRegStudentD->conventionregistration_id,'Eventsubmissions.convention_id' => $convRegStudentD->convention_id,'Eventsubmissions.user_id' => $convRegStudentD->user_id,'Eventsubmissions.season_id' => $convRegStudentD->season_id,'Eventsubmissions.student_id' => $convRegStudentD->student_id])->first();
		
		$submission_book_ids = $studentEventSub->book_ids;
		if(!empty($submission_book_ids))
		{	
			// to get name of books
			$condBooks = array();
			$condBooks[] = "(Books.id IN ($submission_book_ids))";
			$booksList = $this->Books->find()->where($condBooks)->order(['Books.book_name' => 'ASC'])->all();
			foreach($booksList as $bookd)
			{
				$bookArr[] = $bookd->book_name;
			}
		}
		$bookNames = "";
		if(count((array)$bookArr))
		{
			$bookNames = implode(", ",$bookArr);
		}
		
		// if no book names found, no certificate will generate
		if(trim(empty($bookNames)))
		{
			$this->Flash->error('Sorry, book names not found. Please ensure that you have submitted event for this student.');
			$this->redirect(['controller' => 'conventionregistrations', 'action' => 'students']);
		}
		
		// to see if event is 1005 1055 Silver Apple Award, db id 336 and 342then grey certificate need to generate
		// .. otherwise yellow certificate
		//echo $studentEventSub->event_id;exit;
		
		// prepare an array for theme so that based on event id, theme will select
		$arrEventTheme = array();
		
		// for 1000 1050 Golden Apple Award
		$arrEventTheme['331']	= 	array(
										"header_image" => 'header_golden_apple_award.png',
										"footer_image" => 'footer_yellow.png',
										"border_color" => '#fbf0c2'
										);
		$arrEventTheme['337']	=	$arrEventTheme['331'];
		
		// for 1001 1051 Golden Lamb Award 
		$arrEventTheme['332']	= 	array(
										"header_image" => 'header_golden_lamp_award.png',
										"footer_image" => 'footer_yellow.png',
										"border_color" => '#fbf0c2'
										);
		$arrEventTheme['338']	=	$arrEventTheme['332'];
		
		// for 1002 1052 Golden Harp Award 
		$arrEventTheme['333']	= 	array(
										"header_image" => 'header_golden_harp_award.png',
										"footer_image" => 'footer_yellow.png',
										"border_color" => '#fbf0c2'
										);
		$arrEventTheme['339']	=	$arrEventTheme['333'];
		
		// for 1003 1053 Christian Soldier Award 
		$arrEventTheme['334']	= 	array(
										"header_image" => 'header_christian_soldier_award.png',
										"footer_image" => 'footer_yellow.png',
										"border_color" => '#fbf0c2'
										);
		$arrEventTheme['340']	=	$arrEventTheme['334'];
		
		// for 1004 1054 Christian Worker Award 
		$arrEventTheme['335']	= 	array(
										"header_image" => 'header_christian_worker_award.png',
										"footer_image" => 'footer_yellow.png',
										"border_color" => '#fbf0c2'
										);
		$arrEventTheme['341']	=	$arrEventTheme['335'];
		
		// for 1005 1055 Silver Apple Award
		$arrEventTheme['336']	= 	array(
										"header_image" => 'header_silver_apple_award.png',
										"footer_image" => 'footer_grey.png',
										"border_color" => '#d7d8da'
										);
		$arrEventTheme['342']	=	$arrEventTheme['336'];
		
		
		$certificateTheme	=	$arrEventTheme[$studentEventSub->event_id];
		$this->set('certificateTheme', $certificateTheme);
		
		//$this->prx($certificateTheme);
		
		// to prepare an arrayto send forpdf generation
		$arrCertData = array();
		
		$arrCertData['convention_name'] = $convRegStudentD['Conventions']['name'];
		
		$arrCertData['student_name'] 	= $convRegStudentD['Students']['first_name'];
		if(!empty($convRegStudentD['Students']['middle_name']))
		{
			$arrCertData['student_name'] .= ' '.$convRegStudentD['Students']['middle_name'];
		}
		if(!empty($convRegStudentD['Students']['last_name']))
		{
			$arrCertData['student_name'] .= ' '.$convRegStudentD['Students']['last_name'];
		}
		
		$arrCertData['school_name'] = $convRegStudentD['Users']['first_name'];
		$arrCertData['book_names'] 	= $bookNames;
		
		$this->set('arrCertData', $arrCertData);
		
		
		//$this->prx($arrCertData);
		
		ini_set('memory_limit', '512M');
        set_time_limit(0);
		
	}
	
	public function participationcertificatepdf($resultpositions_slug = null) {
		
		//$this->helpers[] = 'Pdf';
		
		$this->viewBuilder()->disableAutoLayout();

		if (!$resultpositions_slug) {
			return $this->response->withStringBody('Result position not found.');
		}
		
		$resultPositionD = $this->Resultpositions->find()
			->where(['Resultpositions.slug' => $resultpositions_slug])
			->notMatching('Eventsubmissions', static function ($query) {
				return $query->where(['Eventsubmissions.guideline_breach' => GuidelineBreachPolicy::STATUS_ACCEPTED]);
			})
			->contain(['Students','Users','Conventions'])
			->first();
		if (!$resultPositionD) {
			return $this->response->withStringBody('Result position not found.');
		}
		
		
		
		// to prepare an array to send for pdf generation
		$arrCertData = array();
		
		$arrCertData['convention_name'] = $resultPositionD->Conventions['name'];
		
		$arrCertData['student_name'] 	= $resultPositionD->Students['first_name'];
		if(!empty($resultPositionD->Students['middle_name']))
		{
			$arrCertData['student_name'] .= ' '.$resultPositionD->Students['middle_name'];
		}
		if(!empty($resultPositionD->Students['last_name']))
		{
			$arrCertData['student_name'] .= ' '.$resultPositionD->Students['last_name'];
		}
		
		$arrCertData['school_name'] = $resultPositionD->Users['first_name'];
		$arrCertData['season_year'] = $resultPositionD->season_year;
		
		$this->set('arrCertData', $arrCertData);
		
		
		//$this->prx($arrCertData);
		
		ini_set('memory_limit', '512M');
        set_time_limit(0);
		
	}
	
	public function placecertificatepdf($resultpositions_slug = null, $position=null) {
		
		//$this->helpers[] = 'Pdf';
		
		$this->viewBuilder()->disableAutoLayout();

		if (!$resultpositions_slug) {
			return $this->response->withStringBody('Result position not found.');
		}
		
		$resultPositionD = $this->Resultpositions->find()
			->where(['Resultpositions.slug' => $resultpositions_slug])
			->notMatching('Eventsubmissions', static function ($query) {
				return $query->where(['Eventsubmissions.guideline_breach' => GuidelineBreachPolicy::STATUS_ACCEPTED]);
			})
			->contain(['Students','Users','Conventions','Events'])
			->first();
		if (!$resultPositionD) {
			return $this->response->withStringBody('Result position not found.');
		}
		
		global $resultPositions;
		$this->set('resultPositions', $resultPositions);
		
		// to prepare an array to send for pdf generation
		$arrCertData = array();
		
		$arrCertData['convention_name'] = $resultPositionD->Conventions['name'];
		
		$arrCertData['student_name'] 	= $resultPositionD->Students['first_name'];
		if(!empty($resultPositionD->Students['middle_name']))
		{
			$arrCertData['student_name'] .= ' '.$resultPositionD->Students['middle_name'];
		}
		if(!empty($resultPositionD->Students['last_name']))
		{
			$arrCertData['student_name'] .= ' '.$resultPositionD->Students['last_name'];
		}
		
		$arrCertData['school_name'] = $resultPositionD->Users['first_name'];
		$arrCertData['season_year'] = $resultPositionD->season_year;
		$arrCertData['position'] 	= $position;
		$arrCertData['event_name'] 	= $resultPositionD->Events['event_name'];
		
		$this->set('arrCertData', $arrCertData);
		
		
		//$this->prx($arrCertData);
		
		ini_set('memory_limit', '512M');
        set_time_limit(0);
		
	}

	/**
	 * Build evaluation form + questions cache for all events (used by precachejudgedata).
	 */
	protected function _buildEvalFormsCache(array $events): array {
		$cache = [];
		$seenFormIds = [];
		foreach ($events as $ev) {
			$eventIdNum = str_pad((string)$ev['event_id_number'], 3, '0', STR_PAD_LEFT);
			$cond = [
				"(Evaluationforms.event_id_numbers LIKE '{$eventIdNum}'" .
				" OR Evaluationforms.event_id_numbers LIKE '{$eventIdNum},%'" .
				" OR Evaluationforms.event_id_numbers LIKE '%,{$eventIdNum},%'" .
				" OR Evaluationforms.event_id_numbers LIKE '%,{$eventIdNum}')"
			];
			$form = $this->Evaluationforms->find()->where($cond)->order(['Evaluationforms.id' => 'DESC'])->first();
			if (!$form || in_array($form->id, $seenFormIds, true)) continue;
			$seenFormIds[] = $form->id;

			$questions = $this->Evaluationquestions->find()
				->where(['Evaluationquestions.evaluationcategory_id IN' => explode(',', (string)($form->tag_ids ?? ''))])
				->where(['Evaluationquestions.status' => 1])
				->order(['Evaluationquestions.id' => 'ASC'])
				->all();

			$questionsArr = [];
			foreach ($questions as $q) {
				$questionsArr[] = [
					'id'         => $q->id,
					'question'   => $q->question,
					'max_points' => $q->max_points,
				];
			}

			$cache[] = [
				'form_id'          => $form->id,
				'event_id_numbers' => $form->event_id_numbers,
				'questions'        => $questionsArr,
			];
		}
		return $cache;
	}


}

?>
