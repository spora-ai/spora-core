<?php

declare(strict_types=1);

namespace Spora\Settings;

/**
 * A plugin that contributes operator-facing settings.
 *
 * The host collects the definitions from every plugin and renders them on the
 * Plugins tab of the settings page. The plugin owns what its settings mean;
 * the host owns where they live.
 */
interface PluginSettingsProviderInterface
{
    /** The plugin slug. The setting keys and the DB group are derived from it. */
    public function slug(): string;

    /**
     * The settings this plugin contributes.
     *
     * The key is given WITHOUT the `SPORA_<SLUG>_` prefix and WITHOUT the
     * `SPORA_` prefix — the registry builds the full key, so the rule is
     * enforced in one place instead of per plugin.
     *
     * The definitions are read while the configuration is being built, so a
     * provider must not touch the database here. It may depend on the
     * configuration: the host resolves the providers once the configuration
     * exists, so a provider that reads it to describe its settings works.
     *
     * The host owns the storage group and the section. A `group` or a
     * `section` on the definition is ignored — the key is rewritten, the
     * rows are filed under `plugin:<slug>`, and the setting is rendered in
     * the `plugins` section.
     *
     * A key that spells a core setting is refused. The host owns the key
     * space: a plugin that could redefine `SPORA_DB_DRIVER` would move it
     * out of the `framework` group and off the boot-only list, and the
     * stored row would be orphaned.
     *
     * @return list<SettingDefinition>
     */
    public function settings(): array;
}
