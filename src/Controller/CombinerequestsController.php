<?php

namespace App\Controller;
use Cake\ORM\TableRegistry;
use Cake\Datasource\ConnectionManager;
use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use App\Utility\LegacyEmail as Email;
use Cake\I18n\I18n;


#[\AllowDynamicProperties]
class CombinerequestsController extends AppController {

    public array $paginate = ['limit' => 50];
	
	public function initialize(): void {
        parent::initialize();
		$this->loadComponent('RequestHandler');
		$this->loadComponent('PImage');
		$this->loadComponent('PImageTest');

        // Include the FlashComponent
        $this->loadComponent('Flash');

        $this->loadModel("Users"); 
		$this->loadModel("Emailtemplates");
		$this->loadModel("Conventionregistrations");
		$this->loadModel("Conventionregistrationstudents");
		$this->loadModel("Conventionseasonevents");
		$this->loadModel("Events");
		$this->loadModel("Crstudentevents");
    }

    public function viewlist() {

        $loginCheck = $this->userLoginCheck();
        if ($loginCheck !== true) {
            return $loginCheck;
        }
        //$this->schoolAdminLoginCheck();
		$this->multiLoginCheck(['School','Teacher_Parent']);
		
        $this->set("title_for_layout", "Combined Team/Group Events " . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
		$this->set('active_cr_studentgroups','active');
		
        $msgString = '';

		$user_id = $this->request->getSession()->read("user_id");
		$user_type 	= $this->request->getSession()->read("user_type");
		$userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);

        $separator = array();
        $registrationId = (int)$this->request->getSession()->read('sess_selected_convention_registration_id');
        $schoolId = $user_type === 'Teacher_Parent' ? (int)($userDetails->school_id ?? 0) : (int)$user_id;
        if ($registrationId <= 0 || $schoolId <= 0 || !$this->Conventionregistrations->find()->where([
            'Conventionregistrations.id' => $registrationId,
            'Conventionregistrations.user_id' => $schoolId,
        ])->first()) {
			$this->Flash->error('Please choose convention registration first.');
			return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
		}

        if ($this->request->is('post')) {
            if ($this->request->getData('action') !== null) {
                $ids = array_values(array_filter(array_map('intval', (array)$this->request->getData('chkRecordId')), static fn($id) => $id > 0));
                $action = $this->request->getData('action');
                if ($ids) {
                    $conditions = ['id IN' => $ids, 'user_id' => $schoolId, 'conventionregistration_id' => $registrationId];
                    if ((new \App\Service\CombinedTeams($this->Combinerequests->getConnection()))->available()) {
                        $conditions['combined_team_id IS'] = null;
                    }
                    if ($action == "Activate") {
                        $affected = $this->Combinerequests->updateAll(['status' => '1'], $conditions);
                    } elseif ($action == "Deactivate") {
                        $affected = $this->Combinerequests->updateAll(['status' => '0'], $conditions);
                    } elseif ($action == "Delete") {
                        $affected = $this->Combinerequests->deleteAll($conditions);
                    } else {
                        $this->Flash->error('Invalid action.');
                    }
                    if (isset($affected)) {
                        if ($affected > 0) {
                            $this->Flash->success($affected . ' request(s) updated.');
                        } else {
                            $this->Flash->error('No matching requests were changed.');
                        }
                    }
                } else {
                    $this->Flash->error('Please select at least one request.');
                }
            }

            if (is_string($this->request->getData('Combinerequests.keyword'))) {
                $keyword = trim($this->request->getData('Combinerequests.keyword'));
            }
        } elseif ($this->request->getParam('pass')) {
            foreach ($this->request->getParam('pass', []) as $value) {
                if (str_starts_with($value, 'keyword:')) {
                    $keyword = urldecode(substr($value, strlen('keyword:')));
                }
            }
        }

        if (isset($keyword) && $keyword != '') {
            $separator[] = 'keyword:' . urlencode($keyword);
            $this->set('keyword', $keyword);
        }
		
        $separator = implode("/", $separator);
        $this->set('separator', $separator);
        $query = $this->Combinerequests->find()->where([
            'Combinerequests.conventionregistration_id' => $registrationId,
            'Combinerequests.user_id' => $schoolId,
        ])->contain(['Conventions', 'Combineduser', 'Events']);
        $teamsService = new \App\Service\CombinedTeams($this->Combinerequests->getConnection());
        $this->set('combinedTeams', $teamsService->listing($registrationId));
        if ($teamsService->available()) {
            $query->where(['Combinerequests.combined_team_id IS' => null]);
        }
        if (isset($keyword) && $keyword != '') {
            $query->where(['OR' => [
                'Combinerequests.student_name LIKE' => '%' . $keyword . '%',
                'Combinerequests.event_id_number LIKE' => '%' . $keyword . '%',
            ]]);
        }
        $this->paginate = ['limit' => 30, 'order' => ['Combinerequests.id' => 'DESC']];
        $this->set('combinerequests', $this->paginate($query));
        if ($this->request->is("ajax")) {
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Combinerequests');
            return $this->render('viewlist');
        }
    }
	
