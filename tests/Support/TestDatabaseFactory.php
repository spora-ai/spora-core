<?php

/*
 * Centralises the per-worker / per-test database lifecycle for the Pest suite.
 *
 * Without this factory each test file inlines its own
 * `Database::resetBootState() + new Database([sqlite :memory:])->boot()`
 * pair. That works because `:memory:` gives every test a fresh schema at
 * near-zero cost. It stops working the moment we want to run the same
 * suite against MySQL or MariaDB — those have no equivalent of an in-memory
 * database, so we have to install the schema on something and reuse it.
 *
 * The factory reads `SPORA_TEST_DB_DRIVER`:
 *
 *   - sqlite  (default) — byte-identical to the pre-factory behaviour:
 *                         each `boot()` call rebuilds a fresh `:memory:`
 *                         database. Schema install runs per test; that's
 *                         the cost we pay for full per-test isolation.
 *   - mysql / mariadb   — one fresh `spora_test_w<PID>_<RAND>` database is
 *                         `CREATE`d on the configured server at the start
 *                         of the worker process, the schema installer runs
 *                         exactly once against it, and every subsequent
 *                         `boot()` in the worker reconnects without
 *                         re-installing. The DB is `DROP`ped on shutdown
 *                         (best-effort, also wired to SIGTERM/SIGINT so
 *                         Pest's parent kills don't leak it).
 *
 * Migration tests (tests/Feature/Database/*) issue DDL mid-test and
 * therefore cannot share a DB across tests — `freshDatabase()` gives those
 * tests a clean DB per `beforeEach` at the cost of one extra schema install
 * per test (~1.5s on gitHub-hosted tmpfs MySQL).
 *
 * Loaded once per worker from `composer.json#autoload-dev.files` so every
 * test file (and every Pest parallel worker) sees the same factory class.
 */

declare(strict_types=1);

use Spora\Core\Database;

final class TestDatabaseFactory
{
    /**
     * Driver name as resolved from `SPORA_TEST_DB_DRIVER`. Normalised to
     * lowercase so callers don't need to worry about the env-var casing.
     */
    private static ?string $driver = null;

    /**
     * Per-worker database name on the configured MySQL/MariaDB server.
     * `null` for SQLite (no remote DB) and before the first MySQL/MariaDB
     * boot in a worker.
     */
    private static ?string $workerDbName = null;

    /**
     * `true` once the schema installer has run against the worker DB.
     * Stays `false` until the first `boot()` call so the install path is
     * exercised exactly once per worker.
     */
    private static bool $workerSchemaInstalled = false;

    /**
     * `true` after a partial-schema call (`bootConnectionOnly` /
     * `freshConnectionOnly`) leaves the worker DB with manual DDL that
     * is incompatible with the framework installer. The next `boot()`
     * will drop+recreate the DB instead of installing on top of the
     * polluted state — see the matching branch in `boot()` for the
     * consumer side.
     */
    private static bool $workerDbDirty = false;

    /**
     * Effective driver (`sqlite` | `mysql` | `mariadb`). Cached after the
     * first read so the factory doesn't re-touch `getenv()` on every call.
     */
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

