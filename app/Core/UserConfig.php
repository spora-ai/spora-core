<?php

declare(strict_types=1);

namespace Spora\Core;

final class UserConfig
{
    public static function load(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        return require_once $path;
    }
}
