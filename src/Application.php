<?php
declare(strict_types=1);

namespace App;

use Cake\Core\Configure;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use Cake\Routing\Router;
use App\Middleware\LegacyRequestMiddleware;
use App\Middleware\ScopedPublicationCsrfMiddleware;
use App\Middleware\EnvironmentSafetyMiddleware;
use App\Deployment\SafetyPolicy;

class Application extends BaseApplication
{
    public function bootstrap(): void
    {
        Router::reload();
        parent::bootstrap();

        if (PHP_SAPI === 'cli') {
            SafetyPolicy::assertCommandAllowed($GLOBALS['argv'] ?? []);
            $this->bootstrapCli();
        }

        $this->addPlugin('Migrations');

        if (Configure::read('debug')) {
            $this->addPlugin('AkkaFacebook', ['autoload' => true]);
        }
    }

    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))
            ->add(new LegacyRequestMiddleware())
            ->add(new AssetMiddleware([
                'cacheTime' => Configure::read('Asset.cacheTime'),
            ]))
            ->add(new RoutingMiddleware($this))
            ->add(new EnvironmentSafetyMiddleware())
            ->add(new BodyParserMiddleware())
            ->add(new ScopedPublicationCsrfMiddleware());

        return $middlewareQueue;
    }
    protected function bootstrapCli(): void
    {
        // CLI-specific bootstrap hooks can be added here if needed.
    }
}