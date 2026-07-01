<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Plugins\PluginLoader;

// Shared stamp path fragments (avoids duplicated string literals across tests).
const STAMP_BASENAME = '.spora_test_stamp_';
const PLUGINS_FIXTURE_WITH_MIGRATIONS = '/tests/Fixtures/plugins_with_migrations';

// Helpers

/**
 * Boot a fresh in-memory SQLite connection and return the installer under test.
 * No stamp path — in-memory DBs have no persistent filesystem.
 */
function bootInstaller(
    ?PluginLoader $pluginLoader = null,
    ?string $stampPath = null,
    ?Spora\Extensions\AppLoader $appLoader = null,
): DatabaseSchemaInstaller {
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:'], $pluginLoader);
    $db->bootDatabaseConnectionOnly();

    return new DatabaseSchemaInstaller($pluginLoader, $stampPath, null, null, $appLoader);
}

function bootLoaderFromFixture(string $dir): PluginLoader
{
    $loader = new PluginLoader([$dir]);
    $loader->boot();
    return $loader;
}

// Infrastructure tables

test('install() creates schema_versions table', function (): void {
    $installer = bootInstaller();
    $installer->install();

    expect(Capsule::schema()->hasTable('schema_versions'))->toBeTrue();
})->afterEach(fn() => Database::resetBootState());

test('install() creates the Laravel migrations tracking table', function (): void {
    $installer = bootInstaller();
    $installer->install();

    expect(Capsule::schema()->hasTable('migrations'))->toBeTrue();
})->afterEach(fn() => Database::resetBootState());

// Core schema

test('install() creates all core application tables', function (): void {
    $installer = bootInstaller();
    $installer->install();

    $schema = Capsule::schema();
    $tables = ['users', 'agents', 'tool_configurations', 'agent_tools', 'agent_tool_overrides',
        'tasks', 'tool_calls', 'task_history'];

    foreach ($tables as $table) {
        expect($schema->hasTable($table))->toBeTrue("Expected table '{$table}' to exist");
    }
})->afterEach(fn() => Database::resetBootState());

test('install() creates all delight-im/auth auxiliary tables', function (): void {
    $installer = bootInstaller();
    $installer->install();

    $schema = Capsule::schema();
    $tables = ['users_2fa', 'users_audit_log', 'users_confirmations',
        'users_otps', 'users_remembered', 'users_resets', 'users_throttling'];

    foreach ($tables as $table) {
        expect($schema->hasTable($table))->toBeTrue("Expected auth table '{$table}' to exist");
    }
})->afterEach(fn() => Database::resetBootState());

test('core component row is written to schema_versions after install', function (): void {
    $installer = bootInstaller();
    $installer->install();

    // Version should match the highest-numbered core migration file.
    $files = glob(BASE_PATH . '/database/migrations/[0-9]*.php') ?: [];
    $max = 0;
    foreach ($files as $file) {
        if (preg_match('/^(\d+)/', basename($file, '.php'), $m)) {
            $max = max($max, (int) $m[1]);
        }
    }

    $row = Capsule::table('schema_versions')->where('component', 'core')->first();
    expect($row)->not->toBeNull();
    expect((int) $row->version)->toBe($max);
})->afterEach(fn() => Database::resetBootState());

// Idempotency (without stamp — relies on schema_versions DB check)

test('install() is idempotent — running twice does not throw or duplicate tables', function (): void {
    $installer = bootInstaller();
    $installer->install();
    $installer->install();

    expect(Capsule::schema()->hasTable('users'))->toBeTrue();

    $count = Capsule::table('schema_versions')->where('component', 'core')->count();
    expect($count)->toBe(1);
})->afterEach(fn() => Database::resetBootState());

test('install() skips a component whose stored version is already at code version', function (): void {
    $installer = bootInstaller();
    $installer->install();

    // Artificially set stored version way above code version so Migrator would
    // re-run migrations if consulted — stamp bypass should prevent that.
    Capsule::table('schema_versions')
        ->where('component', 'core')
        ->update(['version' => 9999]);

    // Would throw duplicate-table error if migrations re-ran.
    $installer->install(); // Should not throw
    expect(true)->toBeTrue();
})->afterEach(fn() => Database::resetBootState());

