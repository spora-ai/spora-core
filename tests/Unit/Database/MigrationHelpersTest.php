<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Spora\Core\Database;
use Spora\Core\Database\MigrationHelpers;

uses(MigrationHelpers::class);

beforeEach(function (): void {
    Database::resetBootState();
    $db = new Database([
        'db_driver' => 'sqlite',
        'db_path'   => ':memory:',
    ]);
    $db->boot();
});

afterEach(function (): void {
    Mockery::close();
    Database::resetBootState();
});

/**
 * Replace the default connection in Capsule's DatabaseManager with a
 * Mockery mock that reports the given driver name. Used to drive the
 * information_schema branches in MigrationHelpers without a real
 * MySQL/MariaDB connection.
 *
 * The mock also stubs the transaction hooks so the global Pest
 * afterEach (which calls transactionLevel()/rollBack()) can run
 * against the mock without throwing.
 */
function swapDefaultConnectionWith(string $driverName, array $methodExpectations): void
{
    $capsule = Database::getCapsule();
    $dbManager = $capsule->getDatabaseManager();

    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('getDriverName')->andReturn($driverName);
    $conn->shouldReceive('transactionLevel')->andReturn(0);
    $conn->shouldReceive('rollBack')->andReturn(true);
    foreach ($methodExpectations as $method => $return) {
        $conn->shouldReceive($method)->andReturn($return);
    }

    $ref = new ReflectionProperty($dbManager, 'connections');
    $connections = $ref->getValue($dbManager);
    $connections['default'] = $conn;
    $ref->setValue($dbManager, $connections);
}

// SQLite paths — exercised against a real in-memory SQLite via PRAGMA.

it('foreignKeyExists returns false on SQLite when no FK exists', function (): void {
    Capsule::schema()->create('migration_helpers_test', static function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('user_id')->nullable();
    });

    expect($this->foreignKeyExists('migration_helpers_test', 'fk_migration_helpers_test_nonexistent'))->toBeFalse();
});

it('indexExists returns true on SQLite when the index exists', function (): void {
    Capsule::schema()->create('migration_helpers_test', static function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->index('user_id');
    });

    expect($this->indexExists('migration_helpers_test', 'migration_helpers_test_user_id_index'))->toBeTrue();
});

it('indexExists returns false on SQLite when the index is missing', function (): void {
    Capsule::schema()->create('migration_helpers_test', static function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('user_id')->nullable();
    });

    expect($this->indexExists('migration_helpers_test', 'migration_helpers_test_user_id_index'))->toBeFalse();
});

it('findForeignKeyOn returns null on SQLite', function (): void {
    Capsule::schema()->create('migration_helpers_test', static function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('user_id')->nullable();
    });

    expect($this->findForeignKeyOn('migration_helpers_test', 'user_id'))->toBeNull();
});

it('findIndexOn returns the leftmost-column index name on SQLite', function (): void {
    Capsule::schema()->create('migration_helpers_test', static function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->index('user_id');
    });

    expect($this->findIndexOn('migration_helpers_test', 'user_id'))->toBe('migration_helpers_test_user_id_index');
});

it('findIndexOn returns null on SQLite when no user-created index has the column', function (): void {
    Capsule::schema()->create('migration_helpers_test', static function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('user_id')->nullable();
    });

    expect($this->findIndexOn('migration_helpers_test', 'user_id'))->toBeNull();
});

it('foreignKeyExists returns true on SQLite when a matching FK exists', function (): void {
    // Raw SQL because Laravel's SQLite grammar emits FKs without CONSTRAINT
    // names, but the trait's SQLite path derives the column by stripping the
    // "fk_<table>_" prefix from the requested name. The PRAGMA loop then
    // matches by `from` column.
    Capsule::statement(<<<SQL
        CREATE TABLE migration_helpers_test_parent (
            id INTEGER PRIMARY KEY
        )
    SQL);
    Capsule::statement(<<<SQL
        CREATE TABLE migration_helpers_test (
            id INTEGER PRIMARY KEY,
            parent_id INTEGER,
            FOREIGN KEY (parent_id) REFERENCES migration_helpers_test_parent(id)
        )
    SQL);

    expect($this->foreignKeyExists('migration_helpers_test', 'fk_migration_helpers_test_parent_id'))->toBeTrue();
});

it('hasForeignKeyOnColumn returns true on SQLite when a FK is attached to the column', function (): void {
    // Matches by `from` column regardless of the constraint name —
    // Laravel's SQLite grammar emits FKs with anonymous numeric ids,
    // so name-based probes always miss on this driver.
    Capsule::statement(<<<SQL
        CREATE TABLE migration_helpers_test_parent (
            id INTEGER PRIMARY KEY
        )
    SQL);
    Capsule::statement(<<<SQL
        CREATE TABLE migration_helpers_test (
            id INTEGER PRIMARY KEY,
            parent_id INTEGER,
            FOREIGN KEY (parent_id) REFERENCES migration_helpers_test_parent(id)
        )
    SQL);

    expect($this->hasForeignKeyOnColumn('migration_helpers_test', 'parent_id'))->toBeTrue();
});

