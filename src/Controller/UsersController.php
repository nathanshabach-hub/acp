<?php

// src/Controller/UserssController.php

namespace App\Controller;

use App\Controller\AppController;
use Cake\Event\EventInterface;
use App\Utility\LegacyEmail as Email;
use Cake\Mailer\Mailer;
use Cake\Core\Configure;
use Cake\Core\Configure\Engine\PhpConfig;
 

#[\AllowDynamicProperties]
class UsersController extends AppController {
	public $Conventionregistrationstudents = null;
	public $Evaluationforms = null;

    public function initialize(): void {
        parent::initialize();

        $this->loadComponent('RequestHandler');
        $this->loadComponent('PImage');
        $this->loadComponent('Auth');
        $this->loadComponent('PImageTest');
        $this->loadComponent('Flash');
    }

    
    public function beforeFilter(EventInterface $event): void {
        parent::beforeFilter($event);

        $this->Auth->allow(['notificationinvoice']);
        $this->Auth->allow(['login','registration','registerprevdetails','forgotpassword','resetpassword','teachersetpassword','dashboard','notifications','notificationaction','crossschoolrequests','cancelcrossschoolrequest','myschedule','myevents','editprofile','changepassword','teachers','editteacher','addteacher','archiveteacher','restoreteacher','students','addstudent','editstudent','archivestudent','restorestudent','generatestudentlogincode','generateallstudentlogincodes','judgesregistration','squad247','squad247submit','logout','judgesconfirmation','judgeeditprofile','applyforjudge','switchprofile','judgeexperience','judgingform','logintotest']);

        $this->loadModel("Emailtemplates");
        $this->loadModel("Conventions");
        $this->loadModel("Conventionseasons");
        $this->loadModel("Events");
        $this->loadModel("Eventsubmissions");
        $this->loadModel("Schedulingtimings");
        $this->loadModel("Divisions");
        $this->loadModel("Seasons");
        $this->loadModel("Conventionregistrations");
        $this->loadModel("Conventionregistrationteachers");
        $this->loadModel("Conventionregistrationstudents");
        $this->loadModel("Crstudentevents");
        $this->loadModel("Judgeevaluations");
        
        $this->loadModel("Evaluationforms");
        $this->loadModel("Settings");
    }
    
    public function login($convention_slug=null,$season_id=null) {
        
        $this->viewBuilder()->setLayout("home");		
        $this->set('title_for_layout', 'Login '.TITLE_FOR_PAGES);
        $this->response = $this->response->withHeader('Cache-Control', 'no-store');
        
        $user_id = $this->request->getSession()->read("user_id");
        
        $this->set('header_menu_login_active', 'active');
        
        global $loginUserTypes;
        $this->set('loginUserTypes', $loginUserTypes);
        
        // Set empty Users entity for form rendering
        $users = $this->Users->newEmptyEntity();
        $this->set('users', $users);
        
        if ($this->request->is('post'))
        {	
            $formData = (array)$this->request->getData('Users');
            foreach (['email_address', 'password', 'user_type', 'student_code'] as $key) {
                if (isset($formData[$key]) && !is_string($formData[$key])) {
                    throw new \Cake\Http\Exception\BadRequestException('Invalid login fields.');
                }
            }

            $email_address = trim((string)($formData['email_address'] ?? ''));
            $password = (string)($formData['password'] ?? '');
            $user_type = trim((string)($formData['user_type'] ?? ''));
            $student_code = strtoupper(trim((string)($formData['student_code'] ?? '')));
            $studentCredentials = null;
            if (!array_key_exists($user_type, $loginUserTypes)) {
                $this->Flash->error('Please choose a valid account type.');
                return;
            }

            if ($user_type === 'Student') {
                try {
                    $portal = new \App\Service\StudentPortalAccounts($this->Users->getConnection());
                    if (!$portal->allowAttempt($this->request->clientIp(), $student_code)) {
                        $this->Flash->error('Too many student login attempts. Please wait 15 minutes.');
                        return;
                    }
                    $studentCredentials = $portal->authenticate($student_code, $password);
                    $userInfo = $this->Users->find()->where(['Users.id' => (int)$studentCredentials['student_id'], 'Users.user_type' => 'Student'])->first();
                } catch (\DomainException $error) {
                    $this->Flash->error('Invalid student ID or password. For first-time setup or a forgotten password, ask your school for a setup code.');
                    return;
                } catch (\Exception $error) {
                    $this->log('Student login unavailable: ' . $error->getMessage(), 'error');
                    $this->Flash->error('Student login is unavailable. Please contact the events team.');
                    return;
                }
            } else {
                // Step 1 :: To check in users table
                $userInfo = $this->Users->find()->where(['Users.email_address' => $email_address, 'Users.user_type' => $user_type])->first();
            }
            //$this->prx($userInfo);
            if ($userInfo)
            {
                if ($userInfo->activation_status == 0) {
                    $this->Flash->error('Your account has not yet verified.');
                }
                elseif ($userInfo->status == 0) {
                    $this->Flash->error('Your account got temporary disabled. Please contact events team.');
                }
                elseif ($userInfo->status == 2) {
                    $this->Flash->error('Your account is archived. Please contact events team.');
                }	
                elseif ($userInfo->status == 3) {
                    $this->Flash->error('Your account is rejected. Please contact events team.');
                }				
                elseif (($user_type === 'Student' && $studentCredentials !== null) || ($user_type !== 'Student' && crypt($password, $userInfo->password) == $userInfo->password)) {
                    
                    
                    //$this->prx($userInfo);

                    $this->request->getSession()->renew();
                    $this->request->getSession()->delete('student_portal_version');
                    $this->request->getSession()->delete('current_session_profile_type');
                    $this->request->getSession()->delete('sess_selected_convention_registration_id');
                    $this->request->getSession()->delete('sess_selected_convention_id');
                    if ($studentCredentials !== null) {
                        $this->request->getSession()->write('student_portal_version', (int)$studentCredentials['credential_version']);
                    }
                    $this->request->getSession()->write("user_id", $userInfo->id);
                    $this->request->getSession()->write("email_address", $userInfo->email_address);
                    $this->request->getSession()->write("user_type", $userInfo->user_type);
                    $this->request->getSession()->write("last_login", $userInfo->last_login);
                    
                    if($userInfo->user_type == "Teacher_Parent" && $userInfo->is_judge == "1")
                    {
                        $this->request->getSession()->write("current_session_profile_type", 'Supervisor');
                    }
                    
                    // update last login
                    $this->Users->updateAll(['forgot_password_status' => '0','last_login' => date('Y-m-d H:i:s')], ["id" => $userInfo->id]);
                    
                    
                    if($user_type !== 'Student' && $convention_slug && $season_id>0)
                    {
                        // now check if registration for convention required
                        // 1. Check convention exists
                        $conventionD = $this->Conventions->find()->where(['Conventions.slug' => $convention_slug])->first();
                        if($conventionD)
                        {
                            // to get season details
                            $seasonD = $this->Seasons->find()->where(['Seasons.id' => $season_id])->first();
                            if($seasonD)
                            {
                                // enter this user record in conventionregistrations table
                                $convention_id 	= $conventionD->id;
                                $user_id 		= $userInfo->id;
                                
                                // get the convention season record so we can store conventionseason_id
                                $convSeasonD = $this->Conventionseasons->find()->where(['Conventionseasons.convention_id' => $convention_id,'Conventionseasons.season_id' => $season_id,'Conventionseasons.season_year' => $seasonD->season_year])->first();
                                
                                // to check if this record already exists
                                $checkRegExists = $this->Conventionregistrations->find()->where(['Conventionregistrations.convention_id' => $convention_id,'Conventionregistrations.user_id' => $user_id,'Conventionregistrations.season_id' => $season_id])->first();
                                if(!$checkRegExists)
                                {
                                    // insert new record
                                    $dataCR = $this->Conventionregistrations->newEntity([]);

                                    $dataCR->slug 					= "convention-registration-".$convention_id.'-'.$user_id.'-'.$season_id.'-'.time();
                                    $dataCR->convention_id			= $convention_id;
                                    $dataCR->conventionseason_id	= $convSeasonD ? $convSeasonD->id : null;
                                    $dataCR->user_id				= $user_id;
                                    $dataCR->season_id				= $season_id;
                                    $dataCR->season_year 			= $seasonD->season_year;
                                    $dataCR->status 				= 1;
                                    
                                    $dataCR->created 				= date('Y-m-d H:i:s');
                                    $dataCR->modified 				= NULL;

                                    $resultCR 		= $this->Conventionregistrations->save($dataCR);
                                }
                                elseif($checkRegExists && $convSeasonD && !$checkRegExists->conventionseason_id)
                                {
                                    // fix existing registrations missing conventionseason_id
                                    $this->Conventionregistrations->updateAll(['conventionseason_id' => $convSeasonD->id], ['id' => $checkRegExists->id]);
                                }
                            }
                        }
                    }
                    
                    
                    $returnUrl = $this->request->getSession()->read("returnUrl");
                    
                    if(isset($returnUrl) && !empty($returnUrl))
                    {						
                        $this->request->getSession()->delete("returnUrl");
                        $safeReturnUrl = '/' . ltrim((string)$returnUrl, '/');
                        $this->redirect($safeReturnUrl);
                    }
                    else
                    {
                        $this->request->getSession()->delete("returnUrl");
                        $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
                    }
                    
                } else {
                    $this->Flash->error('Invalid email or password.');
                }
            }
        
            if(!$userInfo)
            {
                if ($user_type === 'Student') {
                    $this->Flash->error('Invalid student code or last name details.');
                } else {
                    $this->Flash->error('Invalid email. Account not found in system.');
                }
            }
            
        } //end if ($this->request->is('post'))
        
    }
    
    public function studentsetup() {
        $this->request->allowMethod(['get', 'post']);
        $this->viewBuilder()->setLayout('home');
        $this->set('title_for_layout', 'Student Password Setup ' . TITLE_FOR_PAGES);
        $this->response = $this->response->withHeader('Cache-Control', 'no-store');
        if ($this->request->is('post')) {
            $input = (array)$this->request->getData('Student');
            foreach (['login_id', 'code', 'password', 'confirmation'] as $key) {
                if (!is_string($input[$key] ?? null)) {
                    throw new \Cake\Http\Exception\BadRequestException('Complete all student setup fields.');
                }
            }
            try {
                $portal = new \App\Service\StudentPortalAccounts($this->Users->getConnection());
                if (!$portal->allowAttempt($this->request->clientIp(), $input['login_id'])) {
                    $this->Flash->error('Too many student setup attempts. Please wait 15 minutes.');
                    return;
                }
                $portal->setup($input['login_id'], $input['code'], $input['password'], $input['confirmation']);
                $this->Flash->success('Student password saved. Select Student and sign in with your student ID and new password.');
                return $this->redirect(['action' => 'login']);
            } catch (\DomainException $error) {
                $this->Flash->error($error->getMessage());
            } catch (\Exception $error) {
                $this->log('Student password setup failed: ' . $error->getMessage(), 'error');
                $this->Flash->error('Student password setup is unavailable. Please contact the events team.');
            }
        }
    }

    public function openstudentportal() {
        $this->request->allowMethod(['post']);
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $school = $this->crossSchoolAccount();
        $session = $this->request->getSession();
        if ($session->read('school_student_access') !== null) {
            throw new \Cake\Http\Exception\ForbiddenException('Return to your school account before opening another student portal.');
        }
        $slug = $this->request->getData('student_slug');
        if (!is_string($slug) || $slug === '') {
            throw new \Cake\Http\Exception\BadRequestException('Select a student from your school list.');
        }
        $student = $this->Users->find()->where([
            'Users.slug' => $slug, 'Users.school_id' => $school->id, 'Users.user_type' => 'Student',
            'Users.status' => 1, 'Users.activation_status' => 1,
        ])->first();
        if (!$student) {
            throw new \Cake\Http\Exception\NotFoundException('Active, verified student not found in your school.');
        }
        $context = [];
        foreach (['sess_selected_convention_registration_id', 'sess_selected_convention_id', 'current_session_profile_type', 'last_login', 'returnUrl'] as $key) {
            $context[$key] = $session->read($key);
            $session->delete($key);
        }
        $session->renew();
        $session->write('school_student_access', [
            'school_id' => (int)$school->id, 'student_id' => (int)$student->id,
            'student_name' => trim($student->first_name . ' ' . $student->last_name),
            'context' => $context,
        ]);
        $session->delete('student_portal_version');
        $session->write('user_id', (int)$student->id);
        $session->write('user_type', 'Student');
        $session->write('email_address', $student->email_address);
        $this->log('School student portal opened: school ' . (int)$school->id . ', student ' . (int)$student->id, 'info');
        return $this->redirect(['action' => 'dashboard']);
    }

    public function returntoschool() {
        $this->request->allowMethod(['post']);
        $session = $this->request->getSession();
        $access = $session->read('school_student_access');
        if (!is_array($access) || $session->read('user_type') !== 'Student'
            || (int)$session->read('user_id') !== (int)($access['student_id'] ?? 0)) {
            throw new \Cake\Http\Exception\ForbiddenException('No school student-view session is active.');
        }
        $school = $this->Users->find()->where([
            'Users.id' => (int)($access['school_id'] ?? 0), 'Users.user_type' => 'School',
            'Users.status' => 1, 'Users.activation_status' => 1,
        ])->first();
        if (!$school) {
            $this->Flash->error('Your school account is no longer active. Please sign in again.');
            return $this->logout();
        }
        $session->renew();
        $session->write('user_id', (int)$school->id);
        $session->write('user_type', 'School');
        $session->write('email_address', $school->email_address);
        foreach (['sess_selected_convention_registration_id', 'sess_selected_convention_id', 'current_session_profile_type', 'last_login', 'returnUrl'] as $key) {
            $session->delete($key);
            if (isset($access['context'][$key])) {
                $session->write($key, $access['context'][$key]);
            }
        }
        $session->delete('school_student_access');
        $session->delete('student_portal_version');
        $this->log('School student portal closed: school ' . (int)$school->id . ', student ' . (int)$access['student_id'], 'info');
        $this->Flash->success('Returned to your school account.');
        return $this->redirect(['action' => 'students']);
    }

