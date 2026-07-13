<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Psr\Log\NullLogger;
use Spora\Core\Extension\PluginManager;
use Spora\Core\Paths;

/**
 * Test helper that wires a {@see PluginManager} with a {@see FakeProcessFactory}
 * and the operator-supplied base path.
 *
 * Centralises the `new PluginManager(new NullLogger(), Closure::fromCallable($factory), new Paths($basePath), $composerBinary)`
 * boilerplate that previously appeared inline in every test (and in the
 * `makePlugin*Tester()` helpers across the install / uninstall / update /
 * list command tests). Default arguments match the previous inline defaults
 * so callers can drop the helper in without changing behaviour.
 *
 * Note: `PluginManager`'s constructor still takes a `Closure` for
 * `$processFactory` (a historical seam from when tests replaced it with
 * a plain callable). The helper wraps `FakeProcessFactory` via
 * `Closure::fromCallable(...)` so callers can pass the factory directly.
 */
final class PluginManagerFactory
{
    public static function build(
        FakeProcessFactory $factory,
        string $basePath = '/srv/spora',
        string $composerBinary = 'composer',
    ): PluginManager {
        return new PluginManager(
            new NullLogger(),
            Closure::fromCallable($factory),
            new Paths($basePath),
            $composerBinary,
        );
    }
}
