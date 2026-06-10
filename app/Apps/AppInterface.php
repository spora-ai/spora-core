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
}
