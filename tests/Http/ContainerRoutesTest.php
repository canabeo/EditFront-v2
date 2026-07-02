<?php

declare(strict_types=1);

namespace EditFront\Tests\Http;

use EditFront\Http\ErrorHandler;
use EditFront\Http\Middleware\AuthMiddleware;
use EditFront\Http\Middleware\CsrfMiddleware;
use EditFront\Http\Middleware\LocaleMiddleware;
use EditFront\Http\Middleware\SecurityHeadersMiddleware;
use EditFront\Http\Middleware\SessionMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\App;

/**
 * DI guard (анти-паттерн №3): every route callable and every middleware MUST
 * resolve from the container with zero manual factories. A constructor change
 * that the container cannot satisfy turns this test red before deploy.
 */
final class ContainerRoutesTest extends TestCase
{
    private static ?App $app = null;

    private function app(): App
    {
        if (self::$app === null) {
            $site = ef2_temp_dir('di-site');
            $storage = ef2_temp_dir('di-storage');
            $_ENV['SITE_ROOT'] = $site;
            $_ENV['STORAGE_DIR'] = $storage;
            $_ENV['BASE_PATH'] = '/cms';
            $_ENV['APP_DEBUG'] = 'true';
            putenv('SITE_ROOT=' . $site);
            putenv('STORAGE_DIR=' . $storage);
            putenv('BASE_PATH=/cms');
            putenv('APP_DEBUG=true');
            self::$app = require dirname(__DIR__, 2) . '/app/bootstrap.php';
        }
        return self::$app;
    }

    public function test_routes_exist(): void
    {
        $routes = $this->app()->getRouteCollector()->getRoutes();
        $this->assertGreaterThanOrEqual(5, count($routes));
    }

    public function test_every_route_callable_resolves_from_container(): void
    {
        $app = $this->app();
        $container = $app->getContainer();
        $this->assertNotNull($container);

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $callable = $route->getCallable();
            $class = null;
            if (is_array($callable) && is_string($callable[0])) {
                $class = $callable[0];
            } elseif (is_string($callable) && str_contains($callable, ':')) {
                [$class] = explode(':', $callable, 2);
            }
            if ($class === null) {
                continue;
            }
            $instance = $container->get($class);
            $this->assertInstanceOf($class, $instance, "route {$route->getPattern()}");
        }
    }

    public function test_all_middleware_resolve_from_container(): void
    {
        $container = $this->app()->getContainer();
        $this->assertNotNull($container);

        foreach ([
            SessionMiddleware::class,
            CsrfMiddleware::class,
            AuthMiddleware::class,
            LocaleMiddleware::class,
            SecurityHeadersMiddleware::class,
            ErrorHandler::class,
        ] as $class) {
            $this->assertInstanceOf($class, $container->get($class));
        }
    }
}
