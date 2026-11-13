<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AdultScriptureSubmissions;
use Cake\Http\Exception\BadRequestException;
use DomainException;
use Psr\Http\Message\UploadedFileInterface;

class ScriptureController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Flash');
    }

    public function index()
    {
        $this->request->allowMethod(['get']);
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store'));
        $service = new AdultScriptureSubmissions($this->Users->getConnection());
        try {
            $context = $service->context(
                (int)$this->request->getSession()->read('user_id'),
                (string)$this->request->getSession()->read('user_type'),
                (int)$this->request->getSession()->read('sess_selected_convention_registration_id')
            );
        } catch (DomainException $error) {
            $this->Flash->error($error->getMessage());
            return $this->redirect(['controller' => 'users', 'action' => 'dashboard']);
        }
        $awards = $service->awards((int)$context['conventionseason_id']);
        $unavailableReason = $this->submissionUnavailableReason($context);
        foreach ($awards as &$award) {
            $award['display_name'] = $this->awardDisplayName($award['event_name']);
            if ($unavailableReason === null) {
                $award['books'] = $service->books($award);
            }
        }
        unset($award);
        $submissions = $service->forUser((int)$context['conventionseason_id'], (int)$this->request->getSession()->read('user_id'));
        foreach ($submissions as &$submission) {
            $submission['display_name'] = $this->awardDisplayName($submission['event_name']);
        }
        unset($submission);
        $this->set(compact('context', 'awards', 'submissions', 'unavailableReason'));
        $this->set('active_scripture', 'active');
        $this->set('title_for_layout', 'Scripture ' . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
    }

    public function submit($eventId = null)
    {
        $this->request->allowMethod(['get', 'post']);
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        $this->setResponse($this->getResponse()->withHeader('Cache-Control', 'private, no-store'));
        if (!is_scalar($eventId) || !ctype_digit((string)$eventId) || (int)$eventId <= 0) {
            throw new BadRequestException('Invalid Scripture award.');
        }
        $service = new AdultScriptureSubmissions($this->Users->getConnection());
        try {
            $context = $service->context(
                (int)$this->request->getSession()->read('user_id'),
                (string)$this->request->getSession()->read('user_type'),
                (int)$this->request->getSession()->read('sess_selected_convention_registration_id')
            );
            $award = $service->award((int)$context['conventionseason_id'], (int)$eventId);
            $unavailableReason = $this->submissionUnavailableReason($context);
            if ($unavailableReason !== null) {
                throw new DomainException($unavailableReason);
            }
            if ($this->request->is('post')) {
                if ($this->request->getData('conventionseason_id') !== (string)$context['conventionseason_id']) {
                    throw new DomainException('The selected convention has changed. Please open the submission form again.');
                }
                $books = $this->request->getData('book_ids');
                $file = $this->request->getData('document');
                if (!is_array($books) || !$file instanceof UploadedFileInterface) {
                    throw new DomainException('Choose books and upload a Scripture submission.');
                }
                $service->submit((int)$context['conventionseason_id'], (int)$this->request->getSession()->read('user_id'), (int)$eventId, $books, $file);
                $this->Flash->success('Your Scripture submission was uploaded.');
                return $this->redirect(['action' => 'index'], 303);
            }
        } catch (DomainException $error) {
            $this->Flash->error($error->getMessage());
            return $this->redirect(['action' => 'index'], 303);
        }
        $award['display_name'] = $this->awardDisplayName($award['event_name']);
        $books = $service->books($award);
        $this->set(compact('award', 'context', 'books'));
        $this->set('active_scripture', 'active');
        $this->set('title_for_layout', 'Submit Scripture ' . TITLE_FOR_PAGES);
        $this->viewBuilder()->setLayout('home');
    }

    private function awardDisplayName(string $name): string
    {
        return preg_replace('/\s+open\s*$/i', '', $name);
    }

    private function submissionUnavailableReason(array $context): ?string
    {
        if ($this->areSubmissionsLocked((int)$context['conventionseason_id'])) {
            return 'Submissions are currently locked.';
        }
        $now = time();
        $start = strtotime((string)$context['registration_start_date']);
        $end = strtotime((string)$context['registration_end_date']);

        return !$start || !$end || $now < $start || $now > $end
            ? 'Registration is closed for this convention.' : null;
    }

    public function download($id = null)
    {
        $this->request->allowMethod(['get']);
        $login = $this->userLoginCheck();
        if ($login !== true) {
            return $login;
        }
        if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id <= 0) {
            throw new BadRequestException('Invalid Scripture submission.');
        }
        $service = new AdultScriptureSubmissions($this->Users->getConnection());
        try {
            $context = $service->context(
                (int)$this->request->getSession()->read('user_id'),
                (string)$this->request->getSession()->read('user_type'),
                (int)$this->request->getSession()->read('sess_selected_convention_registration_id')
            );
        } catch (DomainException $error) {
            throw new \Cake\Http\Exception\ForbiddenException($error->getMessage());
        }
        $submission = $service->get((int)$id);
        if (!$submission || (int)$submission['user_id'] !== (int)$this->request->getSession()->read('user_id')
            || (int)$submission['conventionseason_id'] !== (int)$context['conventionseason_id']) {
            throw new \Cake\Http\Exception\NotFoundException('Scripture submission not found.');
        }
        return $this->getResponse()->withFile($service->filePath($submission))
            ->withType('application/octet-stream')->withDownload($submission['original_filename'])
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
