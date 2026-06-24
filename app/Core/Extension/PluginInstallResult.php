<?php

declare(strict_types=1);

namespace Spora\Core\Extension;

/**
 * Output value object returned by {@see PluginManager} install/uninstall/update
 * and by the list() method.
 *
 * `status` is one of the self-typed status constants below. Callers (CLI
 * commands, HTTP controllers) map status to an exit code / HTTP code; the
 * manager itself stays UI-agnostic.
 */
final class PluginInstallResult
{
    public const STATUS_INSTALLED   = 'installed';
    public const STATUS_UNINSTALLED = 'uninstalled';
    public const STATUS_UPDATED     = 'updated';

    /**
     * @param string                       $package  Composer package name (vendor/name).
     * @param string                       $status   One of the STATUS_* constants.
     * @param string|null                  $version  Resolved version, or null when not applicable (e.g. list entries).
     * @param string|null                  $path     Absolute install path, when known.
     * @param string                       $message  Human-readable summary suitable for CLI output / toast.
     * @param list<array{name: string, version: ?string, path: ?string}> $plugins
     *                                              Populated by list(); empty for install/uninstall/update.
     */
    public function __construct(
        public readonly string $package,
        public readonly string $status,
        public readonly ?string $version = null,
        public readonly ?string $path = null,
        public readonly string $message = '',
        public readonly array $plugins = [],
    ) {}
}
