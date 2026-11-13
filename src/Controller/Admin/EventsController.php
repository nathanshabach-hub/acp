<?php

namespace App\Controller\Admin;

use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;

#[\AllowDynamicProperties]
class EventsController extends AppController {

    public array $paginate = ['limit' => 50, 'order' => ['Events.name' => 'asc']];

    public $Divisions = null;
    public $Books = null;
    public $Conventionseasonevents = null;

    //public $helpers = array('Javascript', 'Ajax');

    public function initialize(): void {
        parent::initialize();
		$this->loadComponent('RequestHandler');
		$this->loadComponent('PImage');
		$this->loadComponent('PImageTest');
        $this->loadComponent('Paginator');
        $this->loadComponent('Flash');
        $action = $this->request->getParam('action');
        $loggedAdminId = $this->request->getSession()->read('admin_id');
        if ($action != 'forgotPassword' && $action != 'logout') {
            if (!$loggedAdminId && $action != "login" && $action != 'captcha') {
                $this->redirect(['controller' => 'admins', 'action' => 'login']);
            }
        }
		
		$this->loadModel('Divisions');
		$this->loadModel('Books');
		$this->loadModel("Conventionseasonevents");
    }

    public function index() {

        $this->set('title', ADMIN_TITLE . 'Manage Global Events');
        $this->viewBuilder()->setLayout('admin');
        $this->set('manageEvents', '1');
        $this->set('eventList', '1');
		
		$divisionDD = $this->Divisions->find()->where([])->order(['Divisions.name' => 'ASC'])->all()->combine('id', 'name')->toArray();
		$this->set('divisionDD', $divisionDD);

        $separator = array();
        $condition = array();
        //$condition = array('Events.parent_id' => 0);

        if ($this->request->is('post')) {
            if (isset($this->request->getData()['action'])) {
                $idList = implode(',', $this->request->getData()['chkRecordId']);
                $action = $this->request->getData()['action'];
                if ($idList) {
                    if ($action == "Activate") {
                        $this->Events->updateAll(['status' => '1'], ["id IN ($idList)"]);
                        $this->Flash->success('Records are activated successfully.');
                    } elseif ($action == "Deactivate") {
                        $this->Events->updateAll(['status' => '0'], ["id IN ($idList)"]);
                        $this->Flash->success('Records are deactivated successfully.');
                    } elseif ($action == "Delete") {
                        $this->Events->deleteAll(["id IN ($idList)"]);
                        $this->Flash->success('Records are deleted successfully.');
                    }
                }
            }

            if (isset($this->request->getData()['Events']['keyword']) && $this->request->getData()['Events']['keyword'] != '') {
                $keyword = trim($this->request->getData()['Events']['keyword']);
            }
			
			if (isset($this->request->getData()['Events']['division_id']) && $this->request->getData()['Events']['division_id'] != '') {
                $division_id = trim($this->request->getData()['Events']['division_id']);
            }
			
        } elseif ($this->request->getQuery('keyword') !== null || $this->request->getQuery('division_id') !== null) {
            $keyword = trim((string)$this->request->getQuery('keyword', ''));
            $division_id = trim((string)$this->request->getQuery('division_id', ''));
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
            $condition[] = "(Events.event_name LIKE '%".addslashes($keyword)."%' OR Events.event_id_number LIKE '%".addslashes($keyword)."%')";
            $this->set('keyword', $keyword);
        }
		if (isset($division_id) && $division_id != '') {
            $separator[] = 'division_id:' . urlencode($division_id);
            $condition[] = "(Events.division_id = '".addslashes($division_id)."')";
            $this->set('division_id', $division_id);
        }
		
        //pr($condition);exit;
        $separator = implode("/", $separator);
        $this->set('separator', $separator);
        $eventsQuery = $this->Events->find()
            ->contain(['Divisions'])
            ->where($condition)
            ->orderBy(['Events.event_id_number' => 'ASC']);
        $this->set('events', $this->paginate($eventsQuery, ['limit' => 50]));
        if ($this->request->is("ajax")) {
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Admin/Events');
            $this->render('index');
        }
    }

    public function qualifyingrecords()
    {
        $this->set('title', ADMIN_TITLE . 'Qualifying Times & Records');
        $this->viewBuilder()->setLayout('admin');
        $this->set('manageEvents', '1');
        $this->set('manageGlobalQualifyingRecords', '1');

        $eventsQuery = $this->Events->find()
            ->where(['Events.event_judging_type IN' => ['times', 'distances', 'scores']])
            ->orderBy(['Events.event_id_number' => 'ASC']);
        $this->set('events', $this->paginate($eventsQuery, ['limit' => 50]));
    }

