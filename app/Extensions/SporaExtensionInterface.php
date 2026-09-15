<?php

declare(strict_types=1);

namespace Spora\Extensions;

/**
 * Common contract for every Spora extension point — a plugin (Composer
 * package, manifest-driven) or an app (project-level, reflection-driven).
 *
 * Both `Spora\Plugins\PluginInterface` and `Spora\Extensions\AppInterface`
 * extend this interface as markers; the data-hook surface is shared so the
 * two are interchangeable from the loader's perspective.
 *
 * Historical context: in 1.0 the hook surface was trimmed. The deleted
 * hooks were `autoload()`, `drivers()`, `recipePaths()`, `register()`,
 * `routes()`, `boot()`. The first three had no callers; the last three
 * became PSR-14 events — extensions that need them implement
 * `Symfony\Contracts\EventDispatcher\EventSubscriberInterface` and react to
 * the matching `Spora\Events\*` events. See
 * `spora-workspace/plans/extension-interface-events.md` for the
 * migration guide and the design rationale.
 */
interface SporaExtensionInterface
{
    /** Human-readable extension name, shown in the UI and logs. */
    public function getName(): string;

    /**
     * Tool classes this extension contributes to the Tool Registry.
     *
     * @return array<class-string<\Spora\Tools\ToolInterface>>
     */
    public function tools(): array;

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
     * Absolute paths to directories containing skills this extension ships.
     * Each directory's immediate subdirectories are skill roots — they
     * must contain a SKILL.md file with YAML frontmatter. See
     * {@see \Spora\Skills\SkillScanner} for the on-disk contract.
     *
     * @return string[]
     */
    public function skillPaths(): array;

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
     * Speech-to-text provider classes this extension contributes.
     *
     * Plugins returning a non-empty list participate in the
     * {@see \Spora\Speech\SpeechToTextRegistry} alongside core's
     * {@see \Spora\Speech\OpenAiCompatibleTranscriber} (which covers the
     * OpenAI-multipart family — Mistral, OpenAI Whisper, Groq, Lemonfox,
     * Fireworks, LocalAI, future — through configuration alone). Plugins
     * contribute only when they need a bespoke wire shape beyond
     * OpenAI-multipart; today {@see spora-plugin-muse} is the lone
     * contributor (Meta Muse's bespoke multipart + ffmpeg pipeline).
     * The first configured provider wins per request.
     *
     * @return list<class-string<\Spora\Speech\SpeechToTextProviderInterface>>
     */
    public function speechToTextProviders(): array;
}
