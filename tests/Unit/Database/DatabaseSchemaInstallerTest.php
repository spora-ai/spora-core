<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;
use Spora\Core\DatabaseSchemaInstaller;
use Spora\Plugins\PluginLoader;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Boot a fresh in-memory SQLite connection and return the installer under test.
 * No stamp path — in-memory DBs have no persistent filesystem.
 */
function bootInstaller(?PluginLoader $pluginLoader = null, ?string $stampPath = null): DatabaseSchemaInstaller
{
    Database::resetBootState();
    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:'], $pluginLoader);
    $db->bootDatabaseConnectionOnly();

    return new DatabaseSchemaInstaller($pluginLoader, $stampPath);
}

function bootLoaderFromFixture(string $dir): PluginLoader
{
    $loader = new PluginLoader($dir);
    $loader->boot();
    return $loader;
}

// ---------------------------------------------------------------------------
// Infrastructure tables
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Core schema
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Idempotency (without stamp — relies on schema_versions DB check)
// ---------------------------------------------------------------------------

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
    $installer->install();

    expect(true)->toBeTrue();
})->afterEach(fn() => Database::resetBootState());

// ---------------------------------------------------------------------------
// Filesystem stamp cache
// ---------------------------------------------------------------------------

test('install() writes stamp file after successful run', function (): void {
    $stamp     = sys_get_temp_dir() . '/.spora_test_stamp_' . uniqid();
    $installer = bootInstaller(stampPath: $stamp);
    $installer->install();

    expect(is_file($stamp))->toBeTrue();
})->afterEach(function (): void {
    Database::resetBootState();
    foreach (glob(sys_get_temp_dir() . '/.spora_test_stamp_*') ?: [] as $f) {
        @unlink($f);
    }
});

test('install() is a zero-query no-op when stamp matches current hash', function (): void {
    $stamp     = sys_get_temp_dir() . '/.spora_test_stamp_' . uniqid();
    $installer = bootInstaller(stampPath: $stamp);
    $installer->install(); // first run — writes stamp + creates tables

    // Simulate a second boot on the same DB state.
    // Drop all tables to prove install() never touches the DB on the second call.
    Capsule::schema()->drop('migrations');
    Capsule::schema()->drop('schema_versions');

    // Second install() must return immediately (stamp matches) — no DB queries,
    // therefore no "table does not exist" error.
    $installer->install();

    expect(true)->toBeTrue();
})->afterEach(function (): void {
    Database::resetBootState();
    foreach (glob(sys_get_temp_dir() . '/.spora_test_stamp_*') ?: [] as $f) {
        @unlink($f);
    }
});

test('install() runs migrations when stamp file is missing', function (): void {
    $stamp     = sys_get_temp_dir() . '/.spora_test_stamp_' . uniqid();
    $installer = bootInstaller(stampPath: $stamp);

    // No stamp file yet — must run migrations.
    $installer->install();

    expect(Capsule::schema()->hasTable('users'))->toBeTrue();
})->afterEach(function (): void {
    Database::resetBootState();
    foreach (glob(sys_get_temp_dir() . '/.spora_test_stamp_*') ?: [] as $f) {
        @unlink($f);
    }
});

test('install() re-runs migrations when stamp file contains a stale hash', function (): void {
    $stamp = sys_get_temp_dir() . '/.spora_test_stamp_' . uniqid();
    file_put_contents($stamp, 'core_v0'); // stale hash

    $installer = bootInstaller(stampPath: $stamp);
    $installer->install();

    // Stamp must now contain the current hash, not the stale one.
    expect(file_get_contents($stamp))->not->toBe('core_v0');
    expect(Capsule::schema()->hasTable('users'))->toBeTrue();
})->afterEach(function (): void {
    Database::resetBootState();
    foreach (glob(sys_get_temp_dir() . '/.spora_test_stamp_*') ?: [] as $f) {
        @unlink($f);
    }
});

// ---------------------------------------------------------------------------
// Plugin migrations
// ---------------------------------------------------------------------------

test('plugin migrations are run when the plugin declares schemaVersion > 0', function (): void {
    $loader    = bootLoaderFromFixture(BASE_PATH . '/tests/Fixtures/plugins_with_migrations');
    $installer = bootInstaller($loader);
    $installer->install();

    expect(Capsule::schema()->hasTable('plugin_widgets'))->toBeTrue();
})->afterEach(fn() => Database::resetBootState());

test('plugin component row uses slug as key in schema_versions', function (): void {
    $loader    = bootLoaderFromFixture(BASE_PATH . '/tests/Fixtures/plugins_with_migrations');
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
    $loader    = bootLoaderFromFixture(BASE_PATH . '/tests/Fixtures/plugins_with_migrations');
    $installer = bootInstaller($loader);
    $installer->install();
    $installer->install();

    expect(Capsule::schema()->hasTable('plugin_widgets'))->toBeTrue();

    $count = Capsule::table('schema_versions')->where('component', 'migrating-plugin')->count();
    expect($count)->toBe(1);
})->afterEach(fn() => Database::resetBootState());

// ---------------------------------------------------------------------------
// Migration filename prefix enforcement
// ---------------------------------------------------------------------------

test('install() throws RuntimeException when a plugin migration file lacks the slug prefix', function (): void {
    $loader    = bootLoaderFromFixture(BASE_PATH . '/tests/Fixtures/plugins_bad_migrations');
    $installer = bootInstaller($loader);

    expect(fn() => $installer->install())->toThrow(RuntimeException::class, 'bad-prefix-plugin_');
})->afterEach(fn() => Database::resetBootState());

// ---------------------------------------------------------------------------
// Database::boot() integration
// ---------------------------------------------------------------------------

test('Database::boot() installs the full schema end-to-end', function (): void {
    Database::resetBootState();

    $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
    $db->boot();

    expect(Capsule::schema()->hasTable('users'))->toBeTrue();
    expect(Capsule::schema()->hasTable('schema_versions'))->toBeTrue();
})->afterEach(fn() => Database::resetBootState());