	public function addrequest() {

		$this->userLoginCheck();
		//$this->schoolAdminLoginCheck();
		$this->multiLoginCheck(['School','Teacher_Parent']);
		
		//echo ' fsdf sdf sdf d';exit;
		$this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Combined Team/Group Events - Add Request " . TITLE_FOR_PAGES);
		
		$this->set('active_cr_studentgroups','active');
		
        $user_id = $this->request->getSession()->read("user_id");
		if ((int)$user_id <= 0) {
			$this->Flash->error('Please login first.');
			return $this->redirect(['controller' => 'users', 'action' => 'login']);
		}

		$userDetails = $this->Users->find()->where(['Users.id' => (int)$user_id])->first();
		if (!$userDetails) {
			$this->Flash->error('Could not load your account. Please login again.');
			return $this->redirect(['controller' => 'users', 'action' => 'logout']);
		}
        $this->set('userDetails', $userDetails);
		$eventNameIDDD = [];
		$schoolNamesDD = [];
		$combinerequests = $this->Combinerequests->newEntity([]);
		$conventionregistrationstudents = [];
		$eventRegisteredStudents = [];
		$selectedEventId = 0;
		$selectedStudentIds = [];
		$selectedCombineWithUserId = 0;
        $partnerRegisteredStudents = [];
        $selectedPartnerStudentIds = [];
        $teamsService = new \App\Service\CombinedTeams($this->Combinerequests->getConnection());
		
		$sessSelectedConventionRegistrationId = (int)$this->request->getSession()->read("sess_selected_convention_registration_id");
		if ($sessSelectedConventionRegistrationId <= 0) {
			$sessSelectedConventionRegistrationId = $this->resolveSelectedConventionRegistrationId($userDetails);
		}

		if($sessSelectedConventionRegistrationId>0)
		{
			$sess_selected_convention_registration_id = $sessSelectedConventionRegistrationId;
			
			// to get convention registration details
			$conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.id' => $sess_selected_convention_registration_id])->contain(['Conventions'])->first();
			if (!$conventionRegD) {
				$this->request->getSession()->delete('sess_selected_convention_registration_id');
				$this->request->getSession()->delete('sess_selected_convention_id');
				$sess_selected_convention_registration_id = $this->resolveSelectedConventionRegistrationId($userDetails);
				if ($sess_selected_convention_registration_id > 0) {
					$conventionRegD = $this->Conventionregistrations->find()
						->where(['Conventionregistrations.id' => $sess_selected_convention_registration_id])
						->contain(['Conventions'])
						->first();
				}
			}

			if (!$conventionRegD) {
				$this->Flash->error('Could not find an active convention registration for your account. Please select your convention from the top dropdown first.');
				return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'myregistrations']);
			}
            $schoolId = $userDetails->user_type === 'School' ? (int)$userDetails->id : (int)$userDetails->school_id;
            if (!in_array($userDetails->user_type, ['School', 'Teacher_Parent'], true)
                || (int)$conventionRegD->user_id !== $schoolId) {
                throw new \Cake\Http\Exception\ForbiddenException('Choose your own school convention registration.');
            }
			
			/* 1. to get the list of event ids chosen in this convention for this season */
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
            $condEvents['Events.group_event_yes_no'] = 1;
            $condEvents['Events.status'] = 1;
			$eventsList = $this->Events->find()->where($condEvents)->order(['Events.event_id_number' => 'ASC'])->all();
			foreach($eventsList as $eventrec)
			{
				$eventNameIDDD[$eventrec->id] = $eventrec->event_name.' ('.$eventrec->event_id_number.')';
			}
			$this->set('eventNameIDDD', $eventNameIDDD);
			
			
			/* 2. To get list of schools participated in this season */
			$arrSchoolIDSCR = array();
			$arrSchoolIDSCR[] = 0;
			$convSchools = $this->Conventionregistrations->find()->where(["Conventionregistrations.conventionseason_id" => $conventionRegD->conventionseason_id])->order(['Conventionregistrations.id' => 'DESC'])->all();
			foreach($convSchools as $convsch)
			{
				$arrSchoolIDSCR[] = $convsch->user_id;
			}
			$arrSchoolIDSCRImplode = implode(",",$arrSchoolIDSCR);
			
