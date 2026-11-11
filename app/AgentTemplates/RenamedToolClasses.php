<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

/**
 * Tool classes that were renamed, and the class that replaced them.
 *
 * An agent template carries the class name of every tool it configures, so
 * a template exported before a rename names a class that no longer exists.
 * The import then skips the tool and its operation overrides with a
 * `TOOL_PLUGIN_MISSING` warning, and the agent loses a tool it had.
 *
 * The map is the one place that knows the old name, so a template written
 * against the old class imports as the new one — with its enabled state and
 * its operation overrides intact.
 */
final class RenamedToolClasses
{
    /** The class that replaced the one a template names. */
    private const RENAMED = [
        'Spora\\Tools\\UserInfoTool' => 'Spora\\Tools\\PrincipalInfoTool',
    ];

    /**
     * The class that replaced the one the template names, or the class it
     * names when that class was never renamed.
     */
    public static function resolve(string $toolClass): string
    {
        return self::RENAMED[$toolClass] ?? $toolClass;
    }
}
