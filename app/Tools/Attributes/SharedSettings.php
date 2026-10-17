<?php

declare(strict_types=1);

namespace Spora\Tools\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a class as a shared settings group: one credential set declared
 * once per plugin and inherited by every tool that names the group through
 * {@see \Spora\Tools\ConsumesSharedSettings}.
 *
 * The class carries ordinary `#[ToolSetting]` attributes — the same ones a
 * tool uses. It is not a tool, so it declares no `#[Tool]`, and its values
 * are stored in `tool_configurations` keyed by this class's FQCN, which is
 * what gives the group encryption, `'***'` redaction and the rest of the
 * `ToolConfigService` storage contract for free.
 *
 * Usage:
 *   #[SharedSettings(
 *       group: 'minimax',
 *       label: 'MiniMax',
 *       description: 'Credentials shared by every MiniMax tool.',
 *       icon: 'sparkles',              // optional; same key space as #[Tool(icon:)]
 *       order: 10,                      // optional; ascending in the Settings UI
 *       plugin: 'spora-plugin-minimax', // optional; audit trail only
 *   )]
 *   #[ToolSetting(key: 'api_key', label: 'API key', type: 'password', required: true)]
 *   final class MinimaxSettings {}
 *
 * The group is a *fallback* layer, not an override: the consuming tool's own
 * global/principal/agent value always wins, and a tool that never names the
 * group is untouched by it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SharedSettings
{
    /**
     * The one rule for a group id. Deliberately identical to
     * {@see Tool::NAME_REGEX} and to the shape of the plugin constants that
     * {@see \Spora\Tools\Traits\ReadsSharedSettings} returns, because the
     * three are compared as strings: a group id that could be spelled two
     * ways would silently match nothing.
     */
    public const GROUP_REGEX = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        /** snake_case group id, matched against ConsumesSharedSettings::sharedSettingsGroup() */
        public readonly string $group,
        /** Operator-facing group name, e.g. "MiniMax". */
        public readonly string $label = '',
        /** One line explaining what the group covers, shown above the shared form. */
        public readonly string $description = '',
        /** Bundled-icon key for the group row; same key space as #[Tool(icon:)]. */
        public readonly string $icon = '',
        /** Ascending sort hint for the Settings UI. Lower comes first. */
        public readonly int $order = 0,
        /**
         * Slug of the plugin that owns the group. Not used for resolution —
         * {@see \Spora\Tools\SharedSettingsResolver} keys on `group` alone —
         * but recorded so an audit can say where a stored credential came
         * from after the plugin has been uninstalled.
         */
        public readonly string $plugin = '',
    ) {
        if (!preg_match(self::GROUP_REGEX, $this->group)) {
            throw new InvalidArgumentException(
                "SharedSettings group '{$this->group}' must match " . self::GROUP_REGEX
                . " (snake_case, lowercase alphanumeric + underscore).",
            );
        }
    }
}
