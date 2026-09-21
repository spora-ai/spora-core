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
        private readonly array $config,
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

        $driver = $this->config['db_driver'] ?? 'sqlite';

        // Pass `mariadb` through as the driver's literal name so Illuminate's MariaDB grammar is used and `Connection::getDriverName()` matches the `=== 'mariadb'` gates in migrations.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $capsule->addConnection([
                'driver'    => $driver,
                'host'      => $this->config['db_host'] ?? '127.0.0.1',
                'port'      => $this->config['db_port'] ?? 3306,
                'database'  => $this->config['db_name'] ?? '',
                'username'  => $this->config['db_user'] ?? '',
                'password'  => $this->config['db_password'] ?? '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
            ]);
        } else {
            $dbPath = $this->config['db_path'] ?? (__DIR__ . '/../../storage/database.sqlite');

            $dir = dirname((string) $dbPath);
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
                'busy_timeout' => (int) ($this->config['sqlite_busy_timeout'] ?? 5000),
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
        $dbPath    = $this->config['db_path'] ?? null;
        $stampPath = ($dbPath === ':memory:')
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
     * Read-only access to the resolved config array (defaults merged with
     * config.php and SPORA_* env vars). Useful for callers that need driver /
     * host / db_name / etc. without going through Eloquent.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Path to the schema-stamp cache file. Returns null for `:memory:`
     * SQLite (tests) where there's no persistent filesystem to cache.
     */
    public function getStampPath(): ?string
    {
        $dbPath = $this->config['db_path'] ?? null;
        if ($dbPath === ':memory:') {
            return null;
        }
        return $this->paths?->storage('.schema_stamp') ?? BASE_PATH . '/storage/.schema_stamp';
    }

    /** Reset the static boot flag (for testing only). */
    public static function resetBootState(): void
    {
        self::$booted  = false;
        self::$capsule = null;
        // A test that bypasses the factory and inlines `new Database([sqlite :memory:])->boot()` would otherwise inherit the worker's "already installed" skip and fail every insert with "no such table".
        self::$schemaInstallSkipped = false;
    }
}
