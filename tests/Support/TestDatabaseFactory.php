<?php

/*
 * Per-worker / per-test database lifecycle for the Pest suite, driven by
 * `SPORA_TEST_DB_DRIVER`:
 *   - sqlite (default): every `boot()` rebuilds a fresh `:memory:` DB.
 *   - mysql / mariadb:  one `spora_test_w<PID>_<RAND>` DB per worker,
 *                       schema installed once, dropped on shutdown
 *                       (incl. SIGTERM/SIGINT so Pest's parent kills don't leak).
 *                       `freshDatabase()` drops+recreates per test for the
 *                       migration tests that issue DDL mid-test.
 *
 * Loaded via `composer.json#autoload-dev.files` so every worker sees one copy.
 */

declare(strict_types=1);

use Spora\Core\Database;

final class TestDatabaseFactory
{
    /** Cached after the first read so we don't re-touch `getenv()` on every call. */
    private static ?string $driver = null;

    /** `null` on SQLite or before the first MySQL/MariaDB boot in a worker. */
    private static ?string $workerDbName = null;

    /** `true` once the schema installer has run on the worker DB. */
    private static bool $workerSchemaInstalled = false;

    /** `true` after a test issues manual DDL that doesn't match the framework installer — next `boot()` drops the DB first. */
    private static bool $workerDbDirty = false;

    /** Resolves `SPORA_TEST_DB_DRIVER` to `sqlite` | `mysql` | `mariadb`. */
    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        $value = strtolower((string) (getenv('SPORA_TEST_DB_DRIVER') ?: 'sqlite'));
        if (!in_array($value, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException(
                "Unsupported SPORA_TEST_DB_DRIVER: '{$value}'. Expected sqlite, mysql, or mariadb.",
            );
        }
        return self::$driver = $value;
    }

    /** Per-test boot. On MySQL/MariaDB reconnects to the worker DB and installs the schema once per worker. */
    public static function boot(): void
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            Database::resetBootState();
            Database::setSchemaInstallSkipped(false);
            (new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
            return;
        }

        if (self::$workerDbName === null) {
            self::createWorkerDatabase();
        }

        // Drop+recreate before re-installing — manual DDL from bootConnectionOnly/freshConnectionOnly left the DB in a shape the installer can't extend.
        if (self::$workerDbDirty) {
            self::dropWorkerDatabase();
            self::createWorkerDatabase();
            self::invalidateLocalStamp();
            self::$workerDbDirty = false;
        }

        Database::resetBootState();
        if (self::$workerSchemaInstalled) {
            // Re-establish the skip flag — resetBootState() clears it so test files that bypass the factory don't inherit "already installed" against an empty DB.
            Database::setSchemaInstallSkipped(true);
        }

        (new Database(self::buildConfigForDriver()))->boot();