// Filesystem stamp cache

test('install() writes stamp file after successful run', function (): void {
    $stamp     = sys_get_temp_dir() . '/' . STAMP_BASENAME . uniqid();
    $installer = bootInstaller(stampPath: $stamp);
    $installer->install();

    expect(is_file($stamp))->toBeTrue();
})->afterEach(function (): void {
    Database::resetBootState();
    foreach (glob(sys_get_temp_dir() . '/' . STAMP_BASENAME . '*') ?: [] as $f) {
        @unlink($f);
    }
});

test('install() is a zero-query no-op when stamp matches current hash', function (): void {
    $stamp     = sys_get_temp_dir() . '/' . STAMP_BASENAME . uniqid();
    $installer = bootInstaller(stampPath: $stamp);
    $installer->install(); // first run — writes stamp + creates tables

    // Simulate a second boot on the same DB state.
    // Drop all tables to prove install() never touches the DB on the second call.
    Capsule::schema()->drop('migrations');
    Capsule::schema()->drop('schema_versions');

    // Second install() must return immediately (stamp matches) — no DB queries,
    // therefore no "table does not exist" error.
    $installer->install(); // Should not throw
    expect(true)->toBeTrue();
})->afterEach(function (): void {
    Database::resetBootState();
    foreach (glob(sys_get_temp_dir() . '/' . STAMP_BASENAME . '*') ?: [] as $f) {
        @unlink($f);
    }
});

test('install() runs migrations when stamp file is missing', function (): void {
    $stamp     = sys_get_temp_dir() . '/' . STAMP_BASENAME . uniqid();
    $installer = bootInstaller(stampPath: $stamp);

    // No stamp file yet — must run migrations.
    $installer->install();

    expect(Capsule::schema()->hasTable('users'))->toBeTrue();
})->afterEach(function (): void {
    Database::resetBootState();
    foreach (glob(sys_get_temp_dir() . '/' . STAMP_BASENAME . '*') ?: [] as $f) {
        @unlink($f);
    }
});

test('install() re-runs migrations when stamp file contains a stale hash', function (): void {
    $stamp = sys_get_temp_dir() . '/' . STAMP_BASENAME . uniqid();
    file_put_contents($stamp, 'core_v0'); // stale hash

    $installer = bootInstaller(stampPath: $stamp);
    $installer->install();

    // Stamp must now contain the current hash, not the stale one.
    expect(file_get_contents($stamp))->not->toBe('core_v0');
    expect(Capsule::schema()->hasTable('users'))->toBeTrue();
})->afterEach(function (): void {
    Database::resetBootState();
    foreach (glob(sys_get_temp_dir() . '/' . STAMP_BASENAME . '*') ?: [] as $f) {
        @unlink($f);
    }
});

// Plugin migrations

test('plugin migrations are run when the plugin declares schemaVersion > 0', function (): void {
    $loader    = bootLoaderFromFixture(BASE_PATH . PLUGINS_FIXTURE_WITH_MIGRATIONS);
    $installer = bootInstaller($loader);
    $installer->install();

    expect(Capsule::schema()->hasTable('plugin_widgets'))->toBeTrue();
})->afterEach(fn() => Database::resetBootState());

test('plugin component row uses slug as key in schema_versions', function (): void {
    $loader    = bootLoaderFromFixture(BASE_PATH . PLUGINS_FIXTURE_WITH_MIGRATIONS);
    $installer = bootInstaller($loader);
    $installer->install();

    $row = Capsule::table('schema_versions')->where('component', 'migrating-plugin')->first();
    expect($row)->not->toBeNull();
    expect((int) $row->version)->toBe(1);
})->afterEach(fn() => Database::resetBootState());

test('plugin with schemaVersion 0 is skipped — no row in schema_versions', function (): void {
    $loader    = bootLoaderFromFixture(BASE_PATH . '/tests/Fixtures/plugins_with_manifest');
    $installer = bootInstaller($loader);
    $installer->install();

    // No row for the manifest-plugin slug because schemaVersion() returns 0.
    $row = Capsule::table('schema_versions')->where('component', 'manifest-plugin')->first();
    expect($row)->toBeNull();
})->afterEach(fn() => Database::resetBootState());

