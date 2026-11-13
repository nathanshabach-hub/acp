<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use Cake\Http\Response;
use Psr\Http\Message\UriInterface;

/**
 * Lets the schedule category page run the scheduling chain one step per request.
 * Requests that send `X-Schedule-Progress: 1` receive the next redirect target as
 * JSON instead of an HTTP redirect, so the page can report progress between steps.
 */
trait ScheduleProgressRedirectTrait
{
    private ?string $scheduleProgressLocation = null;

    public function redirect(UriInterface|array|string $url, int $status = 302): ?Response
    {
        $response = parent::redirect($url, $status);
        if ($response === null || $this->request->getHeaderLine('X-Schedule-Progress') !== '1') {
            return $response;
        }

        // Like a normal redirect, the first target wins (for example the login check in initialize()).
        $this->scheduleProgressLocation ??= $response->getHeaderLine('Location');

        return $this->response = $response
            ->withoutHeader('Location')
            ->withStatus(200)
            ->withType('application/json')
            ->withStringBody((string)json_encode(['redirect' => $this->scheduleProgressLocation]));
    }
}
