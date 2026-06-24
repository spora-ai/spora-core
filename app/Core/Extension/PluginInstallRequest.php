<?php

declare(strict_types=1);

namespace Spora\Core\Extension;

/**
 * Input value object for {@see PluginManager::install()}.
 *
 * Mutual exclusion: pass either `version` (registry / VCS) or `path` (local
 * checkout installed as a Composer path repository for sibling-clone dev
 * workflows), not both.
 *
 * The manager does NOT validate `package` — it is passed straight to
 * `composer require` and the operator is trusted to supply a real vendor/name.
 * Validation belongs at the call site (CLI flag / HTTP body).
 */
final class PluginInstallRequest
{
    public function __construct(
        public readonly string $package,
        public readonly ?string $version = null,
        public readonly ?string $path = null,
    ) {}
}