test('plugin migrations are idempotent — running install() twice does not duplicate plugin tables', function (): void {
    $loader    = bootLoaderFromFixture(BASE_PATH . PLUGINS_FIXTURE_WITH_MIGRATIONS);
    $installer = bootInstaller($loader);
    $installer->install();
    $installer->install();

    expect(Capsule::schema()->hasTable('plugin_widgets'))->toBeTrue();

    $count = Capsule::table('schema_versions')->where('component', 'migrating-plugin')->count();
    expect($count)->toBe(1);
})->afterEach(fn() => Database::resetBootState());

// Migration filename prefix enforcement

test('install() throws RuntimeException when a plugin migration file lacks the slug prefix', function (): void {
    $loader    = bootLoaderFromFixture(BASE_PATH . '/tests/Fixtures/plugins_bad_migrations');
    $installer = bootInstaller($loader);

    expect(fn() => $installer->install())->toThrow(RuntimeException::class, 'bad-prefix-plugin_');
})->afterEach(fn() => Database::resetBootState());

// Database::boot() integration

test('Database::boot() installs the full schema end-to-end', function (): void {
    Database::resetBootState();

    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();

    expect(Capsule::schema()->hasTable('users'))->toBeTrue();
    expect(Capsule::schema()->hasTable('schema_versions'))->toBeTrue();
})->afterEach(fn() => Database::resetBootState());

// App migrations via AppLoader

test('installer accepts an AppLoader and installs app migrations under the "app" component', function (): void {
    Database::resetBootState();

    $migrationsDir = sys_get_temp_dir() . '/spora-app-migrations-' . bin2hex(random_bytes(4));
    mkdir($migrationsDir, 0755, true);
    file_put_contents($migrationsDir . '/app_001_create_app_notes.php', <<<'PHP'
<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        Capsule::schema()->create('app_notes', static function (Blueprint $t): void {
            $t->string('note')->primary();
        });
    }
    public function down(): void { Capsule::schema()->dropIfExists('app_notes'); }
};
PHP);

    $app = new class ($migrationsDir) extends Spora\Extensions\AbstractExtension {
        public function __construct(private readonly string $migrationsPath) {}
        public function getName(): string
        {
            return 'TestApp';
        }
        public function schemaVersion(): int
        {
            return 1;
        }
        public function migrationsPath(): string
        {
            return $this->migrationsPath;
        }
    };

    // Hand-build an AppLoader with the App pre-set via reflection — same
    // seam AppLoaderTest uses for the autoload() branch.
    $loader = new Spora\Extensions\AppLoader();
    (new ReflectionProperty($loader, 'app'))->setValue($loader, $app);

    $installer = bootInstaller(appLoader: $loader);
    $installer->install();

    expect(Capsule::schema()->hasTable('app_notes'))->toBeTrue();
    $row = Capsule::table('schema_versions')->where('component', 'app')->first();
    expect($row)->not->toBeNull();
    expect((int) $row->version)->toBe(1);

    Database::resetBootState();
    @unlink($migrationsDir . '/app_001_create_app_notes.php');
    @rmdir($migrationsDir);
});

test('installer skips App migrations when App declares schemaVersion 0', function (): void {
    Database::resetBootState();

    $app = new class extends Spora\Extensions\AbstractExtension {
        public function getName(): string
        {
            return 'NoSchemaApp';
        }
        public function schemaVersion(): int
        {
            return 0;
        }
        public function migrationsPath(): string
        {
            return '/somewhere/never-read';
        }
    };

    $loader = new Spora\Extensions\AppLoader();
    (new ReflectionProperty($loader, 'app'))->setValue($loader, $app);

    $installer = bootInstaller(appLoader: $loader);
    $installer->install();

    $row = Capsule::table('schema_versions')->where('component', 'app')->first();
    expect($row)->toBeNull();

    Database::resetBootState();
});

