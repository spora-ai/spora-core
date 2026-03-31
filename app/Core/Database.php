<?php

declare(strict_types=1);

namespace Spora\Core;

use Illuminate\Database\Capsule\Manager as Capsule;

final class Database
{
    private static bool $booted = false;

    public function __construct(private readonly array $config) {}

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
            // SQLite default
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
            ]);
        }

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$booted = true;
    }

    public function boot(): void
    {
        $this->bootDatabaseConnectionOnly();

        $dbPath = (string) ($this->config['db_path'] ?? (__DIR__ . '/../../storage/database.sqlite'));

        // In-memory SQLite has no persistence — always run migrations immediately.
        if ($dbPath === ':memory:') {
            (new DatabaseSchemaInstaller())->install();
            return;
        }

        // For file-based databases: a version file prevents re-running migrations
        // on every PHP request (shared hosting zero-config optimisation).
        // MySQL users invoke `bin/spora spora:install` explicitly instead.
        $versionFile = dirname($dbPath) . '/.db_version';

        if (!file_exists($versionFile) || (int) file_get_contents($versionFile) < DatabaseSchemaInstaller::CODE_VERSION) {
            (new DatabaseSchemaInstaller())->install();
            file_put_contents($versionFile, (string) DatabaseSchemaInstaller::CODE_VERSION);
        }
    }

    /** Reset the static boot flag (for testing only). */
    public static function resetBootState(): void
    {
        self::$booted = false;
    }
}
