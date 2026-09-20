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

    /**
     * Sticky flag flipped by the test bootstrap (`TestDatabaseFactory::boot()`)
     * after the first schema install on a per-worker database. Subsequent
     * `boot()` calls in the same worker short-circuit the install step —
     * the schema is already in place; only the Eloquent connection needs to
     * be (re-)established.
     *
     * Stays `false` in production. The factory is the only caller and is
     * loaded only via `composer test:parallel`, so production boots always
     * see the install path.
     */
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

        // `mariadb` rides the same wire protocol as MySQL but Illuminate ships a
        // distinct `MariaDbConnection` that swaps in the MariaDB grammars. We pass
        // the operator's choice through as the connection's `driver` so
        // `Connection::getDriverName()` returns the exact value the migrations
        // gate on (their `=== 'mariadb'` branches become reachable instead of
        // dead code). MySQL still uses `mysql`; either value produces a working
        // Eloquent connection.
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
            // The owning test worker has already installed the schema on its
            // per-worker database; re-running install() here would either no-op
            // (best case) or fail on a partial re-install (worst case). The
            // eager stamp-cache short-circuit in production doesn't apply to
            // per-worker DBs because the stamp file would be shared across
            // workers against databases that aren't.
            return;
        }

        // For :memory: SQLite (tests) there is no persistent filesystem, so the stamp
        // cache is disabled and the installer always runs the full DB check.
        // For all other drivers the stamp file gives an O(1) hot path on every HTTP request.
        $dbPath    = $this->config['db_path'] ?? null;
        $stampPath = ($dbPath === ':memory:')
            ? null
            : ($this->paths?->storage('.schema_stamp') ?? BASE_PATH . '/storage/.schema_stamp');

        (new DatabaseSchemaInstaller($this->pluginLoader, $stampPath, null, $this->paths, $this->appLoader))->install();
    }

    /**
     * Toggle the schema-install short-circuit. Set `true` after a test worker
     * has finished its first schema install; set `false` to re-enable install
     * (used by `TestDatabaseFactory::freshDatabase()` when a test drops and
     * recreates its per-worker DB).
     */
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
        // Note: $schemaInstallSkipped is intentionally NOT reset here. It is
        // a worker-scoped flag owned by TestDatabaseFactory; resetting it
        // here would re-enable the (expensive) schema install on every test
        // that calls `Database::resetBootState()` between its setup phases.
    }
}
