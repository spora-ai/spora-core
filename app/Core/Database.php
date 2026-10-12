<?php

declare(strict_types=1);

namespace Spora\Core;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Exceptions\DatabaseNotBootedException;
use Spora\Extensions\AppLoader;
use Spora\Plugins\PluginLoader;

final class Database
{
    /**
     * Eloquent ORM connection bootstrap plus framework-wide schema installation,
     * including plugin- and App-contributed migrations.
     */
    private static bool $booted = false;

    /** Stored so DatabaseSchemaInstaller can access getDatabaseManager() after boot. */
    private static ?Capsule $capsule = null;

    /** Skip the install step on subsequent `boot()` calls in the same worker — only set by `TestDatabaseFactory`. */
    private static bool $schemaInstallSkipped = false;

    public function __construct(
        private readonly Config $config,
        private readonly ?PluginLoader $pluginLoader = null,
        private readonly ?Paths $paths = null,
        private readonly ?AppLoader $appLoader = null,
    ) {}

    public function bootDatabaseConnectionOnly(): void
    {
        if (self::$booted) {
            return;
        }

        $capsule = new Capsule();

        // SPORA_DB_HOST, SPORA_DB_PORT, SPORA_DB_NAME, SPORA_DB_USER,
        // SPORA_DB_PASSWORD and SPORA_SQLITE_BUSY_TIMEOUT carry no registry
        // default, so the fallbacks below stay — the registry is the single
        // declaration of these only once it declares them.
        $driver = $this->config->getString('SPORA_DB_DRIVER');

        // Pass `mariadb` through as the driver's literal name so Illuminate's MariaDB grammar is used and `Connection::getDriverName()` matches the `=== 'mariadb'` gates in migrations.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $capsule->addConnection([
                'driver'    => $driver,
                'host'      => $this->config->getString('SPORA_DB_HOST') ?? '127.0.0.1',
                'port'      => $this->config->getInt('SPORA_DB_PORT') ?? 3306,
                'database'  => $this->config->getString('SPORA_DB_NAME') ?? '',
                'username'  => $this->config->getString('SPORA_DB_USER') ?? '',
                'password'  => $this->config->getString('SPORA_DB_PASSWORD') ?? '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
            ]);
        } else {
            $dbPath = $this->sqlitePath();

            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (!file_exists($dbPath)) {
                touch($dbPath);
            }

            $capsule->addConnection([
                'driver'   => 'sqlite',
                'database' => $dbPath,
                'prefix'   => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => $this->config->getInt('SPORA_SQLITE_BUSY_TIMEOUT', 5000),
                'journal_mode' => 'wal',
                'synchronous'  => 'NORMAL',
                'pragmas' => [
                    'wal_autocheckpoint' => 100,
                    'cache_size' => -32000,
                ],
            ]);
        }

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
        self::$booted  = true;
    }

    public function boot(): void
    {
        $this->bootDatabaseConnectionOnly();

        if (self::$schemaInstallSkipped) {
            // Worker DB already has the schema; the per-worker stamp file would otherwise be shared across workers against different DBs.
            return;
        }

        // Stamp file is skipped for `:memory:` SQLite (no persistent fs); otherwise it's the O(1) hot-path cache.
        $stampPath = $this->usesInMemorySqlite()
            ? null
            : ($this->paths?->storage('.schema_stamp') ?? BASE_PATH . '/storage/.schema_stamp');

        (new DatabaseSchemaInstaller($this->pluginLoader, $stampPath, null, $this->paths, $this->appLoader))->install();
    }

    /** Toggle the schema-install short-circuit — `true` after first install in a worker; reset to `false` by `TestDatabaseFactory::freshDatabase()`. */
    public static function setSchemaInstallSkipped(bool $skipped): void
    {
        self::$schemaInstallSkipped = $skipped;
    }

    /** Returns the active Capsule instance (available after bootDatabaseConnectionOnly). */
    public static function getCapsule(): Capsule
    {
        if (self::$capsule === null) {
            throw new DatabaseNotBootedException('Database not booted yet.');
        }
        return self::$capsule;
    }

    /**
     * Read-only access to the resolved configuration, for callers that need
     * driver / host / db name / etc. without going through Eloquent.
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Path to the schema-stamp cache file. Returns null for `:memory:`
     * SQLite (tests) where there's no persistent filesystem to cache.
     */
    public function getStampPath(): ?string
    {
        if ($this->usesInMemorySqlite()) {
            return null;
        }
        return $this->paths?->storage('.schema_stamp') ?? BASE_PATH . '/storage/.schema_stamp';
    }

    /**
     * The registry defaults `SPORA_DB_PATH` to `Paths::storage()`, but a
     * hand-built `Config` — the tests construct one directly — need not carry
     * it, and the connection cannot be opened without a real path.
     */
    private function sqlitePath(): string
    {
        return $this->config->getString('SPORA_DB_PATH')
            ?? $this->paths?->storage('database.sqlite')
            ?? __DIR__ . '/../../storage/database.sqlite';
    }

    private function usesInMemorySqlite(): bool
    {
        return $this->config->getString('SPORA_DB_PATH') === ':memory:';
    }

    /** Reset the static boot flag (for testing only). */
    public static function resetBootState(): void
    {
        self::$booted  = false;
        self::$capsule = null;
        // A test that bypasses the factory and inlines a `:memory:` Database would otherwise inherit the worker's "already installed" skip and fail every insert with "no such table".
        self::$schemaInstallSkipped = false;
    }
}