it('hasForeignKeyOnColumn returns false on SQLite when no FK is attached to the column', function (): void {
    Capsule::schema()->create('migration_helpers_test', static function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('parent_id')->nullable();
    });

    expect($this->hasForeignKeyOnColumn('migration_helpers_test', 'parent_id'))->toBeFalse();
});

it('findIndexOn skips SQLite auto-indexes (origin u/pk/f) and returns null when only auto indexes have the column', function (): void {
    // Anonymous UNIQUE / PRIMARY KEY constraints create sqlite_autoindex_*
    // entries with origin 'u' / 'pk' / 'f' that the trait's SQLite branch
    // must skip and continue looking for user-created ('c') indexes.
    // Laravel's schema builder names these constraints explicitly so they
    // surface as origin 'c'; use raw SQL to keep the constraint anonymous.
    Capsule::statement('CREATE TABLE migration_helpers_test (id INTEGER PRIMARY KEY, email TEXT UNIQUE)');

    expect($this->findIndexOn('migration_helpers_test', 'email'))->toBeNull();
});

// MySQL / MariaDB paths — exercised by swapping the default connection
// for a Mockery mock that reports the driver name and returns canned
// selectOne() values. The information_schema SQL never executes; only
// the branch logic is covered.

it('foreignKeyExists returns true on MySQL when information_schema reports the FK', function (): void {
    swapDefaultConnectionWith('mysql', [
        'selectOne' => (object) ['CONSTRAINT_NAME' => 'fk_test'],
    ]);

    expect($this->foreignKeyExists('any_table', 'fk_test'))->toBeTrue();
});

it('foreignKeyExists returns false on MariaDB when information_schema has no row', function (): void {
    swapDefaultConnectionWith('mariadb', [
        'selectOne' => null,
    ]);

    expect($this->foreignKeyExists('any_table', 'fk_nonexistent'))->toBeFalse();
});

it('indexExists returns true on MySQL when information_schema reports the index', function (): void {
    swapDefaultConnectionWith('mysql', [
        'selectOne' => (object) ['INDEX_NAME' => 'idx_test'],
    ]);

    expect($this->indexExists('any_table', 'idx_test'))->toBeTrue();
});

it('indexExists returns false on MariaDB when information_schema has no row', function (): void {
    swapDefaultConnectionWith('mariadb', [
        'selectOne' => null,
    ]);

    expect($this->indexExists('any_table', 'idx_nonexistent'))->toBeFalse();
});

it('findForeignKeyOn returns the constraint name on MySQL', function (): void {
    swapDefaultConnectionWith('mysql', [
        'selectOne' => (object) ['CONSTRAINT_NAME' => 'fk_user_id'],
    ]);

    expect($this->findForeignKeyOn('users', 'user_id'))->toBe('fk_user_id');
});

it('findForeignKeyOn returns null on MariaDB when no FK matches the column', function (): void {
    swapDefaultConnectionWith('mariadb', [
        'selectOne' => null,
    ]);

    expect($this->findForeignKeyOn('users', 'user_id'))->toBeNull();
});

it('hasForeignKeyOnColumn returns true on MySQL when information_schema reports a FK on the column', function (): void {
    swapDefaultConnectionWith('mysql', [
        'selectOne' => (object) ['CONSTRAINT_NAME' => 'fk_user_id'],
    ]);

    expect($this->hasForeignKeyOnColumn('users', 'user_id'))->toBeTrue();
});

it('hasForeignKeyOnColumn returns false on MariaDB when no FK matches the column', function (): void {
    swapDefaultConnectionWith('mariadb', [
        'selectOne' => null,
    ]);

    expect($this->hasForeignKeyOnColumn('users', 'user_id'))->toBeFalse();
});

it('findIndexOn returns the index name on MySQL', function (): void {
    swapDefaultConnectionWith('mysql', [
        'selectOne' => (object) ['INDEX_NAME' => 'idx_users_email'],
    ]);

    expect($this->findIndexOn('users', 'email'))->toBe('idx_users_email');
});

it('findIndexOn returns null on MariaDB when no index leftmost-matches the column', function (): void {
    swapDefaultConnectionWith('mariadb', [
        'selectOne' => null,
    ]);

    expect($this->findIndexOn('users', 'email'))->toBeNull();
});

it('hasPrimaryKey returns true on MySQL when the PRIMARY index is reported', function (): void {
    swapDefaultConnectionWith('mysql', [
        'selectOne' => (object) ['INDEX_NAME' => 'PRIMARY'],
    ]);

    expect($this->hasPrimaryKey('any_table'))->toBeTrue();
});

it('hasPrimaryKey returns false on MariaDB when no PRIMARY row is reported', function (): void {
    swapDefaultConnectionWith('mariadb', [
        'selectOne' => null,
    ]);

    expect($this->hasPrimaryKey('any_table'))->toBeFalse();
});

/**
 * PHPStan does not trace Pest's `uses(MigrationHelpers::class)` binding
 * (it points at a runtime-generated TestCase that PHPStan does not
 * see in the source). Declaring an explicit class here gives the
 * analyser a class-body usage to recognise. The class is never
 * instantiated — every test calls the trait methods through `$this`
 * on the Pest-generated TestCase.
 */
final class MigrationHelpersTraitHarness
{
    use MigrationHelpers;
}
