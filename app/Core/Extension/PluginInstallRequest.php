<?php

declare(strict_types=1);

namespace Spora\Core\Extension;

/**
 * Input value object for {@see PluginManager::install()}.
 *
 * Exactly one of `version` or `path` is meaningful per request:
 *  - `version` selects a Composer-distributed package (registry, VCS, or path-repo
 *    already registered in composer.json).
 *  - `path` installs a local checkout as a Composer path repository and requires
 *    the virtual package name. Use this during plugin development against a
 *    sibling git clone.
 *
 * `package` is required and must be a non-empty Composer package name
 * (vendor/name). Validation is the caller's responsibility — the manager
 * passes the value straight to `composer require` and trusts the user input.
 */
final class PluginInstallRequest
{
    public function __construct(
        public readonly string $package,
        public readonly ?string $version = null,
        public readonly ?string $path = null,
    ) {}
}
