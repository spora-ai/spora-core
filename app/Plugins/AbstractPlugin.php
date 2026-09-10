<?php

declare(strict_types=1);

namespace Spora\Plugins;

use ReflectionClass;

/**
 * Base implementation of {@see PluginInterface} with no-op defaults for the
 * data hooks plugins can opt into (tools, apps, skill/agent template paths,
 * schema version + migrations).
 *
 * Plugins SHOULD extend this class and override only the hooks they actually
 * use (typically {@see getName()} and {@see tools()}). Direct implementations
 * of PluginInterface remain valid but require implementing the full hook
 * surface.
 *
 * Behavioural hooks (DI registration, routes, boot) are NOT here — plugins
 * opt into those via {@see EventSubscriberInterface}. See
 * `spora-workspace/plans/extension-interface-events.md`.
 *
 * Hook lifecycle (PSR-14 events fired by `Spora\Plugins\PluginLoader`):
 *
 * - `ContainerBuildingEvent` → fires once per process, BEFORE the DI
 *   container is built. Listeners add bindings (`$event->builder()->addDefinitions`)
 *   so plugin tools can be autowired. The container is not yet resolvable
 *   here — use `BootingEvent` for post-build init.
 * - `apps()` → merged into the host's AppRegistry at container build time
 *   so plugin-supplied admin panels surface in `GET /api/v1/apps`.
 * - `RoutesRegisteringEvent` → fires per request, after the project's
 *   App routes are registered. Plugin routes can extend or override them.
 * - `BootingEvent` → fires per request, after the App boots. Idempotent
 *   within a process. Use this for stateful init that needs container
 *   services (Database, LoggerInterface, etc.).
 */
abstract class AbstractPlugin implements PluginInterface
{
    /**
     * Default name: the unqualified class name with a trailing "Plugin" suffix
     * stripped (e.g. SkeletonPlugin → "Skeleton"). Subclasses should override
     * with their human-facing brand name (e.g. "MiniMax", "Tavily Search").
     */
    public function getName(): string
    {
        $short = (new ReflectionClass($this))->getShortName();
        if (str_ends_with($short, 'Plugin')) {
            $short = substr($short, 0, -strlen('Plugin'));
        }
        return $short !== '' ? $short : 'Plugin';
    }

    /**
     * Tool classes this plugin contributes to the Tool Registry.
     *
     * @return array<class-string<\Spora\Tools\ToolInterface>>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Absolute paths to agent-template files (.json / .yaml / .yml) this
     * plugin ships. The scanner reads depth-0 from each path. Templates
     * declare tool activations and per-operation auto-approve defaults;
     * settings (passwords, secrets) are NEVER exported or imported —
     * recipients must configure them in Settings → Tools after import.
     *
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [];
    }

    /**
     * Absolute paths to skill directories this plugin ships. The
     * scanner walks each directory depth-1 and treats immediate
     * children as skill roots (each must contain a SKILL.md).
     *
     * @return string[]
     */
    public function skillPaths(): array
    {
        return [];
    }

    /**
     * Bump whenever new migration files are added under {@see migrationsPath()}.
     * Return 0 (the default) if the plugin has no database schema.
     */
    public function schemaVersion(): int
    {
        return 0;
    }

    /**
     * Absolute path to the directory containing this plugin's Laravel
     * migration files. Return null (the default) if the plugin has no
     * database schema.
     */
    public function migrationsPath(): ?string
    {
        return null;
    }

    /**
     * UI side-panels this plugin contributes to the App Registry. Merged into
     * the host's AppRegistry at container build time. Return [] unless the
     * plugin ships new admin panels.
     *
     * @return array<class-string<\Spora\Apps\AppInterface>>
     */
    public function apps(): array
    {
        return [];
    }

    /** @return list<class-string<\Spora\Speech\SpeechToTextProviderInterface>> */
    public function speechToTextProviders(): array
    {
        return [];
    }
}
