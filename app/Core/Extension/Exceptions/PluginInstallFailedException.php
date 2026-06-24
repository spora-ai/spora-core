<?php

declare(strict_types=1);

namespace Spora\Core\Extension\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Spora\Core\Extension\PluginManager} when a Composer
 * invocation exits non-zero (install / uninstall / update / path-form
 * config-and-require). The {@see \Spora\Core\Extension\PluginManager::list()}
 * operation does NOT throw on bad JSON — it returns an empty array instead,
 * because "no plugins installed" is a normal state, not an error.
 *
 * Carries the underlying exit code and stderr so callers can surface the
 * Composer error message verbatim in CLI / HTTP responses.
 */
final class PluginInstallFailedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $exitCode = 0,
        public readonly string $stderr = '',
    ) {
        parent::__construct($message);
    }
}