			// now create dropdown for schools name
			$schoolNamesDD = array();
			$condSchoolsNameCR = array();
			$condSchoolsNameCR[] = "(Users.id IN ($arrSchoolIDSCRImplode) )";
			$condSchoolsNameCR[] = "(Users.user_type = 'School')";
            $condSchoolsNameCR['Users.id !='] = $schoolId;
            $condSchoolsNameCR['Users.status'] = 1;
            $condSchoolsNameCR['Users.activation_status'] = 1;
			$schoolsListCR = $this->Users->find()->where($condSchoolsNameCR)->order(['Users.first_name' => 'ASC'])->all();
			foreach($schoolsListCR as $schoollistcr)
			{
				$schoolNamesDD[$schoollistcr->id] = $schoollistcr->first_name.' '.$schoollistcr->last_name;
			}
			$this->set('schoolNamesDD', $schoolNamesDD);

			if ($this->request->is('post')) {
				$selectedEventId = (int)($this->request->getData('Combinerequests.event_id') ?? 0);
				$selectedCombineWithUserId = (int)($this->request->getData('Combinerequests.combine_with_user_id') ?? 0);
				$selectedStudentIds = array_values(array_unique(array_filter(array_map('intval', (array)$this->request->getData('selected_student_ids')), static function ($sid) {
					return $sid > 0;
				})));
                $selectedPartnerStudentIds = (array)$this->request->getData('partner_student_ids', []);
			} else {
				$selectedEventId = (int)$this->request->getQuery('event_id');
                $selectedCombineWithUserId = (int)$this->request->getQuery('partner_id');
			}

			$eventRegisteredStudents = $this->getRegisteredStudentsForEvent($sess_selected_convention_registration_id, $selectedEventId);
            $partnerRegistration = $this->Conventionregistrations->find()->where([
                'Conventionregistrations.conventionseason_id' => $conventionRegD->conventionseason_id,
                'Conventionregistrations.user_id' => $selectedCombineWithUserId,
            ])->first();
            if (isset($schoolNamesDD[$selectedCombineWithUserId]) && $partnerRegistration && isset($eventNameIDDD[$selectedEventId])) {
                $partnerRegisteredStudents = $teamsService->students((int)$partnerRegistration->id, $selectedEventId);
            }
			