    /**
     * Standard per-test boot path. Honours the env-driven driver.
     *
     * For SQLite: rebuilds `:memory:` + installs the schema (existing
     * behaviour). For MySQL/MariaDB: reconnects to the worker's DB on
     * the first call only; subsequent calls short-circuit the schema
     * install via `Database::setSchemaInstallSkipped(true)`.
     */
    public static function boot(): void
    {
        $driver = self::driver();

        if ($driver === 'sqlite') {
            Database::resetBootState();
            Database::setSchemaInstallSkipped(false);
            (new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
            return;
        }

        // MySQL / MariaDB path.
        if (self::$workerDbName === null) {
            self::createWorkerDatabase();
        }

        // `bootConnectionOnly` / `freshConnectionOnly` leave the worker DB
        // polluted with manual DDL that doesn't match the installer's table
        // shapes (e.g. `mail_templates` with non-migration columns). On the
        // next `boot()` we drop the DB and reinstall so the framework
        // installer doesn't crash on `CREATE TABLE … already exists` for
        // every subsequent test in the worker.
        if (self::$workerDbDirty) {
            self::dropWorkerDatabase();
            self::createWorkerDatabase();
            self::invalidateLocalStamp();
            self::$workerDbDirty = false;
        }

        // `Database::resetBootState()` now clears `setSchemaInstallSkipped()`
        // as well (the previous sticky-flag behaviour let test files that
        // bypass the factory — `new Database([sqlite :memory:])->boot()` —
        // inherit a "schema already installed" optimisation against a
        // *different* (empty) DB and crash on the first insert). Re-establish
        // the skip flag here when this worker has already installed, so the
        // hot-path optimisation still applies on the per-worker DB.
        Database::resetBootState();
        if (self::$workerSchemaInstalled) {
            Database::setSchemaInstallSkipped(true);
        }

        (new Database(self::buildConfigForDriver()))->boot();

        self::$workerSchemaInstalled = true;
    }

    /**
     * Per-test fresh-DB boot path. For SQLite it is identical to `boot()`.
     * For MySQL/MariaDB it drops and recreates the worker DB so the next
     * test gets an empty schema.
     *
     * Slower than `boot()` (extra `DROP DATABASE` + re-install) — only use
     * it for tests that issue DDL mid-test and need a clean slate.
     */
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
        // The local stamp file from the previous worker DB is still in
        // place (it lives at <BASE_PATH>/storage/.schema_stamp, not on the
        // remote DB server). Its hash would still match the unchanged
        // migration files, so the installer's O(1) hot path would skip the
        // migration run entirely and leave the freshly-created (empty) DB
        // without a schema. Invalidate the stamp so the install runs.
        self::invalidateLocalStamp();

        Database::resetBootState();
        Database::setSchemaInstallSkipped(false);
        (new Database(self::buildConfigForDriver()))->boot();
        Database::setSchemaInstallSkipped(true);
        self::$workerSchemaInstalled = true;
    }

    /**
     * Connect-only boot for tests that build their own partial schema.
     * Mirrors `Database::bootDatabaseConnectionOnly()` but honours
     * `SPORA_TEST_DB_DRIVER`.
     *
     * On SQLite it returns a fresh `:memory:` connection. On MySQL/MariaDB
     * it returns a connection to the worker DB without running the
     * schema installer — the test is responsible for the schema it sees.
     */
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

        // Whatever the test creates manually after this point is, by
        // definition, not the full framework schema. Mark the worker DB
        // as "dirty" so the next `boot()` drops it before re-installing
        // (otherwise the installer would crash on CREATE TABLE for a
        // table the test just created with a different shape).
        self::$workerDbDirty = true;

