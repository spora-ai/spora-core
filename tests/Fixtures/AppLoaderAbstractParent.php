<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Extensions\AbstractExtension;

/**
 * Abstract parent for AppLoaderTest's regression. Lives outside the test
 * file on purpose: the only way PHP autoloads it (and AbstractExtension)
 * is via the require_once of the synthesized app/App.php — that is what
 * makes the bug reproducible.
 */
abstract class AppLoaderAbstractParent extends AbstractExtension
{
    public function getName(): string
    {
        return 'Tests\\Fixtures\\AppLoaderAbstractParent';
    }
}
