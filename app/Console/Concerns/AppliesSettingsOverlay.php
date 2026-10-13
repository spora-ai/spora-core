<?php

declare(strict_types=1);

namespace Spora\Console\Concerns;

use Psr\Container\ContainerInterface;
use Spora\Core\Config;
use Spora\Core\Database;
use Spora\Settings\SettingsRegistry;
use Spora\Settings\SettingsRepository;

/**
 * Opts a command into the database layer of {@see Config}.
 *
 * `Kernel::handle()` applies the overlay, so every web request sees stored
 * settings. The CLI never calls `handle()` — `bin/spora` builds the kernel
 * and pulls commands straight out of the container — so a command that read
 * `Config` was reading defaults, `config.php` and `.env` only, and the
 * admin page's `source: database` was a lie for anything the worker used.
 *
 * Opt-in rather than applied once in `bin/spora`, because the overlay needs an
 * open database and some commands must work without one: `db:reset` deletes
 * it. A global call would make resetting a database depend on being able to
 * read it.
 */
trait AppliesSettingsOverlay
{
    /**
     * Boot the database connection, then layer the settings store over the
     * `Config` this container hands out. Idempotent for the boot half; the
     * overlay is idempotent too, so calling it twice costs a query.
     */
    private function applyRuntimeSettings(
        ContainerInterface $container,
        Database $database,
    ): void {
        $database->bootDatabaseConnectionOnly();

        $container->get(Config::class)->applySettingsOverlay(
            $container->get(SettingsRepository::class),
            $container->get(SettingsRegistry::class),
        );
    }
}
