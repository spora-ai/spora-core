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

        if ($this->config['db_driver'] === 'mysql') {
            $capsule->addConnection([
                'driver'    => 'mysql',
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

        // For :memory: SQLite (tests) there is no persistent filesystem, so the stamp
        // cache is disabled and the installer always runs the full DB check.
        // For all other drivers the stamp file gives an O(1) hot path on every HTTP request.
        $dbPath    = $this->config['db_path'] ?? null;
        $stampPath = ($dbPath === ':memory:')
            ? null
            : ($this->paths?->storage('.schema_stamp') ?? BASE_PATH . '/storage/.schema_stamp');

        (new DatabaseSchemaInstaller($this->pluginLoader, $stampPath, null, $this->paths, $this->appLoader))->install();
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
    }
}
