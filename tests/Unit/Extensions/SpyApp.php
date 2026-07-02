<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use DI\ContainerBuilder;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Extensions\AbstractExtension;
use Spora\Extensions\AppInterface;

/**
 * App that records every method call so we can assert on the lifecycle.
 * Non-final because AppLoader's discovery creates a runtime subclass via
 * `class App extends SpyApp {}` written to a file on disk.
 */
class SpyApp extends AbstractExtension implements AppInterface
{
    public int $registerCalls = 0;
    public int $routesCalls = 0;
    public int $bootCalls = 0;

    public function getName(): string
    {
        return 'Spy';
    }

    public function register(ContainerBuilder $builder): void
    {
        $this->registerCalls++;
    }

    public function routes(MiddlewareRouteCollector $routes): void
    {
        $this->routesCalls++;
    }

    public function boot(): void
    {
        $this->bootCalls++;
    }
}