test('installer skips App migrations when migrationsPath() returns null', function (): void {
    Database::resetBootState();

    // schemaVersion > 0 but no migrationsPath → "not ready yet".
    $app = new class extends Spora\Extensions\AbstractExtension {
        public function getName(): string
        {
            return 'MigrationsTbdApp';
        }
        public function schemaVersion(): int
        {
            return 5;
        }
        public function migrationsPath(): ?string
        {
            return null;
        }
    };

    $loader = new Spora\Extensions\AppLoader();
    (new ReflectionProperty($loader, 'app'))->setValue($loader, $app);

    $installer = bootInstaller(appLoader: $loader);
    $installer->install();

    $row = Capsule::table('schema_versions')->where('component', 'app')->first();
    expect($row)->toBeNull();

    Database::resetBootState();
});

test('installer is unchanged when no AppLoader is provided (backward compatibility)', function (): void {
    Database::resetBootState();

    $installer = bootInstaller();
    $installer->install();

    $row = Capsule::table('schema_versions')->where('component', 'app')->first();
    expect($row)->toBeNull();

    Database::resetBootState();
});

// Core migrations path resolution
//
// The constructor override ($coreMigrationsPath) exercises the same code path as
// the implicit resolver while letting us inject temp dirs without monkey-patching
// the BASE_PATH constant.

test('constructor override is used verbatim when provided', function (): void {
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    // Point at an empty dir: version 0 → no migrations to run, no upsert past 0.
    $tmp = sys_get_temp_dir() . '/spora-mig-override-' . uniqid();
    mkdir($tmp, 0755, true);

    try {
        $installer = new DatabaseSchemaInstaller(null, null, $tmp);
        $installer->install();

        $row = Capsule::table('schema_versions')->where('component', 'core')->first();
        expect($row)->toBeNull();
    } finally {
        rmdir($tmp);
        Database::resetBootState();
    }
})->afterEach(fn() => Database::resetBootState());

test('resolver prefers the explicit override over the implicit fallback chain', function (): void {
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    // Override short-circuits resolveCoreMigrationsPath(): a non-existent path
    // must NOT trigger the resolver's SchemaInstallFailedException.
    $fakeDir = sys_get_temp_dir() . '/spora-mig-does-not-exist-' . uniqid();
    expect(is_dir($fakeDir))->toBeFalse();

    $installer = new DatabaseSchemaInstaller(null, null, $fakeDir);
    expect($installer)->toBeInstanceOf(DatabaseSchemaInstaller::class);

    Database::resetBootState();
})->afterEach(fn() => Database::resetBootState());

test('implicit resolver finds migrations when BASE_PATH/database/migrations exists', function (): void {
    // Pass null to opt into the implicit resolver; relies on the real
    // spora-core checkout (BASE_PATH/database/migrations is populated).
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->bootDatabaseConnectionOnly();

    $installer = new DatabaseSchemaInstaller(null, null, null);
    $installer->install();

    expect(Capsule::schema()->hasTable('users'))->toBeTrue();

    Database::resetBootState();
})->afterEach(fn() => Database::resetBootState());

test('resolveCoreMigrationsPath() throws a clear exception when no migrations exist', function (): void {
    // We hide the project-local dir temporarily; BASE_PATH can't be changed (it's
    // a constant), so we rely on the framework vendor path also being absent in
    // this checkout (vendor/spora-ai/spora-core/ isn't populated for spora-core
    // itself). The dir is always restored.
    Database::resetBootState();

    $local = BASE_PATH . '/database/migrations';
    $hide  = $local . '.hidden-for-test';

    if (!is_dir($local)) {
        expect(true)->toBeTrue();
        return;
    }

    expect(rename($local, $hide))->toBeTrue();

    try {
        $framework = BASE_PATH . '/vendor/spora-ai/spora-core/database/migrations';
        if (is_dir($framework)) {
            // Framework path is present — the resolver would succeed. Skip.
            expect(true)->toBeTrue();
            return;
        }

        expect(fn() => new DatabaseSchemaInstaller(null, null, null))
            ->toThrow(Spora\Core\Exceptions\SchemaInstallFailedException::class, 'No core migrations found');
    } finally {
        // Re-rename could fail if an earlier rename already failed; ignore to
        // keep teardown best-effort.
        if (is_dir($hide)) {
            rename($hide, $local);
        }
        Database::resetBootState();
    }
})->afterEach(fn() => Database::resetBootState());
