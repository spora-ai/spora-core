<?php

declare(strict_types=1);

namespace Spora\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Composer plugin entry point. Registers {@see SporaPluginInstaller} with
 * Composer's installation manager so that `spora-plugin` packages install
 * into `plugins/{$name}/` instead of the default `vendor/` location.
 */
final class SporaPluginInstallerPlugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $installer = new SporaPluginInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }
}
