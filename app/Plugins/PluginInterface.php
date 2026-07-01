<?php

declare(strict_types=1);

namespace Spora\Plugins;

use Spora\Extensions\SporaExtensionInterface;

/**
 * Marker interface for Composer-distributed Spora plugins.
 *
 * PluginInterface is now a pure marker — the actual hook surface
 * (`tools()`, `drivers()`, `register()`, …) lives on the parent
 * {@see SporaExtensionInterface}, so an `AppInterface` and a
 * `PluginInterface` share the same contract and the same wiring.
 *
 * To get empty defaults for every hook for free, extend
 * {@see \Spora\Extensions\AbstractExtension} instead of implementing
 * this interface directly.
 */
interface PluginInterface extends SporaExtensionInterface {}