    public function qualifyingdata($slug = null)
    {
        $event = $this->Events->find()
            ->where(['Events.slug' => $slug])
            ->first();
        if (!$event || !in_array($event->event_judging_type, ['times', 'distances', 'scores'], true)) {
            $this->Flash->error('Qualifying settings were not found for this event.');
            return $this->redirect(['controller' => 'events', 'action' => 'qualifyingrecords']);
        }

        $this->set('title', ADMIN_TITLE . 'Qualifying Times & Records - ' . $event->event_name);
        $this->viewBuilder()->setLayout('admin');
        $this->set('manageEvents', '1');
        $this->set('manageGlobalQualifyingRecords', '1');

        if ($this->request->is(['post', 'put'])) {
            $qualifyingTime = null;
            $qualifyingDistance = null;
            $qualifyingScore = null;
            $fieldName = match ($event->event_judging_type) {
                'times' => 'qualifying_time_score',
                'distances' => 'qualifying_distance',
                'scores' => 'qualifying_score',
            };
            $value = trim((string)$this->request->getData($fieldName, ''));
            $currentRecord = trim((string)$this->request->getData('current_record', ''));
            $validationError = null;

            if ($value !== '' && $event->event_judging_type === 'times') {
                if (!preg_match('/^(\d{1,3}):[0-5]\d:[0-5]\d(?:\.\d{1,6})?$/', $value, $timeParts) || (int)$timeParts[1] > 838) {
                    $validationError = 'Enter qualifying time as HH:MM:SS or HH:MM:SS.milliseconds (for example, 00:01:13.000).';
                } else {
                    $qualifyingTime = $value;
                }
            } elseif ($value !== '' && $event->event_judging_type === 'distances') {
                if (!is_numeric($value)) {
                    $validationError = 'Enter a valid qualifying distance in meters.';
                } else {
                    $qualifyingDistance = $value;
                }
            } elseif ($value !== '' && $event->event_judging_type === 'scores') {
                if (!preg_match('/^-?\d+$/', $value)) {
                    $validationError = 'Enter a whole-number qualifying score.';
                } else {
                    $qualifyingScore = (int)$value;
                }
            }

            if (mb_strlen($currentRecord) > 255) {
                $validationError = 'Current record must be 255 characters or fewer.';
            }

            if ($validationError !== null) {
                $this->Flash->error($validationError);
            } else {
                $event->qualifying_time_score = $qualifyingTime;
                $event->qualifying_distance = $qualifyingDistance;
                $event->qualifying_score = $qualifyingScore;
                $event->current_record = $currentRecord !== '' ? $currentRecord : null;

                $seasonValues = [
                    'qualifying_time_score' => $qualifyingTime,
                    'qualifying_distance' => $qualifyingDistance,
                    'qualifying_score' => $qualifyingScore,
                    'current_record' => $currentRecord !== '' ? $currentRecord : null,
                ];
                $saved = $this->Events->getConnection()->transactional(function () use ($event, $seasonValues) {
                    if (!$this->Events->save($event)) {
                        return false;
                    }
                    $this->Conventionseasonevents->updateAll($seasonValues, [
                        'Conventionseasonevents.event_id' => $event->id,
                    ]);
                    return true;
                });

                if (!$saved) {
                    $this->Flash->error('Qualifying settings could not be saved.');
                } else {
                    $this->Flash->success('Qualifying settings saved and applied to all convention seasons for this event.');
                    return $this->redirect(['controller' => 'events', 'action' => 'qualifyingdata', $event->slug]);
                }
            }
        }

        $this->set('event', $event);
    }

    public function activateevent($slug = null) {
        if ($slug != '') {
            $this->viewBuilder()->disableAutoLayout();
            $this->Events->updateAll(['status' => '1'], ["slug" => $slug]);
            $this->set('action', '/admin/events/deactivatev/' . $slug);
            $this->set('status', 1);
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Admin');
            $this->render('update_status');
        }
    }

    public function deactivateevent($slug = null) {
        if ($slug != '') {
            $this->viewBuilder()->disableAutoLayout();
            $this->Events->updateAll(['status' => '0'], ["slug" => $slug]);
            $this->set('action', '/admin/events/activateevent/' . $slug);
            $this->set('status', 0);
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Admin');
            $this->render('update_status');
        }
    }

    public function deleteevent($slug = null) {
        
		// first check that this event exists
		$eventD = $this->Events->find()->where(['Events.slug' => $slug])->first();
		if($eventD)
		{
			// to check if this event linked with any other data
			$event_id 	= $eventD->id;
			$flagDelete = 1;
			
			//check in conventionseasonevents
			$checkConventionSeasonEvents = $this->Conventionseasonevents->find()->where(['Conventionseasonevents.event_id' => $event_id])->first();
			if($checkConventionSeasonEvents)
			{
				$flagDelete = 0;
				$this->Flash->error('Event cannot delete. Event is linked with Convention > Seasons > Events.');
			}
			
			if($flagDelete == 1)
			{
				$this->Events->deleteAll(["slug" => $slug]);
				$this->Flash->success('Event details deleted successfully.');
			}
		}
		else
		{
			$this->Flash->error('Event not found.');
		}
		
		
        $this->redirect(['controller' => 'events', 'action' => 'index']);
    }