    public function bulkstudentaccess() {
        $this->request->allowMethod(['post']);
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $school = $this->crossSchoolAccount();
        $mode = $this->request->getData('mode');
        if (!in_array($mode, ['selected', 'without_password'], true)) {
            throw new \Cake\Http\Exception\BadRequestException('Choose a valid bulk setup option.');
        }
        $ids = [];
        if ($mode === 'selected') {
            $selected = $this->request->getData('student_ids', []);
            if (!is_array($selected)) {
                throw new \Cake\Http\Exception\BadRequestException('Invalid student selection.');
            }
            foreach ($selected as $id) {
                if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id <= 0) {
                    throw new \Cake\Http\Exception\BadRequestException('Invalid student selection.');
                }
                $ids[] = (int)$id;
            }
        }
        $this->viewBuilder()->setLayout('home');
        $this->set('title_for_layout', 'Bulk Student Setup Codes ' . TITLE_FOR_PAGES);
        $this->set('active_students', 'active');
        $this->set('userDetails', $school);
        $this->set('mode', $mode);
        $this->response = $this->response->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Referrer-Policy', 'no-referrer');
        try {
            $portal = new \App\Service\StudentPortalAccounts($this->Users->getConnection());
            if ($this->request->getData('confirm_issue') === '1') {
                $this->set('issued', $portal->issueBulk((int)$school->id, $ids, $mode === 'without_password'));
            } else {
                $this->set('students', $portal->bulkStudents((int)$school->id, $ids, $mode === 'without_password'));
            }
        } catch (\DomainException $error) {
            $this->Flash->error($error->getMessage());
            return $this->redirect(['action' => 'students']);
        } catch (\Exception $error) {
            $this->log('Bulk student code issuance failed for school ' . (int)$school->id . ': ' . $error->getMessage(), 'error');
            $this->Flash->error('Bulk setup is unavailable. No code changes were saved. Please contact the events team.');
            return $this->redirect(['action' => 'students']);
        }
    }

    public function studentaccess($slug = null) {
        $this->request->allowMethod(['get', 'post']);
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $schoolId = (int)$this->request->getSession()->read('user_id');
        if ($this->request->getSession()->read('user_type') !== 'School') {
            throw new \Cake\Http\Exception\ForbiddenException('Only the student\'s school can issue setup codes.');
        }
        $student = $this->Users->find()->where([
            'Users.slug' => $slug, 'Users.school_id' => $schoolId, 'Users.user_type' => 'Student',
        ])->first();
        if (!$student) {
            throw new \Cake\Http\Exception\NotFoundException('Student not found in your school Global Students List.');
        }
        $this->viewBuilder()->setLayout('home');
        $this->set('title_for_layout', 'Student Portal Access ' . TITLE_FOR_PAGES);
        $this->set('active_students', 'active');
        $this->set('userDetails', $this->Users->get($schoolId));
        $this->set('student', $student);
        $this->response = $this->response->withHeader('Cache-Control', 'no-store')->withHeader('Referrer-Policy', 'no-referrer');
        $portal = new \App\Service\StudentPortalAccounts($this->Users->getConnection());
        $this->set('access', $portal->account((int)$student->id));
        if ($this->request->is('post')) {
            if ($this->request->getData('confirm_issue') !== '1') {
                $this->Flash->error('Confirm that you want to issue a new single-use code.');
                return;
            }
            try {
                $this->set('issued', $portal->issue((int)$student->id, $schoolId));
                $this->set('access', $portal->account((int)$student->id));
            } catch (\DomainException $error) {
                $this->Flash->error($error->getMessage());
            } catch (\Exception $error) {
                $this->log('Student setup code issuance failed for student ' . (int)$student->id . ': ' . $error->getMessage(), 'error');
                $this->Flash->error('A code could not be issued. Please contact the events team.');
            }
        }
    }

    public function registration($conventionregistration_slug=null) {
        
        $this->viewBuilder()->setLayout("home");		
        $this->set('title_for_layout', 'Registration '.TITLE_FOR_PAGES);
        
        $this->set('conventionregistration_slug', $conventionregistration_slug);
        
        $conventionRegD = $this->Conventionregistrations->find()->where(['Conventionregistrations.slug' => $conventionregistration_slug])->first();
        if($conventionRegD)
        {
            $this->set('conventionRegD', $conventionRegD);
        }
        else
        {
            $this->Flash->error('Invalid registration information.');
            $this->redirect(['controller' => 'homes', 'action' => 'index']);
        }
        
        $user_id = $conventionRegD->user_id;
        
        
        $users = $this->Users->get($user_id);

        if ($this->request->is(['post', 'put'])) {
            $data = $this->Users->patchEntity($users, $this->request->getData());
            
            if (count($data->getErrors()) == 0) {
                
                //$this->prx($this->request->getData());
                
                $new_password 	= $data->password;
                $salt 			= uniqid(mt_rand(), true);
                $password 		= crypt($new_password, '$2a$07$' . $salt . '$');
                
                // update details
                $this->Users->updateAll([
                'first_name' => $data->first_name,
                'middle_name' => $data->middle_name,
                'phone' => $data->phone,
                'password' => $password
                ], ["id" => $user_id]);
                
                $userInfo = $this->Users->find()->where(['Users.id' => $user_id])->first();
                
                // now create a session for this user
                $this->request->getSession()->write("user_id", $userInfo->id);
                $this->request->getSession()->write("email_address", $userInfo->email_address);
                $this->request->getSession()->write("user_type", $userInfo->user_type);
                $this->request->getSession()->write("first_name", $userInfo->first_name);
                //$this->request->getSession()->write("last_login", $userInfo->last_login);
                
                $this->Flash->success('Profile details updated successfully.');
                $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
            }
        }
        $this->set('users', $users);
        
        
    }
    
    public function registerprevdetails($convention_slug=null,$season_id=null) {
        
        $this->viewBuilder()->setLayout("home");		
        $this->set('title_for_layout', 'Register Using Previous Details '.TITLE_FOR_PAGES);
        
        $this->set('convention_slug',$convention_slug);
        $this->set('season_id',$season_id);
        
        // check convention details
        $conventionD = $this->Conventions->find()->where(['Conventions.slug' => $convention_slug])->first();
        if($conventionD)
        {
            // to get season details
            $seasonD = $this->Seasons->find()->where(['Seasons.id' => $season_id])->first();
        }
        else
        {
            $this->Flash->error('Invalid details.');
            $this->redirect(['controller' => 'homes', 'action' => 'index']);
        }
        
        if ($this->request->is('post'))
        {	
            //$this->prx($this->request->getData());
            $customer_code	= $this->request->getData()['Users']['customer_code'];
            $password		= $this->request->getData()['Users']['password'];
            
            // Step 1 :: To check in users table
            $userInfo = $this->Users->find()->where(['Users.customer_code' => $customer_code,'Users.user_type' => 'School'])->first();
            //echo crypt($password, $userInfo->password);
            //$this->prx($userInfo);
            if ($userInfo)
            {
                if ($userInfo->activation_status == 0) {
                    $this->Flash->error('Your account has not yet active.');
                }
                elseif ($userInfo->status == 0) {
                    $this->Flash->error('Your account got temporary disabled.');
                } 
                elseif (!empty($userInfo) && crypt($password, $userInfo->password) == $userInfo->password) {

                    $this->request->getSession()->write("user_id", $userInfo->id);
                    $this->request->getSession()->write("email_address", $userInfo->email_address);
                    $this->request->getSession()->write("user_type", $userInfo->user_type);
                    $this->request->getSession()->write("last_login", $userInfo->last_login);
                    
                    // update last login
                    $this->Users->updateAll(['forgot_password_status' => '0','last_login' => date('Y-m-d H:i:s')], ["id" => $userInfo->id]);
                    
                    $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
                    
                    
                } else {
                    $this->Flash->error('Invalid customer code or password.');
                }
            }
        
            if(!$userInfo)
            {
                $this->Flash->error('Invalid customer codes. Account not found in system.');
            }
            
        } //end if ($this->request->is('post'))
        
        
    }
    
    public function forgotpassword() {
        $this->request->allowMethod(['get', 'post']);
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer'));
        $this->viewBuilder()->setLayout('home');
        $this->set('title_for_layout', 'Forgot Password ' . TITLE_FOR_PAGES);
        $this->set('loginUserTypes', ['School' => 'School', 'Teacher_Parent' => 'Supervisor', 'Judge' => 'Judge']);
        $this->set('users', $this->Users->newEmptyEntity());
        if (!$this->request->is('post')) {
            return;
        }
        $service = new \App\Service\PasswordReset($this->Users->getConnection());
        try {
            $config = $service->configuration();
        } catch (\RuntimeException $error) {
            \Cake\Log\Log::error('Password reset unavailable: ' . $error->getMessage() . ' Deployment: ' . json_encode([
                'environment' => \App\Deployment\Environment::settings()['environment'],
                'configFile' => getenv('ACP_CONFIG_FILE') ?: '(not set)',
                'resetMailEnabled' => (\App\Deployment\Environment::settings()['passwordResetMail']['enabled'] ?? false) === true,
            ], JSON_UNESCAPED_SLASHES));
            $this->Flash->error('Password reset email is not configured. Please contact the events team.');
            return $this->redirect(['action' => 'forgotpassword']);
        }
        $email = trim((string)$this->request->getData('Users.email_address'));
        $type = (string)$this->request->getData('Users.user_type');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || !in_array($type, ['School', 'Teacher_Parent', 'Judge'], true)) {
            $this->Flash->error('Enter a valid email address and choose an account type.');
            return $this->redirect(['action' => 'forgotpassword']);
        }
        if (!$service->recipientAllowed($config, $email)) {
            $this->Flash->error('Local password reset is restricted to approved test mailboxes.');
            return $this->redirect(['action' => 'forgotpassword']);
        }
        $ip = (string)($this->request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($ip === '' || !$service->allowRequest($ip)) {
            $this->Flash->error('Too many requests or unable to verify the connection. Please try again later.');
            return $this->redirect(['action' => 'forgotpassword']);
        }
        $accounts = $this->Users->find()->where([
            'Users.email_address' => $email, 'Users.user_type' => $type,
            'Users.status' => 1, 'Users.activation_status' => 1,
        ])->contain(['Schools'])->orderBy(['Users.id' => 'ASC'])->all()->toList();
        if ($accounts) {
            $issued = [];
            $links = [];
            try {
                $this->Users->getConnection()->transactional(function () use ($accounts, $service, &$issued, &$links) {
                    foreach ($accounts as $account) {
                        $token = $service->issue((int)$account->id, (string)$account->email_address);
                        if ($token === null) {
                            continue;
                        }
                        $issued[(int)$account->id] = $token;
                        $name = trim($account->first_name . ' ' . $account->last_name);
                        $schoolName = $account->Schools ? trim((string)$account->Schools->first_name) : '';
                        $label = $name . ' - Account #' . (int)$account->id;
                        if ($schoolName !== '') {
                            $label .= ' - ' . $schoolName;
                        }
                        $links[] = [
                            'label' => $label,
                            'url' => rtrim(HTTP_PATH, '/') . '/users/resetpassword#' . $token,
                        ];
                    }
                });
            } catch (\RuntimeException $error) {
                \Cake\Log\Log::error('Password reset token could not be issued.');
                $this->Flash->error('Password reset is unavailable. Please try again later or contact the events team.');
                return $this->redirect(['action' => 'forgotpassword']);
            }
            if ($issued) {
                $localTest = \App\Deployment\Environment::settings()['environment'] === 'local';
                try {
                    $service->send($config, (string)$accounts[0]->email_address, ($localTest ? '[LOCAL TEST] ' : '') . 'Reset your convention portal password',
                        [
                            'recipientName' => count($accounts) === 1
                                ? trim($accounts[0]->first_name . ' ' . $accounts[0]->last_name)
                                : 'convention portal account holder',
                            'links' => $links,
                        ]);
                } catch (\Exception $error) {
                    foreach ($issued as $id => $issuedToken) {
                        $service->revoke($id, $issuedToken);
                    }
                    \Cake\Log\Log::error('Password reset email delivery failed.');
                    $this->Flash->error('Password reset email delivery is unavailable. Please try again later or contact the events team.');
                    return $this->redirect(['action' => 'forgotpassword']);
                }
                try {
                    $service->send($config, $config['notify'], ($localTest ? '[LOCAL TEST] ' : '') . 'Convention portal password reset requested',
                        [
                            'notification' => true,
                            'accountType' => $type,
                            'accountEmail' => (string)$accounts[0]->email_address,
                            'accountIds' => array_keys($issued),
                        ]);
                } catch (\Exception $error) {
                    \Cake\Log\Log::error('Password reset administrator notification delivery failed.');
                    $this->Flash->error('The reset request was processed, but the events-team notification could not be sent.');
                    return $this->redirect(['action' => 'forgotpassword']);
                }
            }
        }
        $this->Flash->success('If an eligible account matches, a reset link will be sent. Please check your inbox and spam folder.');
        return $this->redirect(['action' => 'login']);
    }
    
    public function resetpassword(...$legacy) {
        $this->request->allowMethod(['get', 'post']);
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer'));
        if ($legacy) {
            $this->Flash->error('This old reset link is no longer valid. Please request a new link.');
            return $this->redirect(['action' => 'login']);
        }
        $this->viewBuilder()->setLayout('home');
        $this->set('title_for_layout', 'Reset Password ' . TITLE_FOR_PAGES);
        $this->set('users', $this->Users->newEmptyEntity());
        if ($this->request->is('post')) {
            $password = (string)$this->request->getData('Users.password');
            if ($password !== (string)$this->request->getData('Users.confirm_password')) {
                $this->Flash->error('The passwords do not match. Open your reset link again.');
                return $this->redirect(['action' => 'forgotpassword']);
            }
            try {
                (new \App\Service\PasswordReset($this->Users->getConnection()))->consume(
                    (string)$this->request->getData('token'), $password
                );
            } catch (\DomainException $error) {
                $this->Flash->error($error->getMessage() . ' Please request a new link.');
                return $this->redirect(['action' => 'forgotpassword']);
            } catch (\RuntimeException $error) {
                \Cake\Log\Log::error('Password reset completion failed.');
                $this->Flash->error('Password reset could not be completed. Please request a new link.');
                return $this->redirect(['action' => 'forgotpassword']);
            }
            $this->Flash->success('Your password has been reset. Please log in.');
            return $this->redirect(['action' => 'login']);
        }
    }
    
    public function teachersetpassword($email_address = NULL, $enc_email = NULL, $userID = NULL, $enc_userID = NULL) {
        $this->Flash->error('Supervisor account setup is temporarily unavailable. Please contact the events team.');
        return $this->redirect(['controller' => 'users', 'action' => 'login']);
    }

    private function crossSchoolAccount()
    {
        $user = $this->Users->find()->where([
            'Users.id' => (int)$this->request->getSession()->read('user_id'),
            'Users.user_type' => 'School',
            'Users.status' => 1,
            'Users.activation_status' => 1,
        ])->first();
        if (!$user || $this->request->getSession()->read('user_type') !== 'School') {
            throw new \Cake\Http\Exception\ForbiddenException('Active school access required.');
        }
        return $user;
    }

    public function crossschoolrequests()
    {
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->request->allowMethod(['get']);
        $school = $this->crossSchoolAccount();
        $connection = $this->Conventionregistrationstudents->getConnection();
        $available = (new \App\Service\CrossSchoolSponsorship($connection))->available();
        $requests = [];
        if ($available) {
            $requests = $connection->execute(
                "SELECT r.id, r.origin_school_id, r.sponsor_school_id, r.created,
                        student.first_name AS student_first, student.last_name AS student_last,
                        origin.first_name AS origin_name, sponsor.first_name AS sponsor_name,
                        supervisor.first_name AS supervisor_first, supervisor.last_name AS supervisor_last,
                        cr.season_year, conv.name AS convention_name,
                        CASE WHEN cr.status = 1 AND s.status = 1 AND s.teacher_parent_id IS NULL
                                  AND s.user_id = r.origin_school_id
                                  AND student.school_id = r.origin_school_id AND student.user_type = 'Student'
                                  AND sponsor.user_type = 'School'
                                  AND sponsor.status = 1 AND sponsor.activation_status = 1
                                  AND supervisor.user_type = 'Teacher_Parent'
                                  AND supervisor.school_id = r.sponsor_school_id
                                  AND supervisor.status = 1 AND supervisor.activation_status = 1
                                  AND EXISTS (
                                      SELECT 1 FROM conventionregistrationteachers t
                                      JOIN conventionregistrations target ON target.id = t.conventionregistration_id
                                      WHERE t.teacher_id = r.supervisor_id AND t.user_id = r.sponsor_school_id
                                        AND t.status = 1 AND target.user_id = r.sponsor_school_id
                                        AND target.convention_id = cr.convention_id AND target.season_id = cr.season_id
                                        AND target.season_year = cr.season_year AND target.status = 1
                                  ) THEN 1 ELSE 0 END AS sponsor_available
                 FROM cross_school_sponsorship_requests r
                 JOIN conventionregistrationstudents s ON s.id = r.student_registration_id
                 LEFT JOIN conventionregistrations cr ON cr.id = s.conventionregistration_id AND cr.user_id = r.origin_school_id
                 LEFT JOIN conventions conv ON conv.id = cr.convention_id
                 LEFT JOIN users student ON student.id = s.student_id
                 LEFT JOIN users origin ON origin.id = r.origin_school_id
                 LEFT JOIN users sponsor ON sponsor.id = r.sponsor_school_id
                 LEFT JOIN users supervisor ON supervisor.id = r.supervisor_id
                 WHERE (r.origin_school_id = ? OR r.sponsor_school_id = ?)
                   AND r.status = 'pending_school'
                 ORDER BY r.created DESC, r.id DESC",
                [$school->id, $school->id]
            )->fetchAll('assoc');
        }
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
        $this->viewBuilder()->setLayout('home');
        $this->set('title_for_layout', 'Sponsorship Requests ' . TITLE_FOR_PAGES);
        $this->set('active_cross_school_requests', 'active');
        $this->set(compact('school', 'available', 'requests'));
        $this->set('userDetails', $school);
    }

    public function cancelcrossschoolrequest($id = null)
    {
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->request->allowMethod(['post']);
        $school = $this->crossSchoolAccount();
        $connection = $this->Conventionregistrationstudents->getConnection();
        if (!(new \App\Service\CrossSchoolSponsorship($connection))->available()) {
            $this->Flash->error('Cross-school requests are unavailable. Please contact the events team.');
            return $this->redirect(['action' => 'crossschoolrequests']);
        }
        $updated = $connection->transactional(function () use ($connection, $id, $school) {
            $updated = $connection->execute(
                "UPDATE cross_school_sponsorship_requests SET status = 'cancelled'
                 WHERE id = ? AND origin_school_id = ? AND status = 'pending_school'",
                [(int)$id, $school->id]
            )->rowCount();
            if ($updated) {
                (new \App\Service\SchoolNotifications($connection))->sponsorship((int)$id, true);
            }
            return $updated;
        });
        if ($updated) {
            $this->Flash->success('The request was cancelled. Please check the student has an appropriate assigned supervisor.');
        } else {
            $this->Flash->error('The pending request could not be found.');
        }
        return $this->redirect(['action' => 'crossschoolrequests']);
    }

    private function notificationAccount()
    {
        $type = (string)$this->request->getSession()->read('user_type');
        if (!in_array($type, ['School', 'Teacher_Parent', 'Judge', 'Student'], true)) {
            throw new \Cake\Http\Exception\ForbiddenException('Portal account required.');
        }
        $user = $this->Users->find()->where([
            'Users.id' => (int)$this->request->getSession()->read('user_id'),
            'Users.user_type' => $type,
            'Users.status' => 1,
            'Users.activation_status' => 1,
        ])->first();
        if (!$user) {
            throw new \Cake\Http\Exception\ForbiddenException('An active verified portal account is required.');
        }
        return $user;
    }

    private function portalNotificationInbox()
    {
        $this->request->allowMethod(['get']);
        $school = $this->notificationAccount();
        $isSchool = $school->user_type === 'School';
        $service = new \App\Service\SchoolNotifications($this->Users->getConnection());
        $available = $service->available();
        $folder = $this->request->getQuery('folder', 'inbox');
        if (!is_string($folder) || !in_array($folder, ['inbox', 'unread', 'archive'], true)) {
            throw new \Cake\Http\Exception\BadRequestException('Invalid notification folder.');
        }
        $pageValue = $this->request->getQuery('page', '1');
        $messageValue = $this->request->getQuery('message');
        if (!is_scalar($pageValue) || !ctype_digit((string)$pageValue)
            || ($messageValue !== null && (!is_scalar($messageValue) || !ctype_digit((string)$messageValue)))) {
            throw new \Cake\Http\Exception\BadRequestException('Invalid notification page or message.');
        }
        $inbox = $available ? $service->inbox((int)$school->id, $folder, (int)$pageValue) : null;
        $message = $available && $messageValue !== null ? $service->message((int)$school->id, (int)$messageValue) : null;
        $notificationInvoice = $message && in_array($school->user_type, ['School', 'Judge'], true)
            ? (new \App\Service\TransactionInvoices($this->Users->getConnection()))->forSchoolNotification((int)$school->id, $message)
            : null;
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
        $this->viewBuilder()->setLayout('home')->setTemplate('school_notifications');
        $this->set('title_for_layout', 'Notifications ' . TITLE_FOR_PAGES);
        $this->set('active_notifications', 'active');
        $this->set('userDetails', $school);
        $this->set(compact('available', 'folder', 'inbox', 'message', 'notificationInvoice', 'isSchool'));
        if ($school->user_type === 'Teacher_Parent') {
            $agreements = new \App\Service\SupervisorAgreements($this->Users->getConnection());
            $agreementsAvailable = $available && $agreements->available();
            $supervisorAgreements = $agreementsAvailable ? $agreements->forSupervisor((int)$school->id) : [];
            $notificationAgreement = null;
            if ($message && $message['kind'] === 'supervisor_agreement') {
                foreach ($supervisorAgreements as $agreement) {
                    if ($message['event_key'] === 'supervisor-agreement:' . $agreement['id']) {
                        $notificationAgreement = $agreement;
                        break;
                    }
                }
            }
            $this->set(compact('agreementsAvailable', 'supervisorAgreements', 'notificationAgreement'));
        }
        return null;
    }

    public function acceptsupervisoragreement($id = null)
    {
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->request->allowMethod(['post']);
        $account = $this->notificationAccount();
        if ($account->user_type !== 'Teacher_Parent' || $this->request->getSession()->read('school_student_access') !== null) {
            throw new \Cake\Http\Exception\ForbiddenException('Sign in to your own supervisor account to accept this agreement.');
        }
        if (!is_string($id) || !ctype_digit($id) || (int)$id <= 0) {
            throw new \Cake\Http\Exception\BadRequestException('Invalid supervisor agreement.');
        }
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
        $hash = $this->request->getData('roster_hash');
        if ($this->request->getData('agree') !== '1' || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            $this->Flash->error('Tick the supervisor agreement after reviewing your assigned students and all declarations.');
            return $this->redirect(['action' => 'notifications'], 303);
        }
        try {
            (new \App\Service\SupervisorAgreements($this->Users->getConnection()))->accept((int)$account->id, (int)$id, $hash);
        } catch (\DomainException $error) {
            $this->Flash->error($error->getMessage());
            return $this->redirect(['action' => 'notifications'], 303);
        }
        $this->Flash->success('Your supervisor agreement has been signed with your first and last name. You can now continue for this convention.');
        return $this->redirect(['action' => 'notifications'], 303);
    }

    public function notificationinvoice($id = null)
    {
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->request->allowMethod(['get']);
        $school = $this->notificationAccount();
        if (!in_array($school->user_type, ['School', 'Judge'], true)) {
            throw new \Cake\Http\Exception\ForbiddenException('A school or judge billing account is required.');
        }
        if (!is_string($id) || !ctype_digit($id)) {
            throw new \Cake\Http\Exception\BadRequestException('Invalid notification.');
        }
        $db = $this->Users->getConnection();
        $message = (new \App\Service\SchoolNotifications($db))->message((int)$school->id, (int)$id);
        $service = new \App\Service\TransactionInvoices($db);
        $invoice = $service->forSchoolNotification((int)$school->id, $message);
        if (!$invoice) {
            throw new \Cake\Http\Exception\NotFoundException('Invoice unavailable.');
        }
        $response = $this->getResponse()->withType('pdf')
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStringBody($service->pdf($invoice, $invoice['paid']));
        if ($this->request->getQuery('download') === '1') {
            return $response->withDownload($invoice['number'] . '.pdf');
        }
        return $response->withHeader('Content-Disposition', 'inline; filename="' . $invoice['number'] . '.pdf"');
    }

    public function notificationaction($id = null, $action = null, $destination = null)
    {
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->request->allowMethod(['post']);
        $school = $this->notificationAccount();
        if (!is_string($id) || !ctype_digit($id) || !in_array($action, ['read', 'unread', 'archive', 'restore'], true)
            || !in_array($destination, [null, 'open'], true)) {
            throw new \Cake\Http\Exception\BadRequestException('Invalid notification action.');
        }
        $service = new \App\Service\SchoolNotifications($this->Users->getConnection());
        if (!$service->available()) {
            $this->Flash->error('Notifications are unavailable until migration 012 is applied. Please contact the events team.');
            return $this->redirect(['action' => 'notifications']);
        }
        $message = $service->message((int)$school->id, (int)$id);
        $service->update((int)$school->id, (int)$id, $action);
        $folder = $action === 'restore' ? 'archive' : ($message['archived_at'] ? 'archive' : 'inbox');
        $query = ['folder' => $folder];
        if ($destination === 'open' && $action === 'read') {
            $query['message'] = (int)$id;
        } else {
            $this->Flash->success(match ($action) {
                'read' => 'Message marked as read.',
                'unread' => 'Message marked as unread.',
                'archive' => 'Message archived.',
                'restore' => 'Message moved to your inbox.',
            });
        }
        return $this->redirect(['action' => 'notifications', '?' => $query]);
    }

    public function notifications()
    {
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        return $this->portalNotificationInbox();
    }

    public function dashboard() {
        //echo 'ddd';exit;
        if (!$this->request->getSession()->read("user_id")) {
            $this->request->getSession()->write("returnUrl", $this->request->getRequestTarget());
            return $this->redirect(['controller' => 'users', 'action' => 'login']);
        }
        $this->userLoginCheck();
        
        $user_id 	= $this->request->getSession()->read("user_id");
        $user_type 	= $this->request->getSession()->read("user_type");
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");		
        $this->set('title_for_layout', 'Dashboard '.TITLE_FOR_PAGES);
        
        $this->set('active_dashboard', 'active');
        if ($user_type === 'Teacher_Parent') {
            $account = $this->notificationAccount();
            $notifications = new \App\Service\SchoolNotifications($this->Users->getConnection());
            if ($notifications->available()) {
                $this->set('unreadNotificationCount', $notifications->unreadCount((int)$account->id));
            } else {
                $this->Flash->error('Notifications are unavailable until migration 012 is applied. Please contact the events team.');
            }
            $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store, max-age=0'));
        }
        
        $userDetails = null;
        if (!empty($user_id)) {
            $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        }
        $this->set('userDetails', $userDetails);

        if ($userDetails && $userDetails->user_type === 'Student' && !$this->request->getSession()->read('sess_selected_convention_registration_id')) {
            $currentSeasonId = $this->getCurrentSeason();
            $studentConventionReg = $this->Conventionregistrationstudents->find()
                ->contain(['Conventionregistrations'])
                ->where([
                    'Conventionregistrationstudents.student_id' => $user_id,
                    'Conventionregistrationstudents.season_id' => $currentSeasonId,
                    'Conventionregistrationstudents.status' => 1,
                ])
                ->order(['Conventionregistrationstudents.id' => 'DESC'])
                ->first();

            if ($studentConventionReg && !empty($studentConventionReg->conventionregistration_id)) {
                $this->request->getSession()->write('sess_selected_convention_registration_id', $studentConventionReg->conventionregistration_id);
            }
        }
        
        $settingsD = null;
        try {
            $settingsD = $this->Settings->find()->where(['Settings.id' => 1])->first();
        } catch (\Throwable $e) {
            // Avoid dashboard hard-fail when local DB schema is missing optional settings columns.
            $settingsD = null;
        }
        $this->set('settingsD', $settingsD);
        
    }

    public function myschedule() {

        $this->userLoginCheck();
        $this->multiLoginCheck(['Student']);

        $this->viewBuilder()->setLayout('home');
        $this->set('title_for_layout', 'My Schedule ' . TITLE_FOR_PAGES);
        $this->set('active_myschedule', 'active');

        $userId = (int)$this->request->getSession()->read('user_id');
        $student = $this->Users->find()->where(['Users.id' => $userId])->first();
        $this->set('userDetails', $student);

        $scheduleRows = [];
        if ($student) {
            $currentSeasonId = $this->getCurrentSeason();
            $schoolId = (int)($student->school_id ?? 0);
            $conventionReg = null;

            if ($schoolId > 0 && $currentSeasonId > 0) {
                $conventionReg = $this->Conventionregistrations->find()
                    ->where([
                        'Conventionregistrations.user_id' => $schoolId,
                        'Conventionregistrations.season_id' => $currentSeasonId,
                    ])
                    ->first();
            }

            $scheduleLookup = [];

            $scheduleBaseConditions = [
                'OR' => [
                    ['Schedulingtimings.user_id' => $userId],
                    ['Schedulingtimings.user_id_opponent' => $userId],
                ]
            ];

            if ($conventionReg) {
                $scheduleBaseConditions['Schedulingtimings.conventionseasons_id'] = $conventionReg->conventionseason_id;
                $scheduleBaseConditions['Schedulingtimings.convention_id'] = $conventionReg->convention_id;
                $scheduleBaseConditions['Schedulingtimings.season_id'] = $conventionReg->season_id;
                $scheduleBaseConditions['Schedulingtimings.season_year'] = $conventionReg->season_year;
            }

            $directSchedules = $this->Schedulingtimings->find()
                ->where($scheduleBaseConditions)
                ->contain(['Events', 'Conventionrooms'])
                ->order(['Schedulingtimings.sch_date_time' => 'ASC', 'Schedulingtimings.start_time' => 'ASC'])
                ->all();

            foreach ($directSchedules as $scheduleRow) {
                $scheduleLookup[(int)$scheduleRow->id] = $scheduleRow;
            }

            if ($conventionReg) {
                $studentGroups = $this->Crstudentevents->find()
                    ->where([
                        'Crstudentevents.conventionseason_id' => $conventionReg->conventionseason_id,
                        'Crstudentevents.convention_id' => $conventionReg->convention_id,
                        'Crstudentevents.season_id' => $conventionReg->season_id,
                        'Crstudentevents.season_year' => $conventionReg->season_year,
                        'Crstudentevents.student_id' => $userId,
                        'Crstudentevents.group_name !=' => ''
                    ])
                    ->order(['Crstudentevents.id' => 'ASC'])
                    ->all();

                foreach ($studentGroups as $studentGroup) {
                    $groupSchedules = $this->Schedulingtimings->find()
                        ->where([
                            'Schedulingtimings.conventionseasons_id' => $conventionReg->conventionseason_id,
                            'Schedulingtimings.convention_id' => $conventionReg->convention_id,
                            'Schedulingtimings.season_id' => $conventionReg->season_id,
                            'Schedulingtimings.season_year' => $conventionReg->season_year,
                            'OR' => [
                                ['Schedulingtimings.user_id' => $schoolId],
                                ['Schedulingtimings.user_id_opponent' => $schoolId],
                            ],
                            'Schedulingtimings.event_id' => $studentGroup->event_id,
                            'Schedulingtimings.event_id_number' => $studentGroup->event_id_number,
                            'Schedulingtimings.group_name' => $studentGroup->group_name,
                        ])
                        ->contain(['Events', 'Conventionrooms'])
                        ->order(['Schedulingtimings.sch_date_time' => 'ASC'])
                        ->all();

                    foreach ($groupSchedules as $groupSchedule) {
                        $scheduleLookup[(int)$groupSchedule->id] = $groupSchedule;
                    }
                }

                $projectedRendered = [];
                $baseScheduleRows = array_values($scheduleLookup);
                usort($baseScheduleRows, function ($a, $b) {
                    return strtotime((string)$a->sch_date_time) <=> strtotime((string)$b->sch_date_time);
                });

                foreach ($baseScheduleRows as $scheduleRow) {
                    if (
                        ((int)($scheduleRow->schedule_category ?? 0) === 2 || (int)($scheduleRow->schedule_category ?? 0) === 3)
                        && (int)($scheduleRow->round_number ?? 0) === 1
                    ) {
                        $nextBaseId = (int)$scheduleRow->id;
                        for ($projStep = 1; $projStep <= 2; $projStep++) {
                            $nextMatch = $this->Schedulingtimings->find()
                                ->where([
                                    'Schedulingtimings.conventionseasons_id' => $conventionReg->conventionseason_id,
                                    'Schedulingtimings.convention_id' => $conventionReg->convention_id,
                                    'Schedulingtimings.season_id' => $conventionReg->season_id,
                                    'Schedulingtimings.season_year' => $conventionReg->season_year,
                                    'OR' => [
                                        ['Schedulingtimings.schtimeautoid1' => $nextBaseId],
                                        ['Schedulingtimings.schtimeautoid2' => $nextBaseId],
                                    ]
                                ])
                                ->contain(['Events', 'Conventionrooms'])
                                ->order(['Schedulingtimings.round_number' => 'ASC', 'Schedulingtimings.match_number' => 'ASC', 'Schedulingtimings.id' => 'ASC'])
                                ->first();

                            if (!$nextMatch) {
                                break;
                            }

                            if (isset($projectedRendered[(int)$nextMatch->id])) {
                                $nextBaseId = (int)$nextMatch->id;
                                continue;
                            }

                            $projectedRendered[(int)$nextMatch->id] = 1;
                            $scheduleLookup[(int)$nextMatch->id] = $nextMatch;
                            $nextBaseId = (int)$nextMatch->id;
                        }
                    }
                }
            }

            $scheduleRows = array_values($scheduleLookup);
            usort($scheduleRows, function ($a, $b) {
                return strtotime((string)$a->sch_date_time) <=> strtotime((string)$b->sch_date_time);
            });

            if ($conventionReg) {
                $judgeRegs = $this->Conventionregistrations->find()
                    ->contain(['Users'])
                    ->where([
                        'Conventionregistrations.conventionseason_id' => $conventionReg->conventionseason_id,
                        'Conventionregistrations.convention_id' => $conventionReg->convention_id,
                        'Conventionregistrations.season_id' => $conventionReg->season_id,
                        'Conventionregistrations.season_year' => $conventionReg->season_year,
                        'Conventionregistrations.status' => 1,
                    ])
                    ->all();

                $judgeUserIdsByEvent = [];
                foreach ($judgeRegs as $judgeReg) {
                    if (empty($judgeReg->judges_event_ids)) {
                        continue;
                    }

                    $eventIds = array_map('trim', explode(',', (string)$judgeReg->judges_event_ids));
                    foreach ($eventIds as $eventId) {
                        $eventId = (int)$eventId;
                        if ($eventId <= 0) {
                            continue;
                        }

                        if (!isset($judgeUserIdsByEvent[$eventId])) {
                            $judgeUserIdsByEvent[$eventId] = [];
                        }

                        $judgeUserIdsByEvent[$eventId][] = (int)$judgeReg->user_id;
                    }
                }

                foreach ($judgeUserIdsByEvent as $eventId => $userIds) {
                    $judgeUserIdsByEvent[$eventId] = array_values(array_unique(array_filter($userIds)));
                }

                foreach ($scheduleRows as $scheduleRow) {
                    $scheduleRow->is_judged_complete = false;
                    $scheduleRow->assigned_judges_count = 0;
                    $scheduleRow->submitted_judges_count = 0;

                    $eventId = (int)($scheduleRow->event_id ?? 0);
                    if ($eventId <= 0 || empty($judgeUserIdsByEvent[$eventId])) {
                        continue;
                    }

                    $eventSubmission = $this->Eventsubmissions->find()
                        ->where([
                            'Eventsubmissions.conventionregistration_id' => $conventionReg->id,
                            'Eventsubmissions.conventionseason_id' => $conventionReg->conventionseason_id,
                            'Eventsubmissions.event_id' => $eventId,
                            'Eventsubmissions.student_id' => $userId,
                        ])
                        ->order(['Eventsubmissions.id' => 'DESC'])
                        ->first();

                    if (!$eventSubmission) {
                        continue;
                    }

                    $assignedJudgeIds = $judgeUserIdsByEvent[$eventId];
                    $assignedJudgeCount = count($assignedJudgeIds);
                    if ($assignedJudgeCount <= 0) {
                        continue;
                    }

                    $submittedJudgeCount = $this->Judgeevaluations->find()
                        ->select(['Judgeevaluations.uploaded_by_user_id'])
                        ->where([
                            'Judgeevaluations.eventsubmission_id' => $eventSubmission->id,
                            'Judgeevaluations.uploaded_by_user_id IN' => $assignedJudgeIds,
                        ])
                        ->distinct(['Judgeevaluations.uploaded_by_user_id'])
                        ->enableHydration(false)
                        ->all()
                        ->count();

                    $scheduleRow->assigned_judges_count = $assignedJudgeCount;
                    $scheduleRow->submitted_judges_count = $submittedJudgeCount;
                    $scheduleRow->is_judged_complete = ($submittedJudgeCount >= $assignedJudgeCount);
                }
            }
        }

        $this->set('scheduleRows', $scheduleRows);
    }

    public function myevents() {

        $this->userLoginCheck();
        $this->multiLoginCheck(['Student']);

        $this->viewBuilder()->setLayout('home');
        $this->set('title_for_layout', 'My Events ' . TITLE_FOR_PAGES);
        $this->set('active_myevents', 'active');

        $userId = (int)$this->request->getSession()->read('user_id');
        $student = $this->Users->find()->where(['Users.id' => $userId])->first();
        $this->set('userDetails', $student);

        $eventRows = [];
        $pendingEventSelections = [];
        if ($student) {
            $currentSeasonId = $this->getCurrentSeason();
            $schoolId = (int)($student->school_id ?? 0);
            $conventionReg = null;

            if ($schoolId > 0 && $currentSeasonId > 0) {
                $conventionReg = $this->Conventionregistrations->find()
                    ->where([
                        'Conventionregistrations.user_id' => $schoolId,
                        'Conventionregistrations.season_id' => $currentSeasonId,
                    ])
                    ->first();
            }

            $conditions = ['Crstudentevents.student_id' => $userId];
            if ($conventionReg) {
                $conditions['Crstudentevents.conventionregistration_id'] = $conventionReg->id;
                $conditions['Crstudentevents.convention_id'] = $conventionReg->convention_id;
                $conditions['Crstudentevents.season_id'] = $conventionReg->season_id;
                $conditions['Crstudentevents.season_year'] = $conventionReg->season_year;
            }

            $eventRows = $this->Crstudentevents->find()
                ->where($conditions)
                ->contain(['Events', 'Conventions'])
                ->order(['Crstudentevents.id' => 'DESC'])
                ->all();
            $reviewSql = "SELECT s.slug,s.season_year,v.event_ids FROM student_event_reviews v
                JOIN conventionregistrationstudents s ON s.id = v.student_registration_id
                WHERE s.student_id = ? AND s.user_id = ? AND v.status = 'pending'";
            $reviewArgs = [$userId, $schoolId];
            if ($conventionReg) {
                $reviewSql .= ' AND s.conventionregistration_id = ?';
                $reviewArgs[] = (int)$conventionReg->id;
            }
            $pendingEventSelections = $this->Users->getConnection()->execute($reviewSql, $reviewArgs)->fetchAll('assoc');
        }

        $this->set('eventRows', $eventRows);
        $this->set('pendingEventSelections', $pendingEventSelections);
    }
    
    public function editprofile() {
        
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->multiLoginCheck(['School','Teacher_Parent','Student']);
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Edit Profile " . TITLE_FOR_PAGES);
        
        $this->set('active_editprofile','active');
        
        $user_id = $this->request->getSession()->read("user_id");
        if (empty($user_id)) {
            $this->Flash->error('Your session has expired. Please login again.');
            return $this->redirect(['controller' => 'users', 'action' => 'logout']);
        }
        
        $userDetails = null;
        if (!empty($user_id)) {
            $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->contain(['Schools'])->first();
        }
        $this->set('userDetails', $userDetails);
        
        $users = $this->Users->get($user_id);

        if ($this->request->is(['post', 'put'])) {
            $input = $this->request->getData();
            if (in_array($users->user_type, ['School', 'Teacher_Parent', 'Student'], true)) {
                $fields = $users->user_type === 'School'
                    ? ['first_name', 'middle_name', 'phone', 'phone2', 'bill_to_street', 'bill_to_block', 'bill_to_city', 'bill_to_zip', 'bill_to_country']
                    : ($users->user_type === 'Student' ? ['first_name', 'middle_name', 'last_name', 'email_address']
                    : ['first_name', 'last_name', 'is_sponsoring_students', 'first_aid_officer', 'volunteer_judge_and_sponsor',
                        'scripture_award_application', 'ace_experience_years', 'ace_experience_months']);
                $supplied = is_array($input['Users'] ?? null) ? $input['Users'] : $input;
                $protected = ['email_address', 'password', 'user_type', 'school_id', 'status', 'activation_status', 'customer_code', 'forgot_password_status'];
                if ($users->user_type === 'Student') {
                    $protected = ['password', 'user_type', 'school_id', 'status', 'activation_status', 'customer_code', 'forgot_password_status'];
                }
                if (array_intersect($protected, array_keys($supplied))) {
                    throw new \Cake\Http\Exception\BadRequestException('Account credentials and identity cannot be changed through this form.');
                }
                $input = array_intersect_key($supplied, array_flip($fields));
            }
            $data = $this->Users->patchEntity($users, $input);
            
            if (count($data->getErrors()) == 0) {
                
                if ($this->Users->save($data)) {
                    $this->Flash->success('Profile details updated successfully.');
                    $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
                }
            }
        }
        $this->set('users', $users);
        if ($users->user_type === 'Student') {
            $this->set('studentPortalAccess', (new \App\Service\StudentPortalAccounts($this->Users->getConnection()))->account((int)$users->id));
        }
        //pr($users);exit;
        
    }
    
    public function changepassword() {
        
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->multiLoginCheck(['School','Teacher_Parent','Judge','Student']);
        
        $this->set("title_for_layout", "Change Password" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
        $this->set('active_changepassword','active');
        
        $msgString = '';

        $user_id = $this->request->getSession()->read("user_id");

        $userDetails = null;
        if (!empty($user_id)) {
            $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        }
        $this->set('userDetails', $userDetails);
        
        $users = $this->Users->newEntity([]);

        if ($this->request->is('post')) {
            if ($userDetails && $userDetails->user_type === 'Student') {
                $input = (array)$this->request->getData('Users');
                foreach (['old_password', 'new_password', 'confirm_password'] as $key) {
                    if (!is_string($input[$key] ?? null)) {
                        throw new \Cake\Http\Exception\BadRequestException('Complete all password fields.');
                    }
                }
                try {
                    $version = (new \App\Service\StudentPortalAccounts($this->Users->getConnection()))->changePassword(
                        (int)$user_id, $input['old_password'], $input['new_password'], $input['confirm_password']
                    );
                    $this->request->getSession()->renew();
                    $this->request->getSession()->write('student_portal_version', $version);
                    $this->Flash->success('Your password has been changed successfully.');
                    return $this->redirect(['action' => 'editprofile']);
                } catch (\DomainException $error) {
                    $this->Flash->error($error->getMessage());
                } catch (\Exception $error) {
                    $this->log('Student password change failed: ' . $error->getMessage(), 'error');
                    $this->Flash->error('Password could not be changed. Please contact the events team.');
                }
                $this->set('users', $users);
                return;
            }
            $data = $this->Users->patchEntity($users, $this->request->getData(), ['validate' => 'changePassword']);

            if (count($data->getErrors()) == 0) {
//               pr($data); die('tsting');
                $old_password = $this->request->getData()['Users']['old_password'];
                $new_password = $this->request->getData()['Users']['new_password'];
                if ($userDetails && crypt($old_password, $userDetails->password) != $userDetails->password) {// Checking the OLD password matched aur not
                    $msgString = 'Old Password is not correct.';
                } else {
                    if ($userDetails && crypt($new_password, $userDetails->password) == $userDetails->password) {// Checking the both password matched aur not
                        $msgString .="- You cannot put your old password for the new password.";
                    }
                }


                if (isset($msgString) && $msgString != '') {
                    $this->Flash->error($msgString);
                } else {
                    $new_password = $data["new_password"];
                    $salt = uniqid(mt_rand(), true);
                    $password = crypt($new_password, '$2a$07$' . $salt . '$');
                    /* geting and setting users data */
                    $usersTable = $this->Users;
                    $user = $usersTable->get($user_id); // Return user with id 
                    $user->password = $password;
                    $usersTable->save($user);

                    $this->Flash->success('Your Password has been changed successfully.');
                    return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
                }
            } else {
                $this->Flash->error('Please below listed errors.');
            }
        }


        $this->set('users', $users);
    }
    
    public function teachers() {

        $this->userLoginCheck();
        $this->schoolAdminLoginCheck();
        
        $this->set("title_for_layout", "Supervisors List" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
        $this->set('active_teachers','active');
        
        $msgString = '';

        $user_id = $this->request->getSession()->read("user_id");

        $userDetails = null;
        if (!empty($user_id)) {
            $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        }
        $this->set('userDetails', $userDetails);

        $separator = array();
        $keyword = '';

        if ($this->request->is('post')) {
            if (isset($this->request->getData()['action'])) {
                $idList = implode(',', $this->request->getData()['chkRecordId']);
                $action = $this->request->getData()['action'];
                if ($idList) {
                    if ($action == "Activate") {
                        $this->Users->updateAll(['status' => '1'], ["id IN ($idList)"]);
                        $this->Flash->success('Records are activated successfully.');
                    } elseif ($action == "Deactivate") {
                        $this->Users->updateAll(['status' => '0'], ["id IN ($idList)"]);
                        $this->Flash->success('Records are deactivated successfully.');
                    } elseif ($action == "Delete") {
                        $this->Users->deleteAll(["id IN ($idList)"]);
                        $this->Flash->success('Records are deleted successfully.');
                    }
                }
            }

            if (isset($this->request->getData()['Users']['keyword']) && $this->request->getData()['Users']['keyword'] != '') {
                $keyword = trim($this->request->getData()['Users']['keyword']);
            }
        } else {
            $searchArr = (array)$this->request->getParam('pass', []);
            if (!empty($searchArr[0])) {
                foreach ($searchArr as $val) {
                    if (strpos($val, ":") !== false) {
                        $vars = explode(":", $val);
                        ${$vars[0]} = urldecode($vars[1]);
                    }
                }
            }
        }

        if (!empty($keyword)) {
            $separator[] = 'keyword:' . urlencode($keyword);
            $this->set('keyword', $keyword);
        }

        $separator = implode("/", $separator);
        $this->set('separator', $separator);

        if (empty($user_id)) {
            return $this->redirect(['controller' => 'users', 'action' => 'login']);
        }
        
        $groups = [];
        foreach (['active' => 1, 'archive' => 2, 'inactive' => 0] as $group => $status) {
            $query = $this->Users->find()->where([
                'Users.school_id' => $user_id,
                'Users.user_type' => 'Teacher_Parent',
                'Users.status' => $status,
            ]);
            if (!empty($keyword)) {
                $query = $query->where(function ($exp, $q) use ($keyword) {
                    return $exp->like('Users.name', '%' . $keyword . '%');
                });
            }
            $groups[$group] = $this->paginate($query, [
                'scope' => $group,
                'limit' => 30,
                'order' => ['Users.id' => 'DESC'],
                'allowedParameters' => ['page'],
            ]);
        }
        $this->set('supervisorGroups', $groups);
        if (strtolower($this->request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest') {
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Users');
            return $this->render('teachers');
        }
    }
    
    public function editteacher($teacher_slug=null) {
        
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $school = $this->crossSchoolAccount();
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Edit Supervisor Details " . TITLE_FOR_PAGES);
        
        $this->set('active_teachers','active');
        
        $user_id = $school->id;
        $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
        
        global $genderDD;
        $this->set('genderDD', $genderDD);
        
        global $yesNoDD;
        $this->set('yesNoDD', $yesNoDD);
        
        // to get teacher details
        $teacherD = $this->Users->find()->where(['Users.school_id' => $user_id, 'Users.slug' => $teacher_slug, 'Users.user_type' => 'Teacher_Parent'])->first();
        if (!$teacherD) {
            throw new \Cake\Http\Exception\NotFoundException('Supervisor not found.');
        }
        
        $users = $this->Users->get($teacherD->id);

        if ($this->request->is(['post', 'put'])) {
            $input = $this->request->getData();
            $supplied = is_array($input['Users'] ?? null) ? $input['Users'] : $input;
            $protected = ['email_address', 'password', 'user_type', 'school_id', 'status', 'activation_status', 'customer_code', 'forgot_password_status'];
            if (array_intersect($protected, array_keys($supplied))) {
                throw new \Cake\Http\Exception\BadRequestException('Supervisor credentials and identity cannot be changed through this form.');
            }
            $data = $this->Users->patchEntity($users, array_intersect_key(
                $supplied,
                array_flip(['title', 'first_name', 'last_name', 'gender', 'is_judge'])
            ));
            
            if (count($data->getErrors()) == 0) {
                
                if ($this->Users->save($data)) {
                    $this->Flash->success('Supervisor details updated successfully.');
                    $this->redirect(['controller' => 'users', 'action' => 'teachers']);
                }
            }
        }
        $this->set('users', $users);
        //pr($users);exit;
        
    }
    
    public function addteacher() {
        
        $this->userLoginCheck();
        $this->schoolAdminLoginCheck();
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Add Supervisors Details " . TITLE_FOR_PAGES);
        
        $this->set('active_teachers','active');
        
        $user_id = $this->request->getSession()->read("user_id");
        $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
        
        global $genderDD;
        $this->set('genderDD', $genderDD);
        
        global $yesNoDD;
        $this->set('yesNoDD', $yesNoDD);
        
        $users = $this->Users->newEntity([]);
        if ($this->request->is('post')) {
            
            //$this->prx($this->request->getData());
            
            $flagCheck = 1;
            
            $data = $this->Users->patchEntity($users, $this->request->getData());
            
            // to check that this email not duplicate for one school
            $checkEmailS = $this->Users->find()->where(['Users.email_address' => $data->email_address,'Users.school_id' => $user_id])->first();
            
            // to check if this email exists - in admins table
            $checkEmailA = $this->Admins->find()->where(['Admins.email' => $data->email_address])->first();
            
            if($checkEmailA || $checkEmailS)
            {
                $flagCheck = 0;
                $this->Flash->error('Email address already exists.');
            }
            
            if (count($data->getErrors()) == 0 && $flagCheck == 1) {

                $slug = $this->getSlug($this->request->getData()['Users']['first_name'] . ' ' . time(), 'Users');
                $data->slug = $slug;
                
                $data->user_type = 'Teacher_Parent';
                $data->school_id = $user_id;
                $data->status = 0;
                $data->activation_status = 0;
                $data->created = date('Y-m-d H:i:s');
                $data->modified = date('Y-m-d H:i:s');
                if ($resultUT = $this->Users->save($data)) {
                    $this->Flash->success('Supervisor details added successfully.');
                    $this->Flash->error('Supervisor account setup is temporarily unavailable; no invitation was sent. Please contact the events team.');
                    return $this->redirect(['controller' => 'users', 'action' => 'teachers']);
                }
                
            } else {
                // $this->Flash->error('Please below listed errors.');
            }
        }
        $this->set('users', $users);
    }
    
    public function archiveteacher($teacher_slug=null) {
        
        $this->userLoginCheck();
        $this->schoolAdminLoginCheck();
        
        $school_id 	= $this->request->getSession()->read("user_id");
        
        // to check if this teacher exists
        $teacherD = $this->Users->find()->where(['Users.slug' => $teacher_slug,'Users.school_id' => $school_id])->first();
        if($teacherD)
        {
            $this->Users->updateAll(['status' => '2'], ["slug"=>$teacher_slug]);
            $this->Flash->success('Supervisors details archived successfully.');
        }
        else
        {
            $this->Flash->error('Supervisors not found.');
        }
        
        
        $this->redirect(['controller'=>'users', 'action' => 'teachers']);
    }
    
    public function restoreteacher($teacher_slug=null) {
        
        $this->userLoginCheck();
        $this->schoolAdminLoginCheck();
        
        $school_id 	= $this->request->getSession()->read("user_id");
        
        // to check if this teacher exists
        $teacherD = $this->Users->find()->where(['Users.slug' => $teacher_slug,'Users.school_id' => $school_id])->first();
        if($teacherD)
        {
            $this->Users->updateAll(['status' => '1'], ["slug"=>$teacher_slug]);
            $this->Flash->success('Supervisor details restored successfully.');
        }
        else
        {
            $this->Flash->error('Supervisor not found.');
        }
        
        
        $this->redirect(['controller'=>'users', 'action' => 'teachers']);
    }
    
    public function students() {

        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        if (!in_array($this->request->getSession()->read('user_type'), ['School', 'Teacher_Parent'], true)) {
            throw new \Cake\Http\Exception\ForbiddenException('School or supervisor access required.');
        }
        
        $this->set("title_for_layout", "Students List" . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
        
        $this->set('active_students','active');
        
        $msgString = '';

        $user_id 	= $this->request->getSession()->read("user_id");
        $user_type 	= $this->request->getSession()->read("user_type");
        $userDetails = null;
        if (!empty($user_id)) {
            $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        }
        $this->set('userDetails', $userDetails);

        $separator = array();
        $keyword = '';

        if ($this->request->is('post')) {
            if (isset($this->request->getData()['action'])) {
                throw new \Cake\Http\Exception\BadRequestException('Use the available student list actions.');
            }

            if (isset($this->request->getData()['Users']['keyword']) && $this->request->getData()['Users']['keyword'] != '') {
                $keyword = trim($this->request->getData()['Users']['keyword']);
            }
        } else {
            $searchArr = (array)$this->request->getParam('pass', []);
            if (!empty($searchArr[0])) {
                foreach ($searchArr as $val) {
                    if (str_starts_with($val, 'keyword:')) {
                        $keyword = urldecode(substr($val, 8));
                    }
                }
            }
        }

        if (!empty($keyword)) {
            $separator[] = 'keyword:' . urlencode($keyword);
            $this->set('keyword', $keyword);
        }

        $separator = implode("/", $separator);
        $this->set('separator', $separator);
        
        $groups = [];
        foreach (['active' => 1, 'archive' => 2, 'inactive' => 0] as $group => $status) {
            $query = $this->Users->find()->where([
                'Users.user_type' => 'Student',
                'Users.status' => $status,
            ])->select($this->Users)->leftJoin(['StudentAccess' => 'student_portal_accounts'], ['StudentAccess.student_id = Users.id'])
                ->select(['portal_login_id' => 'StudentAccess.login_id', 'portal_first_login' => 'StudentAccess.first_login']);

            if ($user_type == "School") {
                $query = $query->where(['Users.school_id' => $user_id]);
            } elseif ($user_type == "Teacher_Parent" && !empty($userDetails)) {
                $query = $query->where(['Users.school_id' => $userDetails->school_id]);
            }

            if (!empty($keyword)) {
                $query = $query->where(function ($exp, $q) use ($keyword) {
                    return $exp->like('Users.name', '%' . $keyword . '%');
                });
            }

            $groups[$group] = $this->paginate($query, [
                'scope' => $group,
                'contain' => ['Schools'],
                'limit' => 30,
                'order' => ['Users.first_name' => 'ASC', 'Users.middle_name' => 'ASC', 'Users.last_name' => 'ASC', 'Users.id' => 'ASC'],
                'allowedParameters' => ['page'],
            ]);
        }
        $this->set('studentGroups', $groups);
        if (strtolower($this->request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest') {
            $this->viewBuilder()->disableAutoLayout();
            $this->viewBuilder()->setTemplatePath('Element' . DS . 'Users');
            return $this->render('students');
        }
    }
    
    public function addstudent() {
        
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        if ($this->request->getSession()->read('user_type') !== 'School') {
            throw new \Cake\Http\Exception\ForbiddenException('Only schools can add students.');
        }
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Add Student Details " . TITLE_FOR_PAGES);
        
        $this->set('active_students','active');
        
        $user_id = $this->request->getSession()->read("user_id");
        $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
        
        global $birthYearDD;
        $this->set('birthYearDD', $birthYearDD);
        
        global $genderDD;
        $this->set('genderDD', $genderDD);
        
        $users = $this->Users->newEntity([]);
        if ($this->request->is('post')) {
            
            //$this->prx($this->request->getData());
            
            $input = (array)$this->request->getData('Users');
            $data = $this->Users->patchEntity($users, array_intersect_key($input, array_flip(['first_name', 'middle_name', 'last_name', 'birth_year', 'gender'])));
            
            if (count($data->getErrors()) == 0) {

                $slug = $this->getSlug($this->request->getData()['Users']['first_name'] . ' ' . time(), 'Users');
                $data->slug = $slug;
                
                if($this->request->getSession()->read("user_type") == "School")
                {
                    $data->school_id = $user_id;
                }
                else
                if($this->request->getSession()->read("user_type") == "Teacher")
                {
                    $data->school_id 			= $userDetails->school_id;
                }
                
                $data->user_type = 'Student';
                $data->status = 1;
                $data->activation_status = 1;
                if (empty($data->customer_code)) {
                    $data->customer_code = $this->generateUniqueStudentLoginCode();
                }
                $data->created = date('Y-m-d H:i:s');
                $data->modified = date('Y-m-d H:i:s');
                if ($this->Users->save($data)) {
                    $this->Flash->success('Student details added successfully.');
                    $this->redirect(['controller' => 'users', 'action' => 'students']);
                }
                
            } else {
                // $this->Flash->error('Please below listed errors.');
            }
        }
        $this->set('users', $users);
    }
    
    public function editstudent($student_slug=null) {
        
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        if ($this->request->getSession()->read('user_type') !== 'School') {
            throw new \Cake\Http\Exception\ForbiddenException('Only schools can edit students.');
        }
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Edit Student Details " . TITLE_FOR_PAGES);
        
        $this->set('active_students','active');
        
        $user_id = $this->request->getSession()->read("user_id");
        $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
        
        global $birthYearDD;
        $this->set('birthYearDD', $birthYearDD);
        
        global $genderDD;
        $this->set('genderDD', $genderDD);
        
        // to get student details
        $studentD = $this->Users->find()->where(['Users.slug' => $student_slug, 'Users.school_id' => $user_id, 'Users.user_type' => 'Student'])->first();
        if (!$studentD) {
            throw new \Cake\Http\Exception\NotFoundException('Student not found in your school Global Students List.');
        }
        
        
        $users = $this->Users->get($studentD->id);

        if ($this->request->is(['post', 'put'])) {
            $input = (array)$this->request->getData('Users');
            $data = $this->Users->patchEntity($users, array_intersect_key($input, array_flip(['first_name', 'middle_name', 'last_name', 'birth_year', 'gender'])));
            
            if (count($data->getErrors()) == 0) {
                
                if ($this->Users->save($data)) {
                    $this->Flash->success('Student details updated successfully.');
                    $this->redirect(['controller' => 'users', 'action' => 'students']);
                }
            }
        }
        $this->set('users', $users);
        //pr($users);exit;
        
    }
    
    public function archivestudents() {
        $this->request->allowMethod(['post']);
        $schoolId = (int)$this->request->getSession()->read('user_id');
        if ($schoolId <= 0 || $this->request->getSession()->read('user_type') !== 'School') {
            throw new \Cake\Http\Exception\ForbiddenException('Only schools can archive students.');
        }
        $selected = $this->request->getData('student_ids', []);
        if (!is_array($selected) || !$selected) {
            $this->Flash->error('Select at least one student to archive.');
            return $this->redirect(['action' => 'students']);
        }
        $ids = [];
        foreach ($selected as $id) {
            if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id <= 0) {
                throw new \Cake\Http\Exception\BadRequestException('Invalid student selection.');
            }
            $ids[] = (int)$id;
        }
        $ids = array_values(array_unique($ids));
        $conditions = [
            'Users.id IN' => $ids,
            'Users.school_id' => $schoolId,
            'Users.user_type' => 'Student',
            'Users.status IN' => [0, 1],
        ];
        if ($this->Users->find()->where($conditions)->count() !== count($ids)) {
            $this->Flash->error('No students were archived. Select only your school\'s active or inactive students.');
            return $this->redirect(['action' => 'students']);
        }
        $count = $this->Users->updateAll(['status' => 2], $conditions);
        $this->Flash->success($count . ' student(s) archived successfully.');
        return $this->redirect(['action' => 'students']);
    }

    public function archivestudent($student_slug=null) {
        
        $this->userLoginCheck();
        $this->schoolAdminLoginCheck();
        
        $school_id 	= $this->request->getSession()->read("user_id");
        
        // to check if this student exists
        $studentD = $this->Users->find()->where(['Users.slug' => $student_slug,'Users.school_id' => $school_id])->first();
        if($studentD)
        {
            $this->Users->updateAll(['status' => '2'], ["slug"=>$student_slug]);
            $this->Flash->success('Student details archived successfully.');
        }
        else
        {
            $this->Flash->error('Student not found.');
        }
        
        $this->redirect(['controller'=>'users', 'action' => 'students']);
    }
    
    public function restorestudent($student_slug=null) {
        
        $this->userLoginCheck();
        $this->schoolAdminLoginCheck();
        
        $school_id 	= $this->request->getSession()->read("user_id");
        
        // to check if this student exists
        $studentD = $this->Users->find()->where(['Users.slug' => $student_slug,'Users.school_id' => $school_id])->first();
        if($studentD)
        {
            $this->Users->updateAll(['status' => '1'], ["slug"=>$student_slug]);
            $this->Flash->success('Student details restored successfully.');
        }
        else
        {
            $this->Flash->error('Student not found.');
        }
        
        $this->redirect(['controller'=>'users', 'action' => 'students']);
    }

    public function generatestudentlogincode($student_slug=null) {
        return $this->redirect(['action' => 'studentaccess', $student_slug]);
    }

    public function generateallstudentlogincodes() {
        $this->Flash->error('Student IDs are assigned automatically. Issue individual setup codes from the Account column.');
        return $this->redirect(['action' => 'students']);
    }

    protected function generateUniqueStudentLoginCode() {
        $seed = time();
        do {
            $loginCode = strtoupper(substr(str_pad(dechex($seed), 8, '0', STR_PAD_LEFT), -8));
            $existingCode = $this->Users->find()->where(['Users.customer_code' => $loginCode])->first();
            $seed++;
        } while ($existingCode);

        return $loginCode;
    }
    
    public function deletestudent_old($student_slug=null) {
        
        $this->userLoginCheck();
        $this->schoolAdminLoginCheck();
        
        $school_id 	= $this->request->getSession()->read("user_id");
        
        // to check if this student exists
        $studentD = $this->Users->find()->where(['Users.slug' => $student_slug,'Users.school_id' => $school_id])->first();
        if($studentD)
        {
            // to check if this student is linked with any convention registration students list
            $checkCRS = $this->Conventionregistrationstudents->find()->where(['Conventionregistrationstudents.student_id' => $studentD->id])->first();
            if($checkCRS)
            {
                $this->Flash->error('Student cannot delete. Student is linked with convention registration teachers list.');
            }
            else
            {
                $this->Users->deleteAll(['Users.school_id' => $school_id,'Users.id' => $studentD->id]);
                $this->Flash->success('Student details deleted successfully.');
            }
        }
        else
        {
            $this->Flash->error('Student not found.');
        }
        
        $this->redirect(['controller'=>'users', 'action' => 'students']);
    }
    
    public function judgesregistration() {
        
        $this->viewBuilder()->setLayout("home");		
        $this->set('title_for_layout', 'Judges Registration '.TITLE_FOR_PAGES);
        
        $this->set('header_menu_judgesreg_active', 'active');

        $today = date('Y-m-d');
        $seasonId = $this->getCurrentSeason();
        $seasonD = $this->Seasons->find()->where(['Seasons.id' => $seasonId])->first();
        $seasonYear = $seasonD ? $seasonD->season_year : null;

        $registrationWindows = [
            ['Conventionseasons.registration_end_date >=' => $today],
        ];
        foreach ((array)Configure::read('JudgesRegistration.closingDates', []) as $seasonSlug => $closingDate) {
            if ($today <= $closingDate) {
                $registrationWindows[] = ['Conventionseasons.slug' => $seasonSlug];
            }
        }

        $activeConventionSeasonRows = $this->Conventionseasons->find()
            ->where([
                'Conventionseasons.season_id' => $seasonId,
                'Conventionseasons.season_year' => $seasonYear,
                'OR' => $registrationWindows,
            ])
            ->order(['Conventionseasons.id' => 'ASC'])
            ->all();

        $activeConventionIds = [];
        $activeConventionSeasons = [];
        foreach ($activeConventionSeasonRows as $activeConventionSeasonRow) {
            $conventionId = (int)$activeConventionSeasonRow->convention_id;
            $activeConventionIds[$conventionId] = true;
            if (!isset($activeConventionSeasons[$conventionId])) {
                $activeConventionSeasons[$conventionId] = $activeConventionSeasonRow;
            }
        }

        $conventionDD = $this->getCurrentSeasonConventions($seasonId, $seasonYear);
        $conventionDD = array_filter($conventionDD, function ($label, $conventionId) use ($activeConventionIds) {
            return isset($activeConventionIds[(int)$conventionId]);
        }, ARRAY_FILTER_USE_BOTH);
        $this->set('conventionDD', $conventionDD);
        
        $users = $this->Users->newEntity([]);
        if ($this->request->is('post')) {
            
            $data = $this->Users->patchEntity($users, $this->request->getData());

            $conventionId = (int)$this->request->getData('convention_id');
            $conventionSeason = isset($conventionDD[$conventionId])
                ? ($activeConventionSeasons[$conventionId] ?? null)
                : null;
            if (!$conventionSeason) {
                $this->Flash->error('Please select a valid convention.');
            }
            
            $flagCheck				=	1;
            
            // to check if its exists in users table
            $checkUE = $this->Users->find()->where(['Users.email_address' => $data->email_address])->first();
            if($checkUE)
            {
                $flagCheck = 0;
                $this->Flash->error('Email address already exists.');
            }
            // to check if its exists in admins table
            $checkUA = $this->Admins->find()->where(['Admins.email' => $data->email_address])->first();
            if($checkUA)
            {
                $flagCheck = 0;
                $this->Flash->error('Email address already exists.');
            }
            
            if (count($data->getErrors()) == 0 && $flagCheck && $conventionSeason)
            {
                //$this->prx($this->request->getData());
                
                $slug = $this->getSlug($this->request->getData()['Users']['first_name'].' '.$this->request->getData()['Users']['last_name'].' '.time(), 'Users');
                $data->slug 				= $slug;
                $data->created 				= date('Y-m-d H:i:s', time());
                $data->modified 			= date('Y-m-d H:i:s', time());
                
                $data->user_type 			= 'Judge';
                $data->is_judge 			= 1;
                
                $data->status 				= 0;
                $data->activation_status 	= 0;
                
                $new_password = $this->request->getData()['Users']['password'];
                $salt = uniqid(mt_rand(), true);
                $password = crypt($new_password, '$2a$07$' . $salt . '$');
                $data->password = $password;
                
                if ($result = $this->Users->save($data)) {

                    $conventionRegistration = $this->Conventionregistrations->newEntity([]);
                    $conventionRegistration->conventionseason_id = $conventionSeason->id;
                    $conventionRegistration->slug = 'convention-registration-' . $conventionId . '-' . $result->id . '-' . $seasonId . '-' . time();
                    $conventionRegistration->convention_id = $conventionId;
                    $conventionRegistration->user_id = $result->id;
                    $conventionRegistration->season_id = $seasonId;
                    $conventionRegistration->season_year = $seasonYear;
                    $conventionRegistration->status = 0;
                    $conventionRegistration->created = date('Y-m-d H:i:s');
                    $this->Conventionregistrations->save($conventionRegistration);
                    
                    $first_name = $result->first_name;
                    $last_name 	= $result->last_name;
                    $emailId 	= $this->request->getData()['Users']['email_address'];
                    
                    $uIDA 		= $result->id + 2013;
                    
                    $emailtemplateMessage = $this->Emailtemplates->find()->where(['Emailtemplates.id' => '11'])->first();

                    $link = HTTP_PATH . "/users/judgesconfirmation/".$result->slug."/". md5($result->slug)."/".urlencode($emailId).'/'.md5($uIDA);
                    
                    $toRepArray = array('[!SITE_TITLE!]','[!first_name!]','[!last_name!]','[!email_address!]','[!LINK!]');
                    $fromRepArray = array(SITE_TITLE,$first_name,$last_name,$emailId,$link);
                    
                    $subjectToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['subject']);
                    $messageToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['template']);
                    
                    //echo $messageToSend;exit;
                    
                    $email = new Email();
                    $email->viewBuilder()
                        ->setClassName('App\\View\\AppView')
                        ->setTemplate('default')
                        ->setLayout('admintemplate')
                        ->setVars(['content_for_layout' => $messageToSend]);
                    $email->setEmailFormat('html')
                        ->setTo($emailId)
                        ->setCc(ACCOUNTS_TEAM_ANOTHER_EMAIL)
                        ->setFrom([HEADERS_FROM_EMAIL => HEADERS_FROM_NAME])
                        ->setSubject($subjectToSend)
                        ->send();
                    
                    $this->Flash->success('Your account has been successfully created. Please check your email for your activation link. If you do not receive it within a few minutes, please check your spam folder or contact our support team.');
                    
                    $this->redirect(['controller' => 'users', 'action' => 'login']);
                }
            } else {
                
            }
        }
        $this->set('users', $users);
        
    }

    public function squad247() {
        $this->viewBuilder()->setLayout("home");
        $this->set('title_for_layout', '24/7 Squad Application '.TITLE_FOR_PAGES);

        $contentFile = CONFIG . 'squad247_content.json';
        $defaultData = [
            'convention_subtitle' => 'Regional Student Conventions 2025',
            'convention_dates_message' => '',
            'convention_dates_message_enabled' => false,
            'application_intro' => 'Southern Cross Educational Enterprises is seeking applications from A.C.E. Graduates and supporters to join the 2025 Regional Convention 24/7 Squads in the following regions:',
            'success_requirements' => [
                'Be at least 17 years of age (if under 18 and staying on site, events team will contact parent/guardian).',
                'Will not be eligible to compete as a student at the Convention.',
                'Will not have responsibility as a sponsor or accompanist for children or students attending Convention.',
                'Will be willing to volunteer their time 24/7 during Convention in any area (judging, stage handling, sound/audio, general help).',
                'Be able to arrive prior to check-in and remain until after awards and campsite clean-up.',
                'Be flexible in sleeping arrangements.',
                'Be passionate about the purpose and ministry of Student Conventions.',
            ],
            'successful_need_to' => [
                'Cover their own transport expenses and registration fee listed on the application form.',
                'Registration fee for PNG, Fiji, Cook Islands, Solomon Islands and AUS workshop includes convention registration, food, 24/7 pin and 24/7 T-shirt.',
                'Registration fee for Indonesia and NZ includes convention registration, onsite accommodation and food, and 24/7 T-shirt.',
                'If travelling internationally, check with Events Coordinator for extra food, accommodation and transport cost estimates.',
                'Be prepared to use any musical or platform gifts at evening rallies.',
            ],
            'important_note' => 'Regional Squad experience is required (where possible) for South Pacific Squad applicants.',
            'applications_email' => 'events@scee.edu.au',
            'applications_deadline' => 'ALL APPLICATIONS MUST BE RECEIVED TWO MONTHS PRIOR to the commencement of the Convention.',
            'page2_title' => '24/7 Squad Regional Application Form 2025 (Page 2)',
            'page2_description' => 'The application form includes personal details, country selection, convention experience, references, testimony, and declaration sections.',
            'applicant_must_provide' => [
                'A current portrait photo.',
                'A reference from Principal and/or Pastor.',
                'Personal testimony of salvation and current walk with the Lord.',
                'A description of church background and beliefs.',
            ],
            'blue_card_requirement' => 'For Australian/NZ conventions, Australian volunteers at Australian conventions must hold an approved Blue Card (or the relevant state Working With Children approval). Applicants can apply via bluecard.qld.gov.au.',
            'payment_options' => 'Once the application is received, specific payment details are sent based on the country selected.',
            'regions_left' => [
                ['name' => 'FIJI', 'dates' => '30 June - 4 July'],
                ['name' => 'NZ', 'dates' => '1 - 5 September'],
            ],
            'regions_right' => [
                ['name' => 'PNG', 'dates' => '8 - 12 September'],
                ['name' => 'AUS Workshop', 'dates' => '10 - 12 June'],
                ['name' => 'INDO', 'dates' => '13 - 17 October'],
            ],
            'fees' => [
                ['name' => 'Fiji', 'amount' => '100 FJD'],
                ['name' => 'AUS', 'amount' => '100 AUD'],
                ['name' => 'NZ', 'amount' => '200 NZD'],
                ['name' => 'PNG', 'amount' => '225 Kina'],
                ['name' => 'INDO', 'amount' => '1,500,000 Rp'],
            ],
        ];

        $squad247Data = $defaultData;
        if (is_file($contentFile)) {
            $decoded = json_decode((string)@file_get_contents($contentFile), true);
            if (is_array($decoded)) {
                if (isset($decoded['convention_subtitle'])) {
                    $squad247Data['convention_subtitle'] = trim((string)$decoded['convention_subtitle']);
                }
                if (isset($decoded['convention_dates_message'])) {
                    $squad247Data['convention_dates_message'] = trim((string)$decoded['convention_dates_message']);
                }
                $squad247Data['convention_dates_message_enabled'] = array_key_exists('convention_dates_message_enabled', $decoded)
                    ? filter_var($decoded['convention_dates_message_enabled'], FILTER_VALIDATE_BOOLEAN)
                    : $squad247Data['convention_dates_message'] !== '';
                if (isset($decoded['application_intro'])) {
                    $squad247Data['application_intro'] = trim((string)$decoded['application_intro']);
                }
                if (isset($decoded['success_requirements']) && is_array($decoded['success_requirements'])) {
                    $squad247Data['success_requirements'] = $decoded['success_requirements'];
                }
                if (isset($decoded['successful_need_to']) && is_array($decoded['successful_need_to'])) {
                    $squad247Data['successful_need_to'] = $decoded['successful_need_to'];
                }
                if (isset($decoded['important_note'])) {
                    $squad247Data['important_note'] = trim((string)$decoded['important_note']);
                }
                if (isset($decoded['applications_email'])) {
                    $squad247Data['applications_email'] = trim((string)$decoded['applications_email']);
                }
                if (isset($decoded['applications_deadline'])) {
                    $squad247Data['applications_deadline'] = trim((string)$decoded['applications_deadline']);
                }
                if (isset($decoded['page2_title'])) {
                    $squad247Data['page2_title'] = trim((string)$decoded['page2_title']);
                }
                if (isset($decoded['page2_description'])) {
                    $squad247Data['page2_description'] = trim((string)$decoded['page2_description']);
                }
                if (isset($decoded['applicant_must_provide']) && is_array($decoded['applicant_must_provide'])) {
                    $squad247Data['applicant_must_provide'] = $decoded['applicant_must_provide'];
                }
                if (isset($decoded['blue_card_requirement'])) {
                    $squad247Data['blue_card_requirement'] = trim((string)$decoded['blue_card_requirement']);
                }
                if (isset($decoded['payment_options'])) {
                    $squad247Data['payment_options'] = trim((string)$decoded['payment_options']);
                }
                if (isset($decoded['regions_left']) && is_array($decoded['regions_left'])) {
                    $squad247Data['regions_left'] = $decoded['regions_left'];
                }
                if (isset($decoded['regions_right']) && is_array($decoded['regions_right'])) {
                    $squad247Data['regions_right'] = $decoded['regions_right'];
                }
                if (isset($decoded['fees']) && is_array($decoded['fees'])) {
                    $squad247Data['fees'] = $decoded['fees'];
                }
            }
        }

        $this->set('squad247Data', $squad247Data);
    }

    public function squad247submit() {
        $this->request->allowMethod(['post']);
        $payload = $this->request->getData('submission', []);
        if (is_array($payload) && count($payload) === 1 && isset($payload[0]) && is_string($payload[0])) {
            $payload = $payload[0];
        }
        if (is_string($payload)) {
            $decodedPayload = json_decode($payload, true);
            $payload = is_array($decodedPayload) ? $decodedPayload : [];
        }

        $uploadedFiles = $this->request->getData('attachments');
        $savedFiles = $this->saveSquad247Uploads($uploadedFiles);
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['files'] = $savedFiles;

        $submission = [
            'submitted_at' => date('Y-m-d H:i:s'),
            'ip_address' => (string)$this->request->clientIp(),
            'payload' => $payload,
        ];

        $this->saveSquad247Submission($submission);
        $this->sendSquad247SubmissionNotification($submission);

        $response = ['success' => true, 'message' => 'Thank you for your 24/7 Squad application. It has been received and our Events team will be in touch soon.'];
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function squad247SubmissionsFile() {
        return TMP . 'squad247_submissions.json';
    }

    protected function loadSquad247Submissions() {
        $submissionsFile = $this->squad247SubmissionsFile();
        if (!is_file($submissionsFile)) {
            return array();
        }

        $decoded = json_decode((string)@file_get_contents($submissionsFile), true);
        return is_array($decoded) ? $decoded : array();
    }

    protected function saveSquad247Submission(array $submission) {
        $submissions = $this->loadSquad247Submissions();
        array_unshift($submissions, $submission);
        @file_put_contents($this->squad247SubmissionsFile(), json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function sendSquad247SubmissionNotification(array $submission) {
        try {
            $payload = isset($submission['payload']) && is_array($submission['payload']) ? $submission['payload'] : [];
            $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];

            $findFieldValue = static function (array $rows, array $labelNeedles, array $excludeNeedles = []) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $label = strtolower(trim((string)($row['label'] ?? '')));
                    $value = trim((string)($row['value'] ?? ''));
                    if ($label === '' || $value === '') {
                        continue;
                    }

                    $skip = false;
                    foreach ($excludeNeedles as $excludeNeedle) {
                        if ($excludeNeedle !== '' && strpos($label, strtolower((string)$excludeNeedle)) !== false) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) {
                        continue;
                    }

                    foreach ($labelNeedles as $needle) {
                        if ($needle !== '' && strpos($label, strtolower((string)$needle)) !== false) {
                            return $value;
                        }
                    }
                }
                return '';
            };

            $applicantName = $findFieldValue($fields, ['full name', 'applicant name'], ['parent', 'guardian', 'principal', 'pastor', 'reference']);
            $emailAddress = $findFieldValue($fields, ['email']);
            $phone = $findFieldValue($fields, ['phone']);
            $country = $findFieldValue($fields, ['country']);

            if ($applicantName === '') {
                $applicantName = 'Unknown Applicant';
            }
            if ($emailAddress === '') {
                $emailAddress = 'Not provided';
            }
            if ($phone === '') {
                $phone = 'Not provided';
            }
            if ($country === '') {
                $country = 'Not provided';
            }

            $submittedAt = trim((string)($submission['submitted_at'] ?? date('Y-m-d H:i:s')));
            $ipAddress = trim((string)($submission['ip_address'] ?? 'N/A'));

            $adminUrl = rtrim((string)HTTP_PATH, '/') . '/admin/admins/squad247';
            $fromEmail = defined('MAIL_FROM') ? (string)MAIL_FROM : 'no-reply@accelerateministries.com.au';
            $fromName = defined('SITE_TITLE') ? (string)SITE_TITLE : 'Accelerate';

            $subject = 'New 24/7 Squad Application - ' . $applicantName;
            $body = '';
            $body .= '<p>A new 24/7 Squad application has been submitted.</p>';
            $body .= '<p><strong>Applicant:</strong> ' . htmlspecialchars($applicantName, ENT_QUOTES, 'UTF-8') . '<br>';
            $body .= '<strong>Email:</strong> ' . htmlspecialchars($emailAddress, ENT_QUOTES, 'UTF-8') . '<br>';
            $body .= '<strong>Phone:</strong> ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '<br>';
            $body .= '<strong>Country:</strong> ' . htmlspecialchars($country, ENT_QUOTES, 'UTF-8') . '<br>';
            $body .= '<strong>Submitted At:</strong> ' . htmlspecialchars($submittedAt, ENT_QUOTES, 'UTF-8') . '<br>';
            $body .= '<strong>IP Address:</strong> ' . htmlspecialchars($ipAddress, ENT_QUOTES, 'UTF-8') . '</p>';
            $body .= '<p><a href="' . htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8') . '">Open 24/7 submissions in admin</a></p>';

            $email = new Email();
            $email->setEmailFormat('html')
                ->setTo('nathanwaqa@accelerate.edu.au')
                ->setFrom([$fromEmail => $fromName])
                ->setSubject($subject)
                ->send($body);
        } catch (\Throwable $e) {
            $this->log('Failed to send 24/7 submission notification email: ' . $e->getMessage(), 'error');
        }
    }

    protected function squad247UploadsDir() {
        return WWW_ROOT . 'uploads' . DS . 'squad247';
    }

    protected function saveSquad247Uploads($uploadedFiles) {
        $files = [];
        if (empty($uploadedFiles)) {
            return $files;
        }

        if (!is_array($uploadedFiles)) {
            $uploadedFiles = [$uploadedFiles];
        }

        $uploadDir = $this->squad247UploadsDir();
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        $maxBytes = 10 * 1024 * 1024;

        foreach ($uploadedFiles as $uploadedFile) {
            if (!is_object($uploadedFile) || !method_exists($uploadedFile, 'getError')) {
                continue;
            }
            if ((int)$uploadedFile->getError() !== 0) {
                continue;
            }

            $clientName = trim((string)$uploadedFile->getClientFilename());
            if ($clientName === '') {
                continue;
            }

            $size = (int)$uploadedFile->getSize();
            if ($size <= 0 || $size > $maxBytes) {
                continue;
            }

            $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                continue;
            }

            $base = pathinfo($clientName, PATHINFO_FILENAME);
            $base = preg_replace('/[^A-Za-z0-9_-]/', '_', $base);
            $base = trim((string)$base, '_');
            if ($base === '') {
                $base = 'attachment';
            }

            $storedName = date('YmdHis') . '_' . mt_rand(1000, 9999) . '_' . $base . '.' . $ext;
            $targetPath = $uploadDir . DS . $storedName;

            try {
                $uploadedFile->moveTo($targetPath);
            } catch (\Throwable $e) {
                continue;
            }

            $files[] = [
                'name' => $clientName,
                'stored_name' => $storedName,
                'size' => $size,
                'url' => HTTP_PATH . '/uploads/squad247/' . rawurlencode($storedName),
            ];
        }

        return $files;
    }
    
    public function judgesconfirmation($slug = null, $md5slug = null, $email = null, $uIDA = null) {
        
        if (md5($slug) == $md5slug)
        {
            $userCheck = $this->Users->find()->where(['Users.slug' => $slug])->first();
            
            if($userCheck && $uIDA == md5($userCheck->id + 2013))
            {
                if ($userCheck->status == 0 && $userCheck->activation_status == 0)
                {
                    $this->Users->updateAll(['activation_status' => '1','modified' => date('Y-m-d H:i:s', time())], ["id" => $userCheck->id]);
                    
                    // send email to events team
                    $emailtemplateMessage = $this->Emailtemplates->find()->where(['Emailtemplates.id' => '12'])->first();
                    
                    $toRepArray = array('[!first_name!]','[!last_name!]','[!email_address!]');
                    $fromRepArray = array($userCheck->first_name,$userCheck->last_name,$userCheck->email_address);
                    
                    $subjectToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['subject']);
                    $messageToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['template']);
                    
                    //echo $messageToSend;exit;
                    
                    $email = new Email();
                    $email->viewBuilder()
                        ->setTemplate('default')
                        ->setLayout('admintemplate')
                        ->setVars(['content_for_layout' => $messageToSend]);
                    $email->setEmailFormat('html')
                        ->setTo(ACCOUNTS_TEAM_ANOTHER_EMAIL)
                        //->setCc(HEADERS_CC)
                        ->setFrom([HEADERS_FROM_EMAIL => HEADERS_FROM_NAME])
                        ->setSubject($subjectToSend)
                        ->send();
                    
                    
                    $this->Flash->success('Your account has been successfully verified. Admin will review and activate your account.');
                    $this->redirect(['controller' => 'users', 'action' => 'login']);
                }
                else
                {
                    $this->Flash->error('You have already used this activation link!');
                }
            }
            else
            {
                $this->Flash->error('User not found.');
            }
        }
        else
        {
            $this->Flash->error('Invalid activation link!');
        }
        
        $this->redirect(['controller' => 'users', 'action' => 'login']);
    }
    
    public function judgeeditprofile() {
        
        $this->userLoginCheck();
        $this->judgeLoginCheck();
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Edit Profile " . TITLE_FOR_PAGES);
        
        $this->set('active_editprofile','active');
        
        $user_id = $this->request->getSession()->read("user_id");
        
        $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        $this->set('userDetails', $userDetails);
        
        $users = $this->Users->get($user_id);

        if ($this->request->is(['post', 'put'])) {
            $data = $this->Users->patchEntity($users, $this->request->getData());
            
            if (count($data->getErrors()) == 0) {
                
                if ($this->Users->save($data)) {
                    $this->Flash->success('Profile details updated successfully.');
                    $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
                }
            }
        }
        $this->set('users', $users);
        //pr($users);exit;
        
    }
    
    public function applyforjudge() {
        
        $this->userLoginCheck();
        $this->teacherLoginCheck();
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Apply For Judge " . TITLE_FOR_PAGES);
        
        $this->set('active_applyforjudge','active');
        
        $user_id = $this->request->getSession()->read("user_id");
        
        $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->contain(['Schools'])->first();
        $this->set('userDetails', $userDetails);
        
        /* if($userDetails->is_judge != 0)
        {
            $this->Flash->error('Invalid access.');
            $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
        } */
        
        $users = $this->Users->get($user_id);

        if ($this->request->is(['post', 'put'])) {
            $data = $this->Users->patchEntity($users, $this->request->getData());
            
            $data->is_judge = 2;
            
            if (count($data->getErrors()) == 0) {
                
                if ($this->Users->save($data)) {
                    
                    // no send email to admin that one supervisor applied for judge
                    $emailtemplateMessage = $this->Emailtemplates->find()->where(['Emailtemplates.id' => '15'])->first();
                    
                    $toRepArray = array('[!SITE_TITLE!]','[!first_name!]','[!last_name!]','[!email_address!]');
                    $fromRepArray = array(SITE_TITLE,$userDetails->first_name,$userDetails->last_name,$userDetails->email_address);
                    
                    $subjectToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['subject']);
                    $messageToSend = str_replace($toRepArray, $fromRepArray, $emailtemplateMessage['template']);
                    
                    //echo $messageToSend;exit;
                    
                    $email = new Email();
                    $email->viewBuilder()
                        ->setTemplate('default')
                        ->setLayout('admintemplate')
                        ->setVars(['content_for_layout' => $messageToSend]);
                    $email->setEmailFormat('html')
                        ->setTo(ACCOUNTS_TEAM_ANOTHER_EMAIL)
                        ->setFrom([HEADERS_FROM_EMAIL => HEADERS_FROM_NAME])
                        ->setSubject($subjectToSend)
                        ->send();
                    
                    $this->Flash->success('Your request to apply for judge has been submitted successfully. Please wait while admin review and approve/reject.');
                    $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
                }
            }
        }
        $this->set('users', $users);
        //pr($users);exit;
        
    }
    
    public function switchprofile($switchprofiletype=null) {

        $user_id = $this->request->getSession()->read("user_id");
        if (empty($user_id)) {
            $this->Flash->error('Please login before switching profiles.');
            return $this->redirect(['controller' => 'users', 'action' => 'login']);
        }
        $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->contain(['Schools'])->first();
        if (empty($userDetails)) {
            $this->request->getSession()->delete('user_id');
            $this->Flash->error('Your session is no longer valid. Please login again.');
            return $this->redirect(['controller' => 'users', 'action' => 'login']);
        }
        
        if($switchprofiletype == 'switchtojudge' || $switchprofiletype == 'switchtosupervisor')
        {
            $this->request->getSession()->delete('sess_selected_convention_registration_id');
            $this->request->getSession()->delete('sess_selected_convention_id');
            
            if($userDetails->user_type == 'Teacher_Parent' && $userDetails->is_judge == '1')
            {
                if($switchprofiletype == 'switchtojudge')
                {
                    $this->request->getSession()->delete('current_session_profile_type');
                    $this->request->getSession()->write("current_session_profile_type", 'Judge');
                    
                    $this->Flash->success('Profile successfully switched as judge.');
                }
                
                if($switchprofiletype == 'switchtosupervisor')
                {
                    $this->request->getSession()->delete('current_session_profile_type');
                    $this->request->getSession()->write("current_session_profile_type", 'Supervisor');
                    
                    $this->Flash->success('Profile successfully switched as supervisor.');
                }
            }
            else
            {
                $this->Flash->error('Invalid user type.');
            }
        }
        else
        {
            $this->Flash->error('Invalid action.');
        }
        
        
        $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
    }
    
    public function judgeexperience() {
        
        $this->userLoginCheck();
        $this->multiLoginCheck(['School','Teacher_Parent']);
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");
        $this->set("title_for_layout", "Judge Experience " . TITLE_FOR_PAGES);
        
        $this->set('active_judgeexp','active');
        
        $user_id = $this->request->getSession()->read("user_id");
        if (empty($user_id)) {
            $this->Flash->error('Your session has expired. Please login again.');
            return $this->redirect(['controller' => 'users', 'action' => 'logout']);
        }
        
        $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->contain(['Schools'])->first();
        $this->set('userDetails', $userDetails);
        
        $users = $this->Users->get($user_id);

        if ($this->request->is(['post', 'put'])) {
            $data = $this->Users->patchEntity($users, $this->request->getData());
            
            if (count($data->getErrors()) == 0) {
                
                if ($this->Users->save($data)) {
                    $this->Flash->success('Judge experience details updated successfully.');
                    $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
                }
            }
        }
        $this->set('users', $users);
        //pr($users);exit;
        
    }
    
    public function logout() {

        $this->Flash->success('Logout successfully.');
        $this->request->getSession()->delete('user_id');
        $this->request->getSession()->delete('email_address');
        $this->request->getSession()->delete('user_type');
        $this->request->getSession()->delete('student_portal_version');
        $this->request->getSession()->delete('school_student_access');
        
        $this->request->getSession()->delete('sess_selected_convention_registration_id');
        $this->request->getSession()->delete('sess_selected_convention_id');
        $this->request->getSession()->delete('current_session_profile_type');
        
        $this->redirect(['controller' => 'users', 'action' => 'login']);
    }
    
    public function logintotest($user_id=null) {
        throw new \Cake\Http\Exception\ForbiddenException('Passwordless test login is no longer available. Sign in with your credentials.');
    }
    
    public function judgingform($event_id_number = '055') {
        //echo 'ddd';exit;
        $this->userLoginCheck();
        
        $user_id 	= $this->request->getSession()->read("user_id");
        $user_type 	= $this->request->getSession()->read("user_type");
        
        //echo ' fsdf sdf sdf d';exit;
        $this->viewBuilder()->setLayout("home");		
        $this->set('title_for_layout', 'Judging Form '.TITLE_FOR_PAGES);
        
        $this->set('active_dashboard', 'active');
        
        $userDetails = null;
        if (!empty($user_id)) {
            $userDetails = $this->Users->find()->where(['Users.id' => $user_id])->first();
        }
        $this->set('userDetails', $userDetails);
        
        //$event_id_number = '055';
        
        // now fetch the form based on event id number
        $condEvalForm = array();
        $condEvalForm[] = "(Evaluationforms.event_id_numbers LIKE '".$event_id_number."' OR Evaluationforms.event_id_numbers LIKE '".$event_id_number.",%' OR Evaluationforms.event_id_numbers LIKE '%,".$event_id_number.",%' OR Evaluationforms.event_id_numbers LIKE '%,".$event_id_number."')";
        
        $evalFormD = $this->Evaluationforms->find()->where($condEvalForm)->first();
        //$this->prx($evalFormD);
        
        $this->set('evalFormD', $evalFormD);
        
        
        if ($this->request->is(['post'])) {
            
            $this->prx($this->request->getData());
        }
        
        
    }

 

}

?>