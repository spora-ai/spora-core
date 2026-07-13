<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Psr\Log\NullLogger;
use Spora\Core\Extension\PluginManager;
use Spora\Core\Paths;

/** Wires a {@see PluginManager} with a {@see FakeProcessFactory} and the test base path. */
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
