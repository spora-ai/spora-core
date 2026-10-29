<?php

declare(strict_types=1);

namespace Spora\Settings;

use Closure;
use Throwable;

/**
 * Collects the settings the installed plugins contribute and rewrites them
 * into the host's own shape.
 *
 * A plugin says "here are my settings"; the host decides what they are called
 * and where they live. The key becomes `SPORA_<SLUG>_<KEY>`, the storage
 * group becomes `plugin:<slug>` and the section becomes `plugins`, so a
 * plugin-contributed setting lands on the same tab as core's own plugin
 * settings and is stored apart from every other plugin's rows.
 *
 * The rewriting happens here rather than in each plugin because the rule has
 * to hold for every plugin: the host owns the key shape and the storage
 * group, so a plugin-contributed setting is stored, listed and reset the way
 * every other setting is.
 */
final class PluginSettingsRegistry
{
    /** The section plugin-contributed settings are rendered under. */
    public const SECTION = 'plugins';

    /** The storage discriminator a plugin's rows are kept under. */
    public const GROUP_PREFIX = 'plugin:';

    /** @var list<string> */
    private array $providerClasses;

    /** @var (Closure(string): PluginSettingsProviderInterface)|null */
    private readonly ?Closure $resolver;

    /** @var list<PluginSettingsProviderInterface>|null */
    private ?array $providers = null;

    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    /**
     * True while the definitions are being built.
     *
     * The definitions are read while the configuration is built, and a
     * provider is free to depend on the configuration. Without this guard
     * that dependency would rebuild the configuration from a half-built
     * registry, for ever. The re-entrant read falls back to the host's own
     * declarations, which is enough to finish the provider's construction.
     */
    private bool $resolving = false;

    /**
     * @param list<string> $providerClasses Class names of the providers, resolved through `$resolver`.
     * @param (Closure(string): PluginSettingsProviderInterface)|null $resolver
     */
    public function __construct(
        array $providerClasses = [],
        ?Closure $resolver = null,
    ) {
        $this->providerClasses = $providerClasses;
        $this->resolver = $resolver;
    }

    /**
     * The plugin-contributed settings, keyed by their full setting key.
     *
     * @return array<string, SettingDefinition>
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        if ($this->resolving) {
            return [];
        }

        $this->resolving = true;
        try {
            $this->definitions = $this->build();
        } finally {
            $this->resolving = false;
        }

        return $this->definitions;
    }

    /**
     * The providers that contributed, in the order they were resolved.
     *
     * @return list<PluginSettingsProviderInterface>
     */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];
        foreach ($this->providerClasses as $class) {
            if ($this->resolver === null) {
                continue;
            }

            try {
                $instance = ($this->resolver)($class);
            } catch (Throwable $e) {
                self::report(sprintf('plugin settings provider "%s" could not be resolved: %s', $class, $e->getMessage()));

                continue;
            }

            $providers[] = $instance;
        }

        return $this->providers = $providers;
    }

    /**
     * The full setting key for a plugin's own key.
     *
     * A plugin gives its key without a prefix; the prefix is added here. A
     * key that arrives with the prefix already on it is stripped first, so a
     * plugin that spells out the whole name does not end up with
     * `SPORA_SOCIAL_SPORA_SOCIAL_FOO`.
     */
    public static function fullKey(string $slug, string $key): string
    {
        return 'SPORA_' . strtoupper($slug) . '_' . strtoupper(self::bareKey($slug, $key));
    }

    /**
     * The storage group a plugin's rows are kept under.
     */
    public static function groupFor(string $slug): string
    {
        return self::GROUP_PREFIX . $slug;
    }

    private static function bareKey(string $slug, string $key): string
    {
        $bare = preg_replace('/^SPORA_/i', '', $key) ?? $key;

        $slugPrefix = strtoupper($slug) . '_';
        if (str_starts_with(strtoupper($bare), $slugPrefix)) {
            $bare = substr($bare, strlen($slugPrefix));
        }

        return $bare;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private function build(): array
    {
        $definitions = [];

        foreach ($this->providers() as $provider) {
            $slug = $provider->slug();
            if ($slug === '') {
                self::report(sprintf('plugin settings provider "%s" has no slug', $provider::class));

                continue;
            }

            try {
                $settings = $provider->settings();
            } catch (Throwable $e) {
                self::report(sprintf('plugin settings provider "%s" failed to list its settings: %s', $provider::class, $e->getMessage()));

                continue;
            }

            $group = self::groupFor($slug);
            foreach ($settings as $definition) {
                $key = self::fullKey($slug, $definition->key);
                $definitions[$key] = new SettingDefinition(
                    key: $key,
                    label: $definition->label,
                    type: $definition->type,
                    default: $definition->default,
                    group: $group,
                    section: self::SECTION,
                    description: $definition->description,
                    secret: $definition->secret,
                    // A plugin's settings are read at request time, never
                    // before the database is open, so they are stored and
                    // edited like any other setting.
                    bootOnly: false,
                    options: $definition->options,
                    validation: $definition->validation,
                    transform: $definition->transform,
                );
            }
        }

        return $definitions;
    }

    /**
     * Report a provider that misbehaved.
     *
     * `error_log` and not the PSR-3 logger: the registry is read while the
     * configuration is built, and the logger is built *from* that
     * configuration — so there is no logger to inject here. The same reason
     * {@see \Spora\Plugins\PluginLoader} reports a failing subscriber to
     * `error_log`.
     */
    private static function report(string $message): void
    {
        error_log('[spora] ' . $message);
    }
}
