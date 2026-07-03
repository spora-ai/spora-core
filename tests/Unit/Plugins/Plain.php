<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use Spora\Plugins\AbstractPlugin;

/**
 * Subclass whose name does not end in "Plugin" — the default name derivation
 * must fall back to the unqualified class name in that case.
 */
final class Plain extends AbstractPlugin {}
