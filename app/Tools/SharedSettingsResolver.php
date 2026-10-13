<?php

declare(strict_types=1);

namespace Spora\Tools;

use ReflectionClass;
use Spora\Tools\Attributes\SharedSettings;

/**
 * Resolves which shared settings group a tool falls back to.
 *
 * The registry is the list of settings classes the loader fanned out from
 * {@see \Spora\Plugins\PluginInterface::settingsClasses()} — an empty list is
 * the normal state for a host with no plugin shipping a group, and every tool
 * then resolves to no group and keeps exactly the cascade it had before.
 *
 * This class holds no I/O: the stored values for a group live in
 * `tool_configurations` keyed by the group class's FQCN, and the caller
 * supplies the read as a callable. That is what lets {@see \Spora\Services\ToolConfigService}
 * depend on the resolver without the two forming a cycle — the read is a
 * first-class callable into a method the caller already owns, invoked only
 * when a group was actually resolved.
 *
 * Precedence, low to high:
 *
 *   schema defaults < shared group < tool global < principal/group < agent override
 *
 * The group is therefore an overlay on the *schema defaults*, applied before
 * the tool's own rows are merged — never appended to the finished result,
 * which would invert the whole cascade.
 */
final class SharedSettingsResolver
{
    /** @var array<string, string> group id => settings class FQCN. */
    private array $groupsById;

    /** @var array<string, string|null> tool class => group class, memoised per tool. */
    private array $toolGroups = [];

    /**
     * @param list<class-string> $settingsClasses Settings-group classes declared by
     *        loaded plugins. A group id declared twice resolves to the first
     *        class, matching {@see \Spora\Plugins\PluginLoader}'s first-slug-wins
     *        rule for plugin directories themselves.
     */
    public function __construct(private readonly array $settingsClasses = [])
    {
        $this->groupsById = [];
        foreach ($settingsClasses as $class) {
            $id = self::groupIdOf($class);
            if ($id === null) {
                continue;
            }
            $this->groupsById[$id] ??= $class;
        }
    }

    /**
     * The settings class whose values a tool falls back to, or null when the
     * tool names no group, implements no {@see ConsumesSharedSettings}, or
     * names a group no loaded plugin declared.
     *
     * The last case is deliberately silent: a plugin that adds a tool but
     * forgets to declare its group class gets today's behaviour — the
     * operator types the key on that tool — rather than a runtime failure on a
     * settings read. Turning it into a hard error is a product decision, not
     * a resolution one.
     *
     * @return class-string|null
     */
    public function groupClassFor(string $toolClass): ?string
    {
        if (array_key_exists($toolClass, $this->toolGroups)) {
            return $this->toolGroups[$toolClass];
        }

        $this->toolGroups[$toolClass] = $this->resolveGroupClass($toolClass);

        return $this->toolGroups[$toolClass];
    }

    /**
     * Overlay the tool's group values onto its schema defaults.
     *
     * Only the keys the tool itself declares are taken: a group may hold
     * settings for tools that no longer ship, and those must not leak into a
     * tool that never asked for them.
     *
     * @param  array<string, mixed> $defaults
     * @param  callable(class-string): array<string, mixed> $readSettings
     * @return array<string, mixed>
     */
    public function applyToDefaults(string $toolClass, array $defaults, callable $readSettings): array
    {
        $groupClass = $this->groupClassFor($toolClass);
        if ($groupClass === null) {
            return $defaults;
        }

        $shared = $readSettings($groupClass);

        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            $value = $shared[$setting->key] ?? null;
            if ($value !== null && $value !== '') {
                $defaults[$setting->key] = $value;
            }
        }

        return $defaults;
    }

    /**
     * The group values a tool renders as read-only "inherited from …", limited
     * to the keys the tool declares.
     *
     * The values returned here are decrypted. Every caller must mask them
     * against the **group** class, not the tool class: the value was encrypted
     * at rest under the group's own `#[ToolSetting(type: 'password')]` list, so
     * a tool that misdeclares the key as plain text must not turn the shared
     * credential into readable JSON on the wire.
     *
     * @param  callable(class-string): array<string, mixed> $readSettings
     * @return array<string, mixed>
     */
    public function inheritedValues(string $toolClass, callable $readSettings): array
    {
        $groupClass = $this->groupClassFor($toolClass);
        if ($groupClass === null) {
            return [];
        }

        $shared = $readSettings($groupClass);

        $inherited = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            if (array_key_exists($setting->key, $shared)) {
                $inherited[$setting->key] = $shared[$setting->key];
            }
        }

        return $inherited;
    }

    /**
     * @return class-string|null
     */
    private function resolveGroupClass(string $toolClass): ?string
    {
        if (!class_exists($toolClass) || !is_subclass_of($toolClass, ConsumesSharedSettings::class)) {
            return null;
        }

        // The group id comes from a class constant, so the constructor cannot
        // affect it. Constructing without one keeps a consuming tool free to
        // declare constructor dependencies that the resolver has no business
        // resolving.
        $instance = (new ReflectionClass($toolClass))->newInstanceWithoutConstructor(); // NOSONAR php:S3011 — the constructor is deliberately not run; see comment above

        return $this->groupsById[$instance->sharedSettingsGroup()] ?? null;
    }

    /**
     * @param  class-string $class
     * @return string|null
     */
    private static function groupIdOf(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }

        $attrs = (new ReflectionClass($class))->getAttributes(SharedSettings::class);
        if ($attrs === []) {
            return null;
        }

        return $attrs[0]->newInstance()->group;
    }
}
