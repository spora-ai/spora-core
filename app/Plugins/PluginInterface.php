<?php

declare(strict_types=1);

namespace Spora\Plugins;

use Spora\Extensions\SporaExtensionInterface;

/**
 * Contract for Composer-distributed Spora plugins.
 *
 * PluginInterface adds one hook to the parent contract —
 * `translationsPaths()`. The rest of the surface (`tools()`, `apps()`,
 * `skillPaths()`, `agentTemplatePaths()`, `schemaVersion()`,
 * `migrationsPath()`, …) lives on the parent
 * {@see SporaExtensionInterface}, so an App and a Plugin share that contract
 * and the same wiring.
 *
 * To get empty defaults for every hook for free, extend
 * {@see AbstractPlugin} or {@see \Spora\Extensions\AbstractExtension}
 * instead of implementing this interface directly.
 */
interface PluginInterface extends SporaExtensionInterface
{
    /**
     * Absolute paths to this plugin's translation catalogues — one directory
     * per set of `<locale>.json` files.
     *
     * The catalogue key *is* the English source string, so the same plain
     * word lands in several plugins' catalogues at once. Core wins a
     * collision: the plugin's entry is dropped with a logged warning, so
     * shipping a duplicate can never break an install.
     *
     * A PHP hook rather than a `plugin.json` key — that schema is
     * `additionalProperties: false` and owned by `spora-docs`, so a new
     * manifest field would be schema churn in a repo this one does not own.
     *
     * @return string[]
     */
    public function translationsPaths(): array;
}
