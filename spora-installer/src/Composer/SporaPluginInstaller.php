<?php

declare(strict_types=1);

namespace Spora\Composer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/**
 * Routes packages of type `spora-plugin` to `plugins/{$name}/` when
 * installed via Composer. Other types fall through to Composer's default
 * library installer unchanged.
 */
final class SporaPluginInstaller extends LibraryInstaller
{
    private const SPORA_PLUGIN_TYPE = 'spora-plugin';

    public function __construct(IOInterface $io, Composer $composer)
    {
        parent::__construct($io, $composer);
    }

    public function supports(string $packageType): bool
    {
        return $packageType === self::SPORA_PLUGIN_TYPE;
    }

    public function getInstallPath(PackageInterface $package): string
    {
        [$vendor, $name] = explode('/', $package->getPrettyName());

        return "plugins/{$name}/";
    }
}
