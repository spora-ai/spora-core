<?php

declare(strict_types=1);

use Composer\InstalledVersions;

// Bootstrap for PHPStan: define constants that are normally set in public/index.php
// at runtime but are not available during static analysis.
define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/vendor/autoload.php';

// Larastan reads the Laravel version as an unqualified constant, so the
// definition has to be global. A namespaced one is invisible to the classes
// in a sub-namespace: `Larastan\Larastan\Methods` resolves the name against
// its own namespace and then the global one, never the parent's.
//
// The value is the installed `illuminate/database` version, because Larastan
// gates behaviour on it — the builder passthru methods it exposes, the stub
// directories it loads. A literal would describe a version nobody installed.
if (!defined('LARAVEL_VERSION') && InstalledVersions::isInstalled('illuminate/database')) {
    $installedVersion = InstalledVersions::getVersion('illuminate/database');

    if ($installedVersion !== null) {
        // Composer normalizes a release to four segments (`13.23.0.0`); the
        // constant Laravel itself defines carries three (`13.23.0`), and that
        // is the shape of every version Larastan compares this one against.
        define('LARAVEL_VERSION', implode('.', array_slice(explode('.', $installedVersion), 0, 3)));
    }
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
