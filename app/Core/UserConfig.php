<?php

declare(strict_types=1);

namespace Spora\Core;

final class UserConfig
{
    /** @var array<string, array> */
    private static array $cache = [];

    public static function load(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        if (!isset(self::$cache[$path])) {
            $loaded = require_once $path;
            self::$cache[$path] = is_array($loaded) ? $loaded : [];
        }
        return self::$cache[$path];
    }
}
