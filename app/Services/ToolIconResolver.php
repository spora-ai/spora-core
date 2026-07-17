<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Plugins\PluginLoader;

/**
 * Resolves the wire-format icon key for a tool, applying the 3-layer
 * fallback chain documented in {@see \Spora\Tools\Attributes\Tool::$icon}.
 *
 *   1. tool.icon   — `#[Tool(icon: ...)]` on the *Tool class (most specific)
 *   2. plugin.icon — owning plugin's `plugin.json` `icon` field
 *   3. null        — frontend's <Icon> component falls back to 'puzzle'
 *
 * Sits between {@see ToolConfigService} (which the controllers already
 * inject) and {@see PluginLoader} (which owns plugin metadata). Constructed
 * once at container build time and shared; both backing services are
 * effectively immutable after boot, so no caching is needed here.
 *
 * Not declared `final` to match {@see ToolConfigService}'s Mockery-friendly
 * convention; consumers should depend on the implementation via constructor
 * injection and avoid mocking this class in tests — drive it through real
 * `#[Tool]` attributes and real `plugin.json` fixtures instead.
 */
class ToolIconResolver
{
    public function __construct(
        private readonly ToolConfigNameResolver $nameResolver,
        private readonly PluginLoader $pluginLoader,
    ) {}

    /**
     * Apply the 3-layer chain. Returns null when neither the tool class
     * nor its owning plugin declares an icon — the wire payload then
     * carries `icon: null`, and the frontend's `<Icon>` component falls
     * back to 'puzzle' on its own.
     */
    public function resolve(string $toolClass): ?string
    {
        // Layer 1: per-tool override via #[Tool(icon: ...)]
        $toolIcon = $this->nameResolver->getToolIcon($toolClass);
        if ($toolIcon !== null && $toolIcon !== '') {
            return $toolIcon;
        }

        // Layer 2: per-plugin default via the owning plugin's plugin.json
        $slug = $this->pluginLoader->getSlugForToolClass($toolClass);
        if ($slug !== null) {
            $manifest = $this->pluginLoader->getPluginManifest($slug);
            $pluginIcon = is_array($manifest) ? ($manifest['icon'] ?? null) : null;
            if (is_string($pluginIcon) && $pluginIcon !== '') {
                return $pluginIcon;
            }
        }

        // Layer 3: null on the wire; frontend <Icon> component defaults to 'puzzle'.
        return null;
    }
}