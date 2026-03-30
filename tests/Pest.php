<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| This file is the entry point for Pest. Global test utilities, helpers,
| and dataset definitions live here.
|
*/

// Ensure BASE_PATH is defined for all tests
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

// Suppress E_DEPRECATED originating from delight-im vendor packages.
// delight-im/auth v9.0.0 uses implicit nullable types (e.g. `callable $x = null`)
// which PHP 8.4+ deprecates. The maintainer has acknowledged this (GitHub #314)
// but defers the fix to preserve PHP 7.0 compatibility. The warnings are harmless
// — nothing breaks at runtime — so we silence them here rather than patching vendor.
set_error_handler(static function (int $errno, string $errstr, string $errfile): bool {
    if ($errno === E_DEPRECATED && str_contains($errfile, \DIRECTORY_SEPARATOR . 'delight-im' . \DIRECTORY_SEPARATOR)) {
        return true;
    }

    return false;
}, E_DEPRECATED);
