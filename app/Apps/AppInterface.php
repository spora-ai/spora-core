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
    /** Tile-accent tokens the host SPA renders. Mirror in `plugin.schema.json`'s `accent` enum and the host's `tileAccent()` map. */
    public const ACCENT_TOKENS = ['violet', 'amber', 'emerald', 'sky', 'rose', 'primary'];

    /** Fallback when no PHP method / manifest field resolves to a known value. */
    public const DEFAULT_ACCENT = 'primary';

    public function name(): string;

    public function displayName(): string;

    public function description(): string;

    /**
     * Bundled icon name (e.g. "puzzle"), raw SVG path, or full <svg> string.
     * Resolved by the shared <Icon> component. See plugin.schema.json and
     * docs/07_plugins.md for the accepted forms.
     */
    public function icon(): string;

    /** Tile-accent token. AppsController picks the value with PHP-method > manifest > default precedence; unknown tokens fall back silently. */
    public function accent(): string;
}
