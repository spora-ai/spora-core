<?php

declare(strict_types=1);

namespace Spora\Extensions;

use DI\ContainerBuilder;
use ReflectionClass;
use Spora\Core\MiddlewareRouteCollector;

/**
 * Convenience base class for any SporaExtensionInterface implementer.
 *
 * Provides reflection-based `getPath()` and empty defaults for every hook,
 * so subclasses only override what they need — same shape as Symfony's
 * AbstractBundle. Use this for both plugins and the project App.
 *
 * Example:
 * ```php
 * final class MyApp extends AbstractExtension
 * {
 *     public function getName(): string { return 'My App'; }
 *     public function tools(): array { return [Tools\Greeter::class]; }
 * }
 * ```
 */
abstract class AbstractExtension implements SporaExtensionInterface
{
    private ?string $path = null;

    /**
     * Absolute filesystem path to the directory containing this extension's
     * entry-point file (the file declaring the concrete subclass).
     * Computed lazily via reflection — mirrors Symfony's AbstractBundle::getPath().
     */
    public function getPath(): string
    {
        if ($this->path === null) {
            $reflected = new ReflectionClass($this);
            $this->path = \dirname($reflected->getFileName());
        }
        return $this->path;
    }

    public function autoload(): array
    {
        return [];
    }

    /** @return array<class-string<\Spora\Tools\ToolInterface>> */
    public function tools(): array
    {
        return [];
    }

    /** @return array<string, class-string<\Spora\Drivers\LLMDriverInterface>> */
    public function drivers(): array
    {
        return [];
    }

    /** @return string[] */
    public function recipePaths(): array
    {
        return [];
    }

    /**
     * See {@see SporaExtensionInterface::agentTemplatePaths()}. Default
     * to no templates shipped; subclasses (plugins and apps) override
     * to contribute their own.
     *
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [];
    }

    public function schemaVersion(): int
    {
        return 0;
    }

    public function migrationsPath(): ?string
    {
        return null;
    }

    /** @return array<class-string<\Spora\Apps\AppInterface>> */
    public function apps(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void {}

    public function routes(MiddlewareRouteCollector $routes): void {}

    public function boot(): void {}
}
