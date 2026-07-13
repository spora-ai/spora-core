<?php

declare(strict_types=1);

namespace Spora\Extensions;

use DI\ContainerBuilder;
use Spora\Core\MiddlewareRouteCollector;

/**
 * Common contract for every Spora extension point — a plugin (Composer
 * package, manifest-driven) or an app (project-level, reflection-driven).
 *
 * Both `Spora\Plugins\PluginInterface` and `Spora\Extensions\AppInterface`
 * extend this interface as pure markers; the hook surface is shared so
 * migrating an app to a plugin is a rename + manifest, not a rewrite.
 *
 * New hook methods (`apps()`, `routes()`, `boot()`) were added in v0.5.
 * Existing concrete plugin classes that don't extend {@see AbstractExtension}
 * must implement them explicitly (return [] / no-op); the tests ship
 * fixtures that extend AbstractExtension for free defaults.
 */
interface SporaExtensionInterface
{
    /** Human-readable extension name, shown in the UI and logs. */
    public function getName(): string;

    /**
     * PSR-4 autoload mappings for the extension's own classes.
     *
     * @return array<string, string> namespace prefix => absolute path
     */
    public function autoload(): array;

    /**
     * Tool classes this extension contributes to the Tool Registry.
     *
     * @return array<class-string<\Spora\Tools\ToolInterface>>
     */
    public function tools(): array;

    /**
     * LLM drivers this extension contributes.
     * Keys are the llm_provider string stored in agents.llm_provider.
     *
     * @return array<string, class-string<\Spora\Drivers\LLMDriverInterface>>
     */
    public function drivers(): array;

    /**
     * Absolute paths to directories or individual files containing recipe definitions.
     *
     * @return string[]
     */
    public function recipePaths(): array;

    /**
     * Absolute paths to agent-template files (.json / .yaml / .yml) this
     * extension ships. The scanner reads depth-0 from each path. Templates
     * declare tool activations and per-operation auto-approve defaults;
     * settings (passwords, secrets) are NEVER exported or imported —
     * recipients must configure them in Settings → Tools after import.
     *
     * @return string[]
     */
    public function agentTemplatePaths(): array;

    /**
     * Schema version for this extension's database migrations.
     * Return 0 (default) if the extension has no database schema.
     * Increment whenever new migration files are added.
     */
    public function schemaVersion(): int;

    /**
     * Absolute path to the directory containing this extension's migration files.
     * Return null (default) if the extension has no database schema.
     */
    public function migrationsPath(): ?string;

    /**
     * UI side-panels (apps) this extension contributes.
     *
     * @return array<class-string<\Spora\Apps\AppInterface>>
     */
    public function apps(): array;

    /**
     * Register arbitrary DI bindings, middleware, or services.
     * Applied to the container builder BEFORE the container is built.
     */
    public function register(ContainerBuilder $builder): void;

    /**
     * Register HTTP routes into the running middleware collector.
     * Called after core routes are registered, before the router is built.
     */
    public function routes(MiddlewareRouteCollector $routes): void;

    /**
     * Called once after the container is built, before the request is handled.
     * Safe to use container services here.
     */
    public function boot(): void;
}
