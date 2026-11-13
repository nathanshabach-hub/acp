<?php

namespace App\Controller\Admin;

use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use App\Utility\LegacyEmail as Email;
use Cake\Datasource\ConnectionManager;

#[\AllowDynamicProperties]
class CombinerequestsController extends AppController {

    public array $paginate = ['limit' => 50, 'order' => ['Combinerequests.id' => 'desc']];

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
		
		$this->loadModel('Conventions');
		$this->loadModel('Events');
		$this->loadModel('Emailtemplates');
    }

    public function index() {

        $this->set('title', ADMIN_TITLE . 'Manage Combinerequests');
        $this->viewBuilder()->setLayout('admin');
        $this->set('manageCombinedRequests', '1');
        $this->set('combinedRequestsList', '1');
        $teams = new \App\Service\CombinedTeams($this->Combinerequests->getConnection());
        $this->set('combinedTeams', $teams->listing(null, (int)$this->request->getSession()->read('sess_admin_header_season_id')));
        $this->set('adminCombinedTeams', true);
        if (!(int)$this->request->getSession()->read('admin_id')) {
            return $this->redirect(['controller' => 'admins', 'action' => 'login']);
        }

        $separator = array();
        $condition = array();
        //$condition = array('Combinerequests.parent_id' => 0);
		
		// to check if conv season selected from header then filter list
		$sess_admin_header_season_id = $this->request->getSession()->read("sess_admin_header_season_id");
		if($sess_admin_header_season_id>0)
		{
			$condition[] = "(Combinerequests.conventionseason_id = '".$sess_admin_header_season_id."')";
		}

        if ($this->request->is('post')) {
            if (isset($this->request->getData()['action'])) {
                throw new \Cake\Http\Exception\BadRequestException('Use the individual legacy request or whole-team decision buttons.');
            }

            if (isset($this->request->getData()['Combinerequests']['keyword']) && $this->request->getData()['Combinerequests']['keyword'] != '') {
                $keyword = trim($this->request->getData()['Combinerequests']['keyword']);
            }
        } elseif ($this->request->getParam('pass')) {
            if (isset($this->request->getParam('pass', [])[0]) && $this->request->getParam('pass', [])[0] != '') {
                $searchArr = $this->request->getParam('pass', []);
                foreach ($searchArr as $val) {
                    if (strpos($val, ":") !== false) {
                        $vars = explode(":", $val, 2);
                        if ($vars[0] === 'keyword') {
                            $keyword = urldecode($vars[1]);
                        }
                    }
                }
            }
        }

        if (isset($keyword) && $keyword != '') {
            $separator[] = 'keyword:' . urlencode($keyword);
            $condition['OR'] = [
                'Combinerequests.student_name LIKE' => '%' . $keyword . '%',
                'Events.event_name LIKE' => '%' . $keyword . '%',
            ];
            $this->set('keyword', $keyword);
        }
        //pr($condition);exit;
        $separator = implode("/", $separator);
        $this->set('separator', $separator);
        $query = $this->Combinerequests->find()->contain(['Conventions', 'Users', 'Combineduser', 'Events'])->where($condition)->orderBy(['Combinerequests.id' => 'DESC']);
        if ($teams->available()) {
            $query->where(['Combinerequests.combined_team_id IS' => null]);
        }
        $rows = $this->paginate($query, ['limit' => 20]);
        $this->set('combinerequests', $rows);
        $this->request = $this->request->withAttribute('paging', ['Combinerequests' => [
            'page' => $rows->currentPage(), 'current' => $rows->count(), 'count' => $rows->totalCount(),
            'prevPage' => $rows->hasPrevPage(), 'nextPage' => $rows->hasNextPage(),
        ] + $rows->pagingParams()]);
        if ($this->request->is("ajax")) {
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Admin/Combinerequests');
            $this->render('index');
        }
    }
	
    public function decideteam($id = null, $decision = null) {
        $this->request->allowMethod(['post']);
        $adminId = (int)$this->request->getSession()->read('admin_id');
        if ($adminId <= 0) {
            throw new \Cake\Http\Exception\ForbiddenException('Administrator login required.');
        }
        if (!in_array($decision, ['approve', 'decline'], true)) {
            throw new \Cake\Http\Exception\BadRequestException('Invalid team decision.');
        }
        try {
            (new \App\Service\CombinedTeams($this->Combinerequests->getConnection()))->decide((int)$id, $adminId, $decision === 'approve');
        } catch (\DomainException $error) {
            $this->Flash->error($error->getMessage());
            return $this->redirect(['action' => 'index']);
        } catch (\Exception $error) {
            $this->log('Combined team decision failed: ' . $error->getMessage(), 'error');
            $this->Flash->error('The team decision could not be completed. No partial approval was saved.');
            return $this->redirect(['action' => 'index']);
        }
        $this->Flash->success($decision === 'approve'
            ? 'Entire team approved. Either school can upload the one shared submission.'
            : 'Entire team declined.');
        try {
            $this->notifyTeamDecision((int)$id, $decision === 'approve');
        } catch (\Exception $error) {
            $this->log('Combined team notification lookup failed for team ' . (int)$id . ': ' . $error->getMessage(), 'error');
            $this->Flash->error('The team decision was saved, but school notification details could not be loaded.');
        }
        return $this->redirect(['action' => 'index']);
    }

    private function notifyTeamDecision(int $teamId, bool $approved): void {
        $schools = $this->Combinerequests->getConnection()->execute(
            'SELECT a.email_address AS source_email, b.email_address AS partner_email, a.first_name AS source_name, b.first_name AS partner_name, e.event_name, e.event_id_number
             FROM combined_teams t JOIN conventionregistrations r ON r.id = t.source_registration_id
             JOIN conventionregistrations p ON p.id = t.partner_registration_id
             JOIN users a ON a.id = r.user_id JOIN users b ON b.id = p.user_id JOIN events e ON e.id = t.event_id
             WHERE t.id = ?', [$teamId]
        )->fetch('assoc');
        if (!$schools) {
            $this->log('Combined team decision notification has no school registrations: ' . $teamId, 'error');
            $this->Flash->error('The team decision was saved, but school notification details could not be loaded.');
            return;
        }
        $decision = $approved ? 'approved' : 'declined';
        foreach (array_unique([$schools['source_email'], $schools['partner_email']]) as $address) {
            try {
                (new Email())->setEmailFormat('text')->setTo($address)
                    ->setFrom([HEADERS_FROM_EMAIL => HEADERS_FROM_NAME])
                    ->setSubject('Combined team ' . $decision . ': ' . $schools['event_id_number'])
                    ->send('The combined team for ' . $schools['source_name'] . ' and ' . $schools['partner_name']
                        . ' in ' . $schools['event_name'] . ' (' . $schools['event_id_number'] . ') has been ' . $decision . '.'
                        . ($approved ? "\nEither school can upload or update the one shared team submission in the portal. No second request or group setup is needed." : "\nPlease contact the events team if you need to discuss this decision."));
            } catch (\Exception $error) {
                $this->log('Combined team decision email failed for team ' . $teamId . ': ' . $error->getMessage(), 'error');
                $this->Flash->error('The team decision was saved, but a school notification email could not be sent.');
            }
        }
    }

	public function approverequest($slug=null) {
        
		$requestD = $this->Combinerequests->find()->where(['Combinerequests.slug' => $slug,'Combinerequests.status' => 2])->contain(['Conventions','Users','Combineduser','Events'])->first();
		if($requestD)
		{
            if ($requestD->combined_team_id) {
                throw new \Cake\Http\Exception\BadRequestException('Approve the whole combined team instead.');
            }
			$this->Combinerequests->updateAll(['status' => '1','modified' => date('Y-m-d H:i:s', time())], ["slug"=>$slug]);
			
			// now sendning email to user who added this request
			$emailId = $requestD->Users['email_address'];
							
			$emailtemplateMessage = $this->Emailtemplates->find()->where(['Emailtemplates.id' => '23'])->first();

			$toRepArray = array('[!school_name!]','[!combine_with_school_name!]','[!event_name!]','[!event_id_number!]','[!convention_name!]','[!season_year!]');
			$fromRepArray = array($requestD->Users['first_name'],$requestD->Combineduser['first_name'],$requestD->Events['event_name'],$requestD->Events['event_id_number'],$requestD->Conventions['name'],$requestD->season_year);

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
            } catch (\Throwable $exception) {
                $this->log('Combined request approval email failed: ' . $exception->getMessage(), 'error');
            }
			
			$this->Flash->success('Request approved successfully.');
		
		}
		else
		{
			$this->Flash->error('Invalid action.');
		}
        $this->redirect(['controller'=>'combinerequests', 'action' => 'index']);
    }
	
	public function declinerequest($slug=null) {
        
		$requestD = $this->Combinerequests->find()->where(['Combinerequests.slug' => $slug,'Combinerequests.status' => 2])->contain(['Conventions','Users','Combineduser','Events'])->first();
		if($requestD)
		{
            if ($requestD->combined_team_id) {
                throw new \Cake\Http\Exception\BadRequestException('Decline the whole combined team instead.');
            }
			$this->Combinerequests->updateAll(['status' => '0','modified' => date('Y-m-d H:i:s', time())], ["slug"=>$slug]);
			
			// now sendning email to user who added this request
			$emailId = $requestD->Users['email_address'];
							
			$emailtemplateMessage = $this->Emailtemplates->find()->where(['Emailtemplates.id' => '24'])->first();

			$toRepArray = array('[!school_name!]','[!combine_with_school_name!]','[!event_name!]','[!event_id_number!]','[!convention_name!]','[!season_year!]');
			$fromRepArray = array($requestD->Users['first_name'],$requestD->Combineduser['first_name'],$requestD->Events['event_name'],$requestD->Events['event_id_number'],$requestD->Conventions['name'],$requestD->season_year);

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
            } catch (\Throwable $exception) {
                $this->log('Combined request decline email failed: ' . $exception->getMessage(), 'error');
            }
			
			$this->Flash->success('Request declined successfully.');
		
		}
		else
		{
			$this->Flash->error('Invalid action.');
		}
        $this->redirect(['controller'=>'combinerequests', 'action' => 'index']);
    }

}

?>
