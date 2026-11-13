<?php
declare(strict_types=1);

namespace App\Controller\Component;

use Cake\Controller\Component;

/**
 * CakePHP 5 compatibility shim for legacy controllers that still call
 * `$this->loadComponent('Paginator')`.
 *
 * Pagination is handled via controller paginate settings/methods in CakePHP 5.
 */
class PaginatorComponent extends Component
{
}

