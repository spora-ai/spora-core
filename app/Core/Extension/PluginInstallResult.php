<?php

declare(strict_types=1);

namespace Spora\Core\Extension;

/**
 * Output value object returned by {@see PluginManager}
 * for the mutating operations (install, uninstall, update).
 *
 * `status` is one of the self-typed status constants below. Callers (CLI
 * commands, HTTP controllers) map status to an exit code / HTTP code; the
 * manager itself stays UI-agnostic.
 *
 * Note: {@see PluginManager::list()} returns a plain
 * array of {name, version, path} entries directly — it does NOT construct
 * a PluginInstallResult. The `$plugins` field below is reserved for future
 * call sites that need to return a structured list response (e.g. an HTTP
 * controller wrapping the list output).
 */
final class PluginInstallResult
{
    public const STATUS_INSTALLED   = 'installed';
    public const STATUS_UNINSTALLED = 'uninstalled';
    public const STATUS_UPDATED     = 'updated';

    /**
     * @param string                                                $package  Composer package name (vendor/name).
     * @param string                                                $status   One of the STATUS_* constants.
     * @param string|null                                           $version  Resolved version, or null when not yet resolved / not applicable.
     * @param string|null                                           $path     Absolute install path, when known.
     * @param string                                                $message  Human-readable summary suitable for CLI output / toast.
     * @param list<array{name: string, version: ?string, path: ?string}> $plugins Optional structured list payload for HTTP responses; not populated by PluginManager directly.
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
