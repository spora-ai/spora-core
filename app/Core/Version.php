<?php

declare(strict_types=1);

namespace Spora\Core;

use Composer\InstalledVersions;

/**
 * Single source of truth for the Spora application version.
 * The version string is read from composer.json via Composer's InstalledVersions
 * at runtime, with a hardcoded fallback for environments without Composer metadata.
 */
final class Version
{
    /**
     * Returns the current Spora version string (e.g. "0.1.0").
     * Falls back to "dev" if Composer's runtime API is unavailable.
     */
    public static function current(): string
    {
        if (class_exists(InstalledVersions::class)) {
            $version = InstalledVersions::getRootPackage()['version'];

            // Composer sets this placeholder string when there is no version tag.
            if ($version !== 'No version set (parsed as 1.0.0)') {
                return ltrim($version, 'v');
            }
        }

        return 'dev';
    }
}