			//$this->prx($schoolNamesDD);
			
		}
		else
		{
			$this->Flash->error('Please select your convention from the top dropdown first, then open Combined Team/Group Events again.');
			return $this->redirect(['controller' => 'conventionregistrations', 'action' => 'myregistrations']);
		}
		
		if ($this->request->is('post')) {
			$eventId = (int)($this->request->getData('Combinerequests.event_id') ?? 0);
			$combineWithUserId = (int)($this->request->getData('Combinerequests.combine_with_user_id') ?? 0);
			if ($this->request->getData('agreement_confirmed') !== '1') {
                $this->Flash->error('Confirm that both schools have agreed to this combined team.');
            } elseif ($eventId <= 0) {
				$this->Flash->error('Please choose an event.');
			} elseif ($combineWithUserId <= 0) {
				$this->Flash->error('Please choose school to combine with.');
			} elseif (empty($selectedStudentIds)) {
				$this->Flash->error('Please select at least one student name.');
			} else {
				$eventD = $this->Events->find()->where(['Events.id' => $eventId])->first();
				$combinedSchoolName = $this->Users->find()->where(['Users.id' => $combineWithUserId])->first();
				if (!$eventD || !$combinedSchoolName) {
					$this->Flash->error('Invalid event or school selection. Please try again.');
				} else {
					$validStudentIds = [];
					foreach ($selectedStudentIds as $sid) {
						if (isset($eventRegisteredStudents[$sid])) {
							$validStudentIds[] = (int)$sid;
						}
					}
					$validStudentIds = array_values(array_unique($validStudentIds));

					if (empty($validStudentIds)) {
						$this->Flash->error('Selected students are not registered for this event. Please select from the list.');
					} else {
                        $savedCount = 0;
                        try {
                            $teamsService->request((int)$conventionRegD->id, $schoolId, (int)$user_id, $eventId, $combineWithUserId, (array)$this->request->getData('selected_student_ids', []), $selectedPartnerStudentIds);
                            $savedCount = count($validStudentIds);
                        } catch (\DomainException $error) {
                            $this->Flash->error($error->getMessage());
                        } catch (\Exception $error) {
                            $this->log('Combined team request could not be saved: ' . $error->getMessage(), 'error');
                            $this->Flash->error('The combined-team request could not be saved. Check migration 009 is installed or contact the events team.');
                        }

						if ($savedCount > 0) {
							$adminD = $this->Admins->find()->where(['Admins.id' => 1])->first();
							$emailtemplateMessage = $this->Emailtemplates->find()->where(['Emailtemplates.id' => '22'])->first();
							if ($adminD && $emailtemplateMessage) {
								$toRepArray = array('[!school_name!]','[!combine_with_school_name!]','[!event_name!]','[!event_id_number!]','[!convention_name!]','[!season_year!]');
								$fromRepArray = array($userDetails->first_name,$combinedSchoolName->first_name,$eventD->event_name,$eventD->event_id_number,$conventionRegD->Conventions['name'],$conventionRegD->season_year);
								$subjectToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['subject']);
								$messageToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['template']);
								try {
									$email = new Email();
									$email->setEmailFormat('html')
										->setTo($adminD->email)
										->setCc(ACCOUNTS_TEAM_ANOTHER_EMAIL)
										->setFrom([HEADERS_FROM_EMAIL => HEADERS_FROM_NAME])
										->setSubject($subjectToSend)
										->send($messageToSend);
								} catch (\Throwable $exception) {
									$this->log('Combined request admin email failed: ' . $exception->getMessage(), 'error');
								}
							}

							if ($savedCount === count($validStudentIds)) {
								$this->Flash->success('Combined team requested for both schools. Admin will approve or decline the whole team; the partner school does not need to submit another request.');
							} else {
								$this->Flash->success('Request saved for '.$savedCount.' student(s). Some students could not be saved.');
							}
							return $this->redirect(['controller' => 'combinerequests', 'action' => 'viewlist']);
						}

					}
				}
			}
		}
		$this->set(compact('combinerequests', 'eventNameIDDD', 'schoolNamesDD', 'eventRegisteredStudents', 'selectedEventId', 'selectedStudentIds', 'selectedCombineWithUserId', 'partnerRegisteredStudents', 'selectedPartnerStudentIds'));
        $this->set('conventionregistrationstudents', $conventionregistrationstudents);
    }

	private function getRegisteredStudentsForEvent(int $conventionRegistrationId, int $eventId): array {
		if ($conventionRegistrationId <= 0 || $eventId <= 0) {
			return [];
		}

		$students = [];
        foreach ((new \App\Service\CombinedTeams($this->Crstudentevents->getConnection()))->students($conventionRegistrationId, $eventId) as $student) {
            $students[(int)$student['id']] = trim($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name']);
        }

		return $students;
	}

	private function resolveSelectedConventionRegistrationId($userDetails = null): int {
		if (!$userDetails || empty($userDetails->id)) {
			return 0;
		}

		$userType = (string)$this->request->getSession()->read('user_type');
		$schoolUserId = 0;
		if ($userType === 'Teacher_Parent') {
			$schoolUserId = (int)($userDetails->school_id ?? 0);
		} else {
			$schoolUserId = (int)$userDetails->id;
		}
		if ($schoolUserId <= 0) {
			return 0;
		}

		$seasonId = (int)$this->getCurrentSeason();
		if ($seasonId <= 0) {
			return 0;
		}

		$selectedConventionId = (int)$this->request->getSession()->read('sess_selected_convention_id');
		$query = $this->Conventionregistrations->find()
			->where([
				'Conventionregistrations.user_id' => $schoolUserId,
				'Conventionregistrations.season_id' => $seasonId,
			])
			->order(['Conventionregistrations.id' => 'DESC']);

		if ($selectedConventionId > 0) {
			$query->where(['Conventionregistrations.convention_id' => $selectedConventionId]);
		}

		$convReg = $query->first();
		if (!$convReg && $selectedConventionId > 0) {
			$convReg = $this->Conventionregistrations->find()
				->where([
					'Conventionregistrations.user_id' => $schoolUserId,
					'Conventionregistrations.season_id' => $seasonId,
				])
				->order(['Conventionregistrations.id' => 'DESC'])
				->first();
		}

		if (!$convReg) {
			return 0;
		}

		$this->request->getSession()->write('sess_selected_convention_registration_id', (int)$convReg->id);
		$this->request->getSession()->write('sess_selected_convention_id', (int)$convReg->convention_id);

		return (int)$convReg->id;
	}
    

}

?>
