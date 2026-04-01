<?php

declare(strict_types=1);

namespace Spora\Plugins;

use DI\ContainerBuilder;

interface PluginInterface
{
    /** Human-readable plugin name, shown in the UI and logs. */
    public function getName(): string;

    /**
     * PSR-4 autoload mappings for the plugin's own classes.
     *
     * @return array<string, string> namespace prefix => absolute path
     */
    public function autoload(): array;

    /**
     * Tool classes this plugin contributes to the Tool Registry.
     *
     * @return array<class-string<\Spora\Tools\InputToolInterface|\Spora\Tools\OutputToolInterface>>
     */
    public function tools(): array;

    /**
     * LLM drivers this plugin contributes.
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
     * Schema version for this plugin's database migrations.
     * Return 0 (default) if the plugin has no database schema.
     * Increment whenever new migration files are added.
     */
    public function schemaVersion(): int;

    /**
     * Absolute path to the directory containing this plugin's Laravel Migration files.
     * Return null (default) if the plugin has no database schema.
     */
    public function migrationsPath(): ?string;

    /**
     * Register arbitrary DI bindings, middleware, or services.
     */
    public function register(ContainerBuilder $builder): void;
}