        self::$workerSchemaInstalled = true;
    }

    /** Per-test fresh-DB boot — slower than `boot()` (DROP + re-install). Use only when the test issues DDL mid-test. */
    public static function freshDatabase(): void
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            Database::resetBootState();
            Database::setSchemaInstallSkipped(false);
            (new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
            return;
        }

        self::dropWorkerDatabase();
        self::createWorkerDatabase();
        self::$workerSchemaInstalled = false;
        // The local stamp file (on the runner's fs, not the remote DB) still claims the schema is current and would short-circuit the install.
        self::invalidateLocalStamp();

        Database::resetBootState();
        Database::setSchemaInstallSkipped(false);
        (new Database(self::buildConfigForDriver()))->boot();
        Database::setSchemaInstallSkipped(true);
        self::$workerSchemaInstalled = true;
    }

    /** Connect-only boot for tests that build their own partial schema. */
    public static function bootConnectionOnly(): void
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            Database::resetBootState();
            Database::setSchemaInstallSkipped(false);
            (new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->bootDatabaseConnectionOnly();
            return;
        }

        if (self::$workerDbName === null) {
            self::createWorkerDatabase();
        }

        self::$workerDbDirty = true;

        Database::resetBootState();
        Database::setSchemaInstallSkipped(false);
        (new Database(self::buildConfigForDriver()))->bootDatabaseConnectionOnly();
    }

    /** Drop the worker DB, then connect-only boot — empty DB for the test to populate. */
    public static function freshConnectionOnly(): void
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            Database::resetBootState();
            Database::setSchemaInstallSkipped(false);
            (new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->bootDatabaseConnectionOnly();
            return;
        }

        self::dropWorkerDatabase();
        self::createWorkerDatabase();
        self::$workerSchemaInstalled = false;
        self::invalidateLocalStamp();
        self::$workerDbDirty = true;

        Database::resetBootState();
        Database::setSchemaInstallSkipped(false);
        (new Database(self::buildConfigForDriver()))->bootDatabaseConnectionOnly();
        // Stay `false` — next factory `boot()` owes an install.
        self::$workerSchemaInstalled = false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildConfigForDriver(): array
    {
        return [
            'db_driver'   => self::driver(),
            'db_host'     => (string) (getenv('SPORA_TEST_DB_HOST') ?: '127.0.0.1'),
            'db_port'     => (int) (getenv('SPORA_TEST_DB_PORT') ?: 3306),
            'db_name'     => (string) self::$workerDbName,
            'db_user'     => (string) (getenv('SPORA_TEST_DB_USER') ?: 'root'),
            'db_password' => (string) (getenv('SPORA_TEST_DB_PASSWORD') ?: ''),
        ];
    }

    /** `CREATE DATABASE` the worker's unique DB; SIGTERM/SIGINT handler drops it so Pest's parent kills don't leak. */
    private static function createWorkerDatabase(): void
    {
        $host = (string) (getenv('SPORA_TEST_DB_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('SPORA_TEST_DB_PORT') ?: 3306);
        $user = (string) (getenv('SPORA_TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('SPORA_TEST_DB_PASSWORD') ?: '');

        // PID keeps the name stable for the worker's lifetime; random suffix avoids two workers colliding on the same nanosecond.
        $dbName = sprintf(
            'spora_test_w%d_%s',
            getmypid() ?: random_int(1, PHP_INT_MAX),
            bin2hex(random_bytes(4)),
        );

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // 10s beats the default 30s when the runner hasn't brought the service up yet.
                PDO::ATTR_TIMEOUT => 10,
            ],
        );

        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $dbName,
        ));

        self::$workerDbName = $dbName;

        register_shutdown_function([self::class, 'dropWorkerDatabase']);

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, [self::class, 'dropWorkerDatabase']);
            pcntl_signal(SIGINT, [self::class, 'dropWorkerDatabase']);
        }
    }

    /** Mark the worker DB dirty — next `boot()` drops+reinstalls. No-op on SQLite (per-test `:memory:` rebuild). */
    public static function markWorkerDbDirty(): void
    {
        if (self::driver() === 'sqlite') {
            return;
        }
        self::$workerDbDirty = true;
    }

    /** Drop the worker DB. Idempotent, best-effort — failures swallowed (we're at shutdown). */
    public static function dropWorkerDatabase(): void
    {
        if (self::$workerDbName === null) {
            return;
        }

        $name = self::$workerDbName;
        self::$workerDbName = null;
        self::$workerSchemaInstalled = false;
        self::$workerDbDirty = false;

        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;charset=utf8mb4',
                    (string) (getenv('SPORA_TEST_DB_HOST') ?: '127.0.0.1'),
                    (int) (getenv('SPORA_TEST_DB_PORT') ?: 3306),
                ),
                (string) (getenv('SPORA_TEST_DB_USER') ?: 'root'),
                (string) (getenv('SPORA_TEST_DB_PASSWORD') ?: ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
            );
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $name));
        } catch (Throwable) {
            // shutdown — no place to surface the error
        }
    }

    /** Delete the local `storage/.schema_stamp` so the installer's hot path doesn't skip an install against a freshly-dropped worker DB. */
    private static function invalidateLocalStamp(): void
    {
        $stampPath = BASE_PATH . '/storage/.schema_stamp';
        if (is_file($stampPath)) {
            @unlink($stampPath);
        }
    }
}