        Database::resetBootState();
        Database::setSchemaInstallSkipped(false);
        (new Database(self::buildConfigForDriver()))->bootDatabaseConnectionOnly();
    }

    /**
     * Drop the worker DB, then connect-only boot. For tests that build a
     * partial schema and need an empty DB to do it on.
     */
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
        // Same rationale as `freshDatabase()` above — the local stamp file
        // would otherwise short-circuit the next `boot()` against this
        // empty DB. The test calling this method will manually `CREATE TABLE`
        // its own partial schema; the next factory `boot()` is responsible
        // for re-installing the full schema.
        self::invalidateLocalStamp();

        // Whatever the test creates manually after this point is, by
        // definition, not the full framework schema. Mark the worker DB
        // as "dirty" so the next `boot()` drops it before re-installing.
        self::$workerDbDirty = true;

        Database::resetBootState();
        Database::setSchemaInstallSkipped(false);
        (new Database(self::buildConfigForDriver()))->bootDatabaseConnectionOnly();
        // Don't set the skip flag here — the DB is empty; the next
        // `boot()` (or this test's manual DDL) is responsible for whatever
        // schema it wants to see. `workerSchemaInstalled` stays false so
        // the next factory boot knows it still owes an install.
        self::$workerSchemaInstalled = false;
    }

    /**
     * Build the config array passed to `new Database(...)` for the resolved
     * MySQL/MariaDB driver. Reads connection details from the
     * `SPORA_TEST_DB_*` env vars, with defaults that match the GitHub
     * Actions `mysql:8.0` and `mariadb:11` service containers.
     *
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

    /**
     * Connect to the MySQL/MariaDB server without selecting a database and
     * `CREATE DATABASE` the worker's unique DB. Registers a shutdown hook
     * so the DB is `DROP`ped even when Pest's parent kills a stuck worker.
     */
    private static function createWorkerDatabase(): void
    {
        $host = (string) (getenv('SPORA_TEST_DB_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('SPORA_TEST_DB_PORT') ?: 3306);
        $user = (string) (getenv('SPORA_TEST_DB_USER') ?: 'root');
        $pass = (string) (getenv('SPORA_TEST_DB_PASSWORD') ?: '');

        // Per-DB name encodes the worker PID + 4 bytes of randomness. The
        // PID makes the name stable for the lifetime of the worker (so a
        // reentrant `createWorkerDatabase()` after a drop reuses the same
        // name); the random suffix keeps two workers started at the same
        // nanosecond from clobbering each other.
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
                // Avoid the default 30s connect timeout blowing up the suite
                // when the runner hasn't brought the service up yet.
                PDO::ATTR_TIMEOUT => 10,
            ],
        );

        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $dbName,
        ));

        self::$workerDbName = $dbName;

        register_shutdown_function([self::class, 'dropWorkerDatabase']);

        // Pest's parent kills stragglers with SIGTERM. The shutdown function
        // covers natural exit; the signal handler covers the kill path so
        // we don't leak DBs on the shared MySQL/MariaDB server.
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, [self::class, 'dropWorkerDatabase']);
            pcntl_signal(SIGINT, [self::class, 'dropWorkerDatabase']);
        }
    }

    /**
     * Flag the worker DB as dirty so the next `boot()` drops and
     * reinstalls the schema. Tests that issue manual DDL (drop tables,
     * drop columns) call this from their `afterEach` so the next test in
     * the same worker — which inherits the factory's hot-path schema
     * install skip — doesn't see a worker DB missing the tables it just
     * dropped. SQLite's per-test `:memory:` rebuild makes the flag a
     * no-op on that driver.
     */
    public static function markWorkerDbDirty(): void
    {
        if (self::driver() === 'sqlite') {
            return;
        }
        self::$workerDbDirty = true;
    }

    /**
     * Drop the worker DB. Idempotent and best-effort — failures are
     * swallowed so a flaky DROP at the end of the run doesn't mask a
     * earlier real failure.
     */
    public static function dropWorkerDatabase(): void
    {
        if (self::$workerDbName === null) {
            return;
        }

        $name = self::$workerDbName;
        self::$workerDbName = null;
        self::$workerSchemaInstalled = false;
        // The DB is gone — no point carrying the "dirty" flag through to
        // the next (freshly-created) DB.
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
            // Best effort — running at shutdown means we have nowhere to
            // surface the error and the parent process is already moving on.
        }
    }

    /**
     * Delete the local `storage/.schema_stamp` so the installer's O(1)
     * hot path doesn't skip a migration run we actually need. Called
     * after `freshDatabase()`/`freshConnectionOnly()` drop the worker DB
     * — the remote DB is empty, but the local stamp file (which lives
     * on the runner's filesystem, not the DB server) still claims the
     * schema is current and would otherwise short-circuit the install
     * for the next `boot()` against the freshly-created DB.
     *
     * Best-effort — missing stamp file is fine (the install runs anyway);
     * an unwritable stamp file would just cost one extra migration run.
     */
    private static function invalidateLocalStamp(): void
    {
        $stampPath = BASE_PATH . '/storage/.schema_stamp';
        if (is_file($stampPath)) {
            @unlink($stampPath);
        }
    }
}
