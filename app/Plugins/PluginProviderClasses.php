<?php

declare(strict_types=1);

namespace Spora\Plugins;

/**
 * The provider classes the loaded plugins contribute.
 *
 * One place per extension point, so the loader stays a loader: it finds the
 * plugins, and this answers what each of them contributes.
 *
 * The lists are read from the live plugin objects on every call, not from the
 * sidecar the loader caches its manifests in: a plugin that ships a provider
 * without touching its `plugin.json` is picked up on the next boot, and
 * caching the list alongside the manifest would make the sidecar a second
 * thing to invalidate.
 *
 * The container reads the lists once at build time and resolves the classes
 * itself. A mutable registry populated from `boot()` would arrive too late —
 * the registry that would read it is built in the same pass — and would need
 * a second owner. That is also why the provider seam is a data hook rather
 * than a fourth PSR-14 event: nothing wires subscribers before the container
 * is built, so a `boot()`-fired event reaches no listeners.
 */
final class PluginProviderClasses
{
    /**
     * @param array<string, PluginInterface> $plugins Loaded plugins, keyed by their manifest slug.
     */
    public function __construct(
        private readonly array $plugins,
    ) {}

    /**
     * @return list<class-string<\Spora\Scheduling\DueWorkProviderInterface>>
     */
    public function dueWork(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->dueWorkProviders() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<class-string<\Spora\Settings\PluginSettingsProviderInterface>>
     */
    public function pluginSettings(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->pluginSettingProviders() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<class-string<\Spora\Search\SearchProviderInterface>>
     */
    public function search(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->searchProviders() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<class-string<\Spora\Speech\SpeechToTextProviderInterface>>
     */
    public function speechToText(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->speechToTextProviders() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<class-string<\Spora\Skills\SkillProviderInterface>>
     */
    public function skills(): array
    {
        $classes = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->skillProviders() as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
