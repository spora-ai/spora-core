<?php

declare(strict_types=1);

namespace Spora\Apps;

/**
 * Contract for plugin applications that extend Spora's functionality.
 *
 * Apps expose metadata (name, icon, description) and are auto-discovered
 * and registered via the AppRegistry.
 */
interface AppInterface
{
    public function name(): string;

    public function displayName(): string;

    public function description(): string;

    /**
     * Bundled icon name (e.g. "puzzle"), raw SVG path, or full <svg> string.
     * Resolved by the shared <Icon> component. See plugin.schema.json and
     * docs/07_plugins.md for the accepted forms.
     */
    public function icon(): string;

    /**
     * Tile accent for the app in the host SPA's navbar drawer. Maps to a
     * Tailwind gradient + text colour on the frontend — see the `accent`
     * enum in plugin.schema.json for the accepted tokens. AppsController
     * resolves the final value with this precedence:
     *
     *   1. This method's return value (PHP wins — same rule as
     *      {@see \Spora\Apps\VueAppInterface::entry()} vs the manifest's
     *      `frontendEntry`).
     *   2. The plugin's `plugin.json#accent` field.
     *   3. The default `"primary"`.
     *
     * Unknown / empty values fall back to `"primary"` silently — matching
     * the host's icon fallback (unknown icon names → `puzzle`). New
     * plugin authors should pick the closest token in the existing
     * palette; if a new colour is genuinely needed, add it to the host's
     * `tileAccent()` map AND the schema enum in the same change so the
     * frontend doesn't ship an unstyled accent.
     */
    public function accent(): string;
}
