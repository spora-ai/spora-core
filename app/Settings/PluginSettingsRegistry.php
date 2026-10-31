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
 *
 * The host owns the key space too: a plugin whose slug and key spell a core
 * setting is refused, because a plugin that could redefine `SPORA_DB_DRIVER`
 * would move it out of the `framework` group and off the boot-only list.
 */
final class PluginSettingsRegistry
{
    public const SECTION = 'plugins';

    public const GROUP_PREFIX = 'plugin:';

    /** @var list<string> */
    private array $providerClasses;

    /** @var (Closure(string): PluginSettingsProviderInterface)|null */
    private readonly ?Closure $resolver;

    /**
     * The core setting keys a plugin may not redefine.
     *
     * @var (Closure(): list<string>)|null
     */
    private readonly ?Closure $reservedKeys;

    /** @var list<PluginSettingsProviderInterface>|null */
    private ?array $providers = null;

    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    /**
     * True while the definitions are being built.
     *
     * A provider may depend on the configuration, and the configuration is
     * what asks for the definitions: the provider is built while the config
     * is read, and a provider that reads the settings registry in its own
     * constructor re-enters this one. The guard stops that read from
     * recursing for ever: the re-entrant read sees no plugin settings.
     */
    private bool $resolving = false;

    /** @var array<string, array{provider: string, message: string}> */
    private array $problems = [];

    /**
     * @param list<string> $providerClasses Class names of the providers, resolved through `$resolver`.
     * @param (Closure(string): PluginSettingsProviderInterface)|null $resolver
     * @param (Closure(): list<string>)|null $reservedKeys The core setting keys a plugin may not redefine.
     */
    public function __construct(
        array $providerClasses = [],
        ?Closure $resolver = null,
        ?Closure $reservedKeys = null,
    ) {
        $this->providerClasses = $providerClasses;
        $this->resolver = $resolver;
        $this->reservedKeys = $reservedKeys;
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
                $this->report(
                    sprintf('plugin settings provider "%s" could not be resolved: %s', $class, $e->getMessage()),
                    $class,
                    $class,
                );

                continue;
            }

            $providers[] = $instance;
        }

        return $this->providers = $providers;
    }

    /**
     * The settings that did not make it, and why.
     *
     * A provider that cannot be resolved contributes nothing, and a provider
     * whose setting collides with a core one is refused. Either way the
     * operator has to be able to see it: a setting that silently does not
     * exist is a setting nobody configured.
     *
     * @return list<array{provider: string, message: string}>
     */
    public function problems(): array
    {
        return array_values($this->problems);
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
        $reserved = $this->reservedKeys();

        foreach ($this->providers() as $provider) {
            $slug = $provider->slug();
            if ($slug === '') {
                $this->report(
                    sprintf('plugin settings provider "%s" has no slug', $provider::class),
                    $provider::class,
                    $provider::class,
                );

                continue;
            }

            try {
                $settings = $provider->settings();
            } catch (Throwable $e) {
                $this->report(
                    sprintf('plugin settings provider "%s" failed to list its settings: %s', $provider::class, $e->getMessage()),
                    $provider::class,
                    $provider::class,
                );

                continue;
            }

            $group = self::groupFor($slug);
            foreach ($settings as $definition) {
                $key = self::fullKey($slug, $definition->key);

                if (in_array($key, $reserved, true)) {
                    $this->report(
                        sprintf(
                            'plugin settings provider "%s" tried to redefine the core setting "%s"; the core definition stands',
                            $provider::class,
                            $key,
                        ),
                        $provider::class,
                        $provider::class . '#' . $key,
                    );

                    continue;
                }

                // The group and the section are the host's, whatever the
                // provider declared: a plugin-contributed setting is stored,
                // listed and reset the way every other setting is.
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
                    min: $definition->min,
                );
            }
        }

        return $definitions;
    }

    /**
     * The core setting keys a plugin may not redefine.
     *
     * @return list<string>
     */
    private function reservedKeys(): array
    {
        if ($this->reservedKeys === null) {
            return [];
        }

        return ($this->reservedKeys)();
    }

    /**
     * Report a provider that misbehaved.
     *
     * `error_log` and not the PSR-3 logger: the plugin settings are folded
     * into the configuration on its first read, and that read can happen
     * while the logger is still being built — the logger is built *from*
     * that configuration — so there is no logger to inject here. The same
     * reason {@see \Spora\Plugins\PluginLoader} reports a failing subscriber
     * to `error_log`.
     *
     * The message is also kept, so the operator sees the failure in the
     * settings response rather than only in the error log.
     *
     * @param string      $id Deduplication key; one problem per id.
     */
    private function report(string $message, string $provider, string $id): void
    {
        error_log('[spora] ERROR ' . $message);

        $this->problems[$id] = [
            'provider' => $provider,
            'message'  => $message,
        ];
    }
}
