<?php

declare(strict_types=1);

// Bootstrap for PHPStan: define constants that are normally set in public/index.php
// at runtime but are not available during static analysis.
define('BASE_PATH', __DIR__);

if (!defined('Larastan\Larastan\LARAVEL_VERSION')) {
    define('Larastan\Larastan\LARAVEL_VERSION', '13.0.0');
}

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string {
        return __DIR__ . '/config' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('app_path')) {
    function app_path(string $path = ''): string {
        return __DIR__ . '/app' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string {
        return __DIR__ . '/storage' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('database_path')) {
    function database_path(string $path = ''): string {
        return __DIR__ . '/database' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
