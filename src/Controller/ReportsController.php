<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\StudentPerformanceReportService;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;

class ReportsController extends AppController
{
    private int $schoolId;

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->setResponse($this->response
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff'));
        if ($event->isStopped()) {
            return;
        }
        if (!$this->request->is('get')) {
            $this->deny($event, $this->response->withStatus(405)->withHeader('Allow', 'GET')
                ->withType('text')->withStringBody('Reports are read-only. Use GET.'));
            return;
        }
        $session = $this->request->getSession();
        $id = $session->read('user_id');
        if (!$id) {
            $this->deny($event, $this->redirect(['controller' => 'Users', 'action' => 'login']));
            return;
        }
        if ($session->read('user_type') !== 'School') {
            $this->deny($event, $this->failure(403, 'School account required.'));
            return;
        }
        try {
            $this->schoolId = StudentPerformanceReportService::positiveId($id);
        } catch (BadRequestException $e) {
            $this->deny($event, $this->failure(403, 'School account required.'));
            return;
        }
        $school = $this->Users->find()->select(['id', 'first_name', 'user_type'])->where([
            'Users.id' => $this->schoolId, 'Users.user_type' => 'School',
            'Users.status' => 1, 'Users.activation_status' => 1,
        ])->first();
        if (!$school) {
            $this->deny($event, $this->failure(403, 'Active school account required.'));
            return;
        }
        $this->set('userDetails', $school);
        $this->set('active_reports', 'active');
        $this->set('title_for_layout', 'Student Performance Reports');
        $this->viewBuilder()->setLayout('home');
    }

    public function index(): ?Response
    {
        try {
            $params = $this->request->getQueryParams();
            if (array_diff(array_keys($params), ['q', 'page', 'limit'])) {
                throw new BadRequestException('Unsupported report filter.');
            }
            $search = $params['q'] ?? '';
            if (!is_string($search)) {
                throw new BadRequestException('Invalid report search.');
            }
            $page = StudentPerformanceReportService::positiveId($params['page'] ?? 1);
            $limit = StudentPerformanceReportService::positiveId($params['limit'] ?? 25);
            $this->set('listing', $this->service()->students($this->schoolId, trim($search), $page, $limit));
        } catch (BadRequestException $e) {
            return $this->failure(400, $e->getMessage());
        } catch (NotFoundException $e) {
            return $this->failure(404, 'Report page not found.');
        }
        return null;
    }

    public function student(string $studentId = ''): ?Response
    {
        try {
            $id = StudentPerformanceReportService::positiveId($studentId);
            $this->set('report', $this->service()->preview($this->schoolId, $id));
        } catch (BadRequestException|NotFoundException $e) {
            return $this->failure(404, 'Student report not found.');
        }
        return null;
    }

    private function service(): StudentPerformanceReportService
    {
        return new StudentPerformanceReportService(TableRegistry::getTableLocator());
    }

    private function failure(int $status, string $message): Response
    {
        return $this->response->withStatus($status)->withType('text')->withStringBody($message);
    }

    private function deny(EventInterface $event, Response $response): void
    {
        $this->setResponse($response);
        $event->setResult($response);
        $event->stopPropagation();
    }
}
