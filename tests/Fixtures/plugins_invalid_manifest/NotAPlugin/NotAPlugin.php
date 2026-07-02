<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\NotAPlugin;

/**
 * Intentionally does NOT implement {@see \Spora\Plugins\PluginInterface}.
 * Used by tests that exercise the "class is autoloadable but not a plugin" branch
 * of PluginLoader::instantiatePlugin().
 */
final class NotAPlugin {}
