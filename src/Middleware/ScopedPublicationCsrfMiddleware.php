<?php
declare(strict_types=1);

namespace App\Middleware;

use Cake\Http\Cookie\CookieInterface;
use Cake\Core\Configure;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ScopedPublicationCsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isPublicationRequest($request)) {
            return $handler->handle($request);
        }

        $csrf = new CsrfProtectionMiddleware([
            'cookieName' => Configure::read('PublicationCsrf.cookieName', 'csrfToken'),
            'secure' => $request->getUri()->getScheme() === 'https',
            'httponly' => true,
            'samesite' => CookieInterface::SAMESITE_LAX,
        ]);

        return $csrf->process($request, $handler);
    }

    private function isPublicationRequest(ServerRequestInterface $request): bool
    {
        if ($request instanceof \Cake\Http\ServerRequest && $request->getSession()->read('school_student_access') !== null) {
            return true;
        }
        $params = $request->getAttribute('params', []);
        if (!is_array($params)) {
            return false;
        }
        $controller = strtolower((string)($params['controller'] ?? ''));
        $action = strtolower((string)($params['action'] ?? ''));
        $prefix = strtolower((string)($params['prefix'] ?? ''));
        if ($prefix === '' && $controller === 'scripture' && in_array($action, ['index', 'submit'], true)) {
            return true;
        }
        if ($controller === 'combinerequests' && in_array($action, ['addrequest', 'viewlist', 'decideteam', 'index'], true)) {
            return true;
        }
        if ($prefix === '' && $controller === 'eventsubmissions' && $action === 'submitgroupevent') {
            return true;
        }
        if ($prefix === '' && $controller === 'conventionregistrations' && in_array($action, [
            'judgepricestructure', 'judgesregisterconvention', 'addstudent', 'students', 'unassignsupervisors', 'studentevents', 'managestudentevents', 'reviewstudentevents', 'editstudentevent', 'removestudentevent', 'addstudentevent',
        ], true)) {
            return true;
        }
        if ($prefix === '' && $controller === 'eventsubmissions' && $action === 'submitstudentevent') {
            return true;
        }
        if ($prefix === '' && $controller === 'users' && in_array($action, [
            'notifications', 'notificationaction', 'acceptsupervisoragreement', 'crossschoolrequests', 'cancelcrossschoolrequest', 'editprofile', 'editteacher', 'changepassword',
            'forgotpassword', 'resetpassword', 'students', 'archivestudents', 'login', 'studentsetup', 'studentaccess', 'bulkstudentaccess', 'addstudent', 'editstudent', 'openstudentportal', 'returntoschool',
        ], true)) {
            return true;
        }
        if ($prefix !== 'admin') {
            return false;
        }
        if ($controller === 'admins') {
            return in_array($action, ['errorlogs', 'deleteerrorlogs'], true);
        }
        if ($controller === 'transactions') {
            return in_array($action, ['index', 'cancelrequest', 'deleteconfirmed', 'viewdetails', 'invoice', 'invoicepdf'], true);
        }
        if ($controller !== 'conventions') {
            return false;
        }

        return in_array($action, [
            'changeprices', 'addseason',
            'seasons',
            'seasonresultrelease',
            'seasonresultreleasestop',
        ], true);
    }
}
