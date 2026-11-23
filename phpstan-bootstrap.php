<?php

declare(strict_types=1);

// Bootstrap for PHPStan: define constants that are normally set in public/index.php
// at runtime but are not available during static analysis.
define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/vendor/autoload.php';

// Larastan reads the Laravel version as an unqualified constant, so the
// definition has to be global. A namespaced one is invisible to the classes
// in a sub-namespace: `Larastan\Larastan\Methods` resolves the name against
// its own namespace and then the global one, never the parent's.
if (!defined('LARAVEL_VERSION')) {
    define('LARAVEL_VERSION', '13.0.0');
}

if (!function_exists('config_path')) {
    function config_path(string $path = ''): string {
        return BASE_PATH . '/config' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('app_path')) {
    function app_path(string $path = ''): string {
        return BASE_PATH . '/app' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string {
        return BASE_PATH . '/storage' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('database_path')) {
    function database_path(string $path = ''): string {
        return BASE_PATH . '/database' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
