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
     * Either a bundled icon name (e.g. "puzzle", "brain", "bell") looked up
     * in the frontend's shared icon map, or a raw SVG path string (starting
     * with a path command letter: M/L/H/V/C/S/Q/T/A/Z) rendered directly.
     * The dual form lets third-party plugins ship their own icons without
     * depending on the central map.
     */
    public function icon(): string;
}
