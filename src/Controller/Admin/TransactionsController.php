<?php

namespace App\Controller\Admin;

use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
use App\Utility\LegacyEmail as Email;

#[\AllowDynamicProperties]
class TransactionsController extends AppController {

    public array $paginate = ['limit' => 50, 'order' => ['Transactions.name' => 'asc']];

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
		$this->loadModel('Transactionstudents');
		$this->loadModel('Settings');
		$this->loadModel('Seasons');
		$this->loadModel('Emailtemplates');
		$this->loadModel('Transactionteachers');
    }

    public function index() {

        $this->set('title', ADMIN_TITLE . 'Manage Transactions');
        $this->viewBuilder()->setLayout('admin');
        $this->set('manageTransactions', '1');
        $this->set('transactionsList', '1');

        $separator = array();
        $condition = array();
        
		// to check if conv season selected from header then filter list
		$sess_admin_header_season_id = $this->request->getSession()->read("sess_admin_header_season_id");
		if($sess_admin_header_season_id>0)
		{
			$condition[] = "(Transactions.conventionseason_id = '".$sess_admin_header_season_id."')";
		}
		
		global $priceStructureCR;
		$this->set('priceStructureCR', $priceStructureCR);
		
		global $paymentStatus;
		$this->set('paymentStatus', $paymentStatus);
		
		$conventionsDD = $this->Conventions->find()->orderBy(['Conventions.name' => 'ASC'])->all()->combine('id', 'name')->toArray();
		$this->set('conventionsDD', $conventionsDD);
		
		$seasonsDD = $this->Seasons->find()->orderBy(['Seasons.season_year' => 'DESC'])->all()->combine('season_year', 'season_year')->toArray();
		$this->set('seasonsDD', $seasonsDD);

        if ($this->request->is('post')) {
            if ($this->request->getData('action')) {
                throw new \Cake\Http\Exception\BadRequestException('Use the confirmed payment or cancellation action for each transaction.');
            }

            if (isset($this->request->getData()['Transactions']['convention_id']) && $this->request->getData()['Transactions']['convention_id'] != '') {
                $convention_id = trim($this->request->getData()['Transactions']['convention_id']);
            }
			if (isset($this->request->getData()['Transactions']['season_year']) && $this->request->getData()['Transactions']['season_year'] != '') {
                $season_year = trim($this->request->getData()['Transactions']['season_year']);
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

        if (isset($convention_id) && $convention_id != '') {
            $separator[] = 'convention_id:' . urlencode($convention_id);
            $condition[] = "(Transactions.convention_id = '".addslashes($convention_id)."')";
            $this->set('convention_id', $convention_id);
        }
		if (isset($season_year) && $season_year != '') {
            $separator[] = 'season_year:' . urlencode($season_year);
            $condition[] = "(Transactions.season_year = '".addslashes($season_year)."')";
            $this->set('season_year', $season_year);
        }
		
        //$this->prx($condition);exit;
        $separator = implode("/", $separator);
        $this->set('separator', $separator);
        $this->paginate = ['limit' => 50, 'order' => ['Transactions.id' => 'DESC']];
        $query = $this->Transactions->find()->where($condition)->contain(['Conventions', 'Users']);
        $this->set('transactions', $this->paginate($query));
        if ($this->request->is("ajax")) {
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Admin/Transactions');
            $this->render('index');
        }
    }
	
    public function deleteconfirmed($slug = null)
    {
        return $this->removeTransaction($slug, true);
    }

    public function cancelrequest($slug = null)
    {
        return $this->removeTransaction($slug, false);
    }

    private function removeTransaction($slug, bool $deleteConfirmed)
    {
        $this->request->allowMethod(['get', 'post']);
        if (!$this->request->getSession()->read('admin_id')) {
            throw new \Cake\Http\Exception\ForbiddenException('Administrator access is required.');
        }
        $transaction = $this->Transactions->find()->where(['Transactions.slug' => $slug])->contain(['Users', 'Conventions'])->first();
        if (!$transaction) {
            throw new \Cake\Http\Exception\NotFoundException('Transaction not found.');
        }
        if ($this->request->is('post')) {
            $reason = $this->request->getData('reason');
            $confirmation = $deleteConfirmed ? 'confirm_delete' : 'confirm_cancel';
            if ($this->request->getData($confirmation) !== '1' || !is_string($reason)) {
                $this->Flash->error('Enter a reason and confirm permanent removal.');
            } else {
                try {
                    $service = new \App\Service\TransactionCancellation($this->Transactions->getConnection());
                    $method = $deleteConfirmed ? 'deleteConfirmed' : 'cancel';
                    $service->{$method}((string)$slug, (int)$this->request->getSession()->read('admin_id'), $reason);
                    $this->Flash->success($deleteConfirmed
                        ? 'Confirmed transaction permanently deleted. No refund was issued. These registrations can be billed again.'
                        : 'Unpaid request permanently removed. The school can submit these registrations again.');
                    return $this->redirect(['action' => 'index']);
                } catch (\DomainException $error) {
                    $this->Flash->error($error->getMessage());
                } catch (\Exception $error) {
                    $this->log('Transaction removal failed: ' . $error->getMessage(), 'error');
                    $this->Flash->error('Removal failed. Please contact the system administrator.');
                }
            }
        }
        $this->set(compact('transaction', 'deleteConfirmed'));
        $this->set('title', ADMIN_TITLE . ($deleteConfirmed ? 'Delete Confirmed Transaction' : 'Cancel Unpaid Request'));
        $this->viewBuilder()->setLayout('admin')->setTemplate('cancelrequest');
    }

    public function invoice($slug = null)
    {
        $this->request->allowMethod(['get', 'post']);
        if ((int)$this->request->getSession()->read('admin_id') <= 0) {
            throw new \Cake\Http\Exception\ForbiddenException('Administrator access is required.');
        }
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
        $service = new \App\Service\TransactionInvoices($this->Transactions->getConnection());
        try {
            $transaction = $service->transaction((string)$slug);
        } catch (\DomainException $error) {
            throw new \Cake\Http\Exception\BadRequestException($error->getMessage());
        }
        $returnUrl = ['action' => 'viewdetails', $slug, '?' => ['invoice' => 'open'], '#' => 'generate-invoice'];
        if ($this->request->is('get')) {
            return $this->redirect($returnUrl);
        }
        $available = $service->available();
        if ($this->request->is('post')) {
            if (!$available) {
                $this->Flash->error('Invoice generation is unavailable until migration 013 is applied.');
            } else {
                $operation = $this->request->getData('operation');
                if (!in_array($operation, ['generate', 'send'], true)) {
                    throw new \Cake\Http\Exception\BadRequestException('Invalid invoice action.');
                }
                try {
                    $adminId = (int)$this->request->getSession()->read('admin_id');
                    if ($operation === 'generate') {
                        $bankDetails = $this->request->getData('bank_details');
                        if (!is_string($bankDetails) || $this->request->getData('confirm_generate') !== '1') {
                            throw new \DomainException('Enter bank details and confirm the billing amounts before generating the invoice.');
                        }
                        $service->generate((string)$slug, $adminId, $bankDetails);
                        $this->Flash->success('Invoice saved. Download the PDF or send it to the school below. Payment status is unchanged.');
                    } else {
                        if ($this->request->getData('confirm_send') !== '1') {
                            throw new \DomainException('Confirm that the invoice should be emailed to the school.');
                        }
                        $sent = $service->send((string)$slug, $adminId);
                        if ($sent) {
                            $this->Flash->success('Invoice email accepted for delivery to the school.');
                        } else {
                            $this->Flash->warning('Invoice email captured by the test transport only. No external email was sent.');
                        }
                    }
                    return $this->redirect($returnUrl);
                } catch (\DomainException $error) {
                    $this->Flash->error($error->getMessage());
                } catch (\Exception $error) {
                    $this->log('Invoice ' . (string)$operation . ' failed for transaction #' . (int)$transaction['id'] . ': ' . $error->getMessage(), 'error');
                    $this->Flash->error('The invoice operation failed. The invoice may already exist or the email may have been accepted; review the saved invoice and contact accounts before retrying.');
                }
            }
        }
        return $this->redirect($returnUrl);
    }

    private function invoiceData(string $slug): array
    {
        $service = new \App\Service\TransactionInvoices($this->Transactions->getConnection());
        $transaction = $service->transaction($slug);
        $available = $service->available();
        $invoice = $service->find((int)$transaction['id']);
        $bankDetails = '';
        $charges = null;
        if (!$invoice) {
            try {
                $charges = $service->charges($transaction);
            } catch (\DomainException $error) {
                $this->Flash->error($error->getMessage());
            }
        }
        return compact('transaction', 'available', 'invoice', 'charges', 'bankDetails');
    }

    public function invoicepdf($slug = null)
    {
        $this->request->allowMethod(['get']);
        if ((int)$this->request->getSession()->read('admin_id') <= 0) {
            throw new \Cake\Http\Exception\ForbiddenException('Administrator access is required.');
        }
        $service = new \App\Service\TransactionInvoices($this->Transactions->getConnection());
        try {
            $transaction = $service->transaction((string)$slug);
        } catch (\DomainException $error) {
            throw new \Cake\Http\Exception\BadRequestException($error->getMessage());
        }
        $invoice = $service->find((int)$transaction['id']);
        if (!$invoice) {
            throw new \Cake\Http\Exception\NotFoundException('Generate this invoice before downloading it.');
        }
        return $this->getResponse()->withType('pdf')
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withDownload($invoice['number'] . '.pdf')
            ->withStringBody($service->pdf($invoice, (int)$transaction['status'] === 1));
    }

	public function viewdetails($slug = null) {
        if ((int)$this->request->getSession()->read('admin_id') <= 0) {
            throw new \Cake\Http\Exception\ForbiddenException('Administrator access is required.');
        }
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
        $this->set('title', ADMIN_TITLE . 'Transaction details');
        $this->viewBuilder()->setLayout('admin');
        $this->set('manageTransactions', '1');
        $this->set('transactionsList', '1');
		
		global $priceStructureCR;
		$this->set('priceStructureCR', $priceStructureCR);
		
		global $paymentStatus;
		$this->set('paymentStatus', $paymentStatus);
		
		$changePaymentS = array(
			'1' => 'Yes'
		);
		$this->set('changePaymentS', $changePaymentS);
		
        if ($slug)
		{
            $transactionD = $this->Transactions->find()->where(['Transactions.slug' => $slug])->contain(['Conventions','Users'])->first();
			$this->set('transactionD', $transactionD);
			$prevPaymentStatus = $paymentStatus[$transactionD->status];
            
			if($transactionD)
			{
                if (in_array((int)$transactionD->status, [1, 2, 3], true)) {
                    $this->set('invoiceData', $this->invoiceData((string)$slug));
                }
				// to get the students list of this transaction
				$transactionStudents = $this->Transactionstudents->find()->where(['Transactionstudents.transaction_id' => $transactionD->id])->order(["Transactionstudents.id" => "ASC"])->contain(['Users'])->all();
				$this->set('transactionStudents', $transactionStudents);
				
				// to get the teachers list of this transaction
				$transactionTeachers = $this->Transactionteachers->find()->where(['Transactionteachers.transaction_id' => $transactionD->id])->order(["Transactionteachers.id" => "ASC"])->contain(['Users'])->all();
				$this->set('transactionTeachers', $transactionTeachers);

				$normalizeGender = static function ($genderValue) {
					$gender = strtolower(trim((string)$genderValue));
					if ($gender === 'm' || $gender === 'male' || $gender === 'boy') {
						return 'male';
					}
					if ($gender === 'f' || $gender === 'female' || $gender === 'girl') {
						return 'female';
					}
					return 'other';
				};

				$studentGenderBreakdown = ['male' => 0, 'female' => 0, 'other' => 0, 'total' => 0];
				foreach ($transactionStudents as $studentRow) {
					$bucket = $normalizeGender($studentRow->Users['gender'] ?? null);
					$studentGenderBreakdown[$bucket]++;
					$studentGenderBreakdown['total']++;
				}

				$teacherGenderBreakdown = ['male' => 0, 'female' => 0, 'other' => 0, 'total' => 0];
				foreach ($transactionTeachers as $teacherRow) {
					$bucket = $normalizeGender($teacherRow->Users['gender'] ?? null);
					$teacherGenderBreakdown[$bucket]++;
					$teacherGenderBreakdown['total']++;
				}

				$overallGenderBreakdown = [
					'male' => $studentGenderBreakdown['male'] + $teacherGenderBreakdown['male'],
					'female' => $studentGenderBreakdown['female'] + $teacherGenderBreakdown['female'],
					'other' => $studentGenderBreakdown['other'] + $teacherGenderBreakdown['other'],
					'total' => $studentGenderBreakdown['total'] + $teacherGenderBreakdown['total'],
				];

				$this->set('studentGenderBreakdown', $studentGenderBreakdown);
				$this->set('teacherGenderBreakdown', $teacherGenderBreakdown);
				$this->set('overallGenderBreakdown', $overallGenderBreakdown);
				
				// save form data
				if ($this->request->is('post'))
				{
					//$this->prx($this->request->getData());
					
					if($transactionD->status == 2 || $transactionD->status == 3)
					{
						$transaction_id_received 	= $this->request->getData()['Transactions']['transaction_id_received'];
						$transaction_data 			= $this->request->getData()['Transactions']['transaction_data'];
						
                        if (!(new \App\Service\TransactionCancellation($this->Transactions->getConnection()))->confirm(
                            (int)$transactionD->id, (string)$transaction_id_received, (string)$transaction_data
                        )) {
                            $this->Flash->error('This transaction was cancelled or already confirmed. Reload before continuing.');
                            return $this->redirect(['action' => 'index']);
                        }
						
						// Now send email to school admin
						$emailId = $transactionD->Users['email_address'];
							
						$emailtemplateMessage = $this->Emailtemplates->find()->where(['Emailtemplates.id' => '9'])->first();

						$toRepArray = array('[!school_name!]','[!convention_name!]','[!season_year!]','[!CURR!]','[!total_amount!]','[!previous_payment_status!]','[!customer_code!]');
						$fromRepArray = array($transactionD->Users['first_name'],$transactionD->Conventions['name'],$transactionD->season_year,CURR,number_format($transactionD->total_amount,2),$prevPaymentStatus,$transactionD->Users['customer_code']);

						$subjectToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['subject']);
						$messageToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['template']);
						
						//echo $messageToSend; exit;
						
						$email = new Email();
						try {
							$email->setEmailFormat('html')
								->setTo($emailId)
								->setCc(HEADERS_CC)
								->setFrom([HEADERS_FROM_EMAIL => HEADERS_FROM_NAME])
								->setSubject($subjectToSend)
								->setBodyHtml($messageToSend)
								->send();
						} catch (\Throwable $exception) {
							$this->log('Transaction confirmation email failed for slug ' . $slug . ': ' . $exception->getMessage(), 'error');
							$this->Flash->warning('Payment was confirmed, but the notification email could not be sent. Please retry sending email later.');
						}
						
						$this->Flash->success('Payment transaction status confirmed successfully.');
					}
					else
					{
						$this->Flash->error('You cannot change payment transaction status.');
					}
					
					$this->redirect(['controller' => 'transactions', 'action' => 'index']);
					
				}
				
			}
			else
			{
				$this->Flash->error('Transaction not found.');
				$this->redirect(['controller' => 'transactions', 'action' => 'index']);
			}
        }
		else
		{
			$this->Flash->error('Invalid transaction.');
			$this->redirect(['controller' => 'transactions', 'action' => 'index']);
		}
		
    }

}

?>