    public function add() {
        $this->set('title', ADMIN_TITLE . 'Add Event');
        $this->viewBuilder()->setLayout('admin');
		
        $this->set('manageEvents', '1');
        $this->set('eventAdd', '1');
		
		$divisionDD = $this->Divisions->find()->where([])->order(['Divisions.name' => 'ASC'])->all()->combine('id', 'name')->toArray();
		$this->set('divisionDD', $divisionDD);
		
		$bookDD = $this->Books->find()->where([])->order(['Books.book_name' => 'ASC'])->all()->combine('id', 'book_name')->toArray();
		$this->set('bookDD', $bookDD);
		
		global $yesNoDD;
		$this->set('yesNoDD', $yesNoDD);
		
		global $eventTypeDD;
		$this->set('eventTypeDD', $eventTypeDD);
		
		global $eventUploadTypeDD;
		$this->set('eventUploadTypeDD', $eventUploadTypeDD);
		
		global $eventGroupNameDD;
		$this->set('eventGroupNameDD', $eventGroupNameDD);
		
		global $eventGenderDD;
		$this->set('eventGenderDD', $eventGenderDD);
		
		global $eventKindID;
		$this->set('eventKindID', $eventKindID);
		
		global $eventJudgeType;
		$this->set('eventJudgeType', $eventJudgeType);
		
        $events = $this->Events->newEntity([]);
        if ($this->request->is('post')) {
			
			//$this->prx($this->request->getData());
			
            $data = $this->Events->patchEntity($events, $this->request->getData(), ['validate' => 'add']);
			
			$book_ids = $this->request->getData()['Events']['book_ids'];
			
			if(isset($book_ids) && count($book_ids))
			{
				$data->book_ids = implode(",",$book_ids);
			}
			else
			{
				$data->book_ids = '';
			}
			
            if (count($data->getErrors()) == 0) {

				$slug = $this->getSlug($this->request->getData()['Events']['event_name'] . ' ' . time(), 'Events');
                $data->slug 			= $slug;
                $data->status 			= 1;
                $data->created 			= date('Y-m-d H:i:s');
                $data->modified 		= NULL;
                if ($this->Events->save($data)) {
                    $this->Flash->success('Event added successfully.');
                    $this->redirect(['controller' => 'events', 'action' => 'index']);
                }
            } else {
                // $this->Flash->error('Please below listed errors.');
            }
        }
        $this->set('events', $events);
    }

    public function edit($slug = null) {
        $this->set('title', ADMIN_TITLE . 'Edit Event');
        $this->viewBuilder()->setLayout('admin');
        
		$this->set('manageEvents', '1');
        $this->set('eventList', '1');
		
		$divisionDD = $this->Divisions->find()->where([])->order(['Divisions.name' => 'ASC'])->all()->combine('id', 'name')->toArray();
		$this->set('divisionDD', $divisionDD);
		
		$bookDD = $this->Books->find()->where([])->order(['Books.book_name' => 'ASC'])->all()->combine('id', 'book_name')->toArray();
		$this->set('bookDD', $bookDD);
		
		global $yesNoDD;
		$this->set('yesNoDD', $yesNoDD);
		
		global $eventTypeDD;
		$this->set('eventTypeDD', $eventTypeDD);
		
		global $eventUploadTypeDD;
		$this->set('eventUploadTypeDD', $eventUploadTypeDD);
		
		global $eventGroupNameDD;
		$this->set('eventGroupNameDD', $eventGroupNameDD);
		
		global $eventGenderDD;
		$this->set('eventGenderDD', $eventGenderDD);
		
		global $eventKindID;
		$this->set('eventKindID', $eventKindID);
		
		global $eventJudgeType;
		$this->set('eventJudgeType', $eventJudgeType);
		
        if ($slug) {
            $categories1 = $this->Events->find()->where(['Events.slug' => $slug])->first();
            $uid = $categories1->id;
        }
		
        $events = $this->Events->get($uid);
        if ($this->request->is(['post', 'put'])) {
            $data = $this->Events->patchEntity($events, $this->request->getData());
			
			$book_ids = $this->request->getData()['Events']['book_ids'];
			
			
			
			if(isset($book_ids) && count((array)$book_ids))
			{
				$data->book_ids = implode(",",(array)$book_ids);
			}
			else
			{
				$data->book_ids = '';
			}
			
            if (count($data->getErrors()) == 0) {
				
				$data->modified = date("Y-m-d H:i:s");
                if ($this->Events->save($data)) {
                    $this->Flash->success('Event details updated successfully.');
                    $this->redirect(['controller' => 'events', 'action' => 'index']);
                }
            } else {
                // $this->Flash->error('Please below listed errors.');
            }
        }
        $this->set('events', $events);
    }

}

?>
