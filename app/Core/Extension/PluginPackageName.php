<?php

declare(strict_types=1);

namespace Spora\Core\Extension;

/**
 * Canonical validator for Composer `vendor/name` strings used as
 * path segments by the DELETE / PATCH plugin routes.
 *
 * Centralised so the plugin inventory listing and the mutating routes
 * agree on what counts as a valid package name. The two callers MUST
 * stay in sync — if you change the shape here, the inventory may
 * surface a `package` that the routes reject (or vice versa).
 */
final class PluginPackageName
{
    /**
     * Lowercase vendor/name with optional separator characters. Mirrors
     * Composer's own package-name rules, restricted to URL-safe characters
     * because the value is interpolated into a request path.
     */
    private const PATTERN = '/^[a-z0-9]([_.\-a-z0-9]*[a-z0-9])?\/[a-z0-9]([_.\-a-z0-9]*[a-z0-9])?$/';

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }
}
