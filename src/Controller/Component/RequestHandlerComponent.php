<?php
declare(strict_types=1);

namespace App\Controller\Component;

use Cake\Controller\Component;

/**
 * CakePHP 5 compatibility shim for legacy controllers that still call
 * `$this->loadComponent('RequestHandler')`.
 *
 * The legacy app does not rely on RequestHandler component methods directly;
 * controllers read request details from `$this->request`.
 */
class RequestHandlerComponent extends Component
{
}

