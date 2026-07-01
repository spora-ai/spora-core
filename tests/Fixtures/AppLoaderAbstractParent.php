<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Spora\Extensions\AbstractExtension;

/**
 * Abstract parent used by AppLoaderTest's "concrete App over abstract
 * parent" regression test. Lives here (NOT in AppLoaderTest.php) so the
 * test fixture file itself doesn't transitively autoload
 * {@see AbstractExtension} — when the test writes an App.php that
 * references this class via a string and require_once's it, both
 * AppLoaderAbstractParent and AbstractExtension get added to
 * `get_declared_classes()` between the snapshot and the diff. That's
 * the exact scenario the bug exposes.
 */
abstract class AppLoaderAbstractParent extends AbstractExtension
{
    public function getName(): string
    {
        return 'Tests\\Fixtures\\AppLoaderAbstractParent';
    }
}
