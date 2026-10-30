<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

beforeEach(function (): void {
    TestDatabaseFactory::freshDatabase();
});

/**
 * The named indexes of a table, on either engine.
 *
 * @return list<string>
 */
function mediaFolderIndexes(string $table): array
{
    $driver = Capsule::connection()->getDriverName();

    if ($driver === 'mysql' || $driver === 'mariadb') {
        $rows = Capsule::select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        return array_values(array_unique(array_map(
            static fn($row): string => (string) $row->INDEX_NAME,
            $rows,
        )));
    }

    $rows = Capsule::select("PRAGMA index_list('{$table}')");

    return array_values(array_map(static fn($row): string => (string) $row->name, $rows));
}

test('0097 creates the media_folders table with its columns', function (): void {
    expect(Capsule::schema()->hasTable('media_folders'))->toBeTrue();

    $columns = Capsule::schema()->getColumnListing('media_folders');

    expect($columns)->toContain('id');
    expect($columns)->toContain('principal_id');
    expect($columns)->toContain('parent_id');
    expect($columns)->toContain('name');
    expect($columns)->toContain('path');
});

test('0097 adds folder_id and kind to media_assets', function (): void {
    $columns = Capsule::schema()->getColumnListing('media_assets');

    expect($columns)->toContain('folder_id');
    expect($columns)->toContain('kind');
});

test('0097 creates the unique (folder_id, filename) index', function (): void {
    // The rule D4 asks for: filenames are unique within a folder. The
    // index is what enforces it — the service checks it first and
    // reports a 422.
    expect(mediaFolderIndexes('media_assets'))->toContain('media_assets_folder_filename_unique');
});

test('0097 creates the unique (principal_id, path) index on media_folders', function (): void {
    // Sibling names are unique because two siblings with one name produce
    // one path.
    expect(mediaFolderIndexes('media_folders'))->toContain('media_folders_principal_path_unique');
});

test('0097 lands the foreign keys on media_folders', function (): void {
    $harness = new class {
        use Spora\Core\Database\MigrationHelpers;
    };

    expect($harness->hasForeignKeyOnColumn('media_folders', 'principal_id'))->toBeTrue();
    expect($harness->hasForeignKeyOnColumn('media_folders', 'parent_id'))->toBeTrue();
});

test('0097 lands the media_assets.folder_id foreign key on the engines that can carry it', function (): void {
    // SQLite cannot add a foreign key to an existing table — Laravel's
    // SQLite grammar compiles the ALTER to nothing — so the constraint
    // only exists on MySQL/MariaDB. The service moves the assets to the
    // root itself, so the behaviour is the same on every engine.
    $driver = Capsule::connection()->getDriverName();

    $harness = new class {
        use Spora\Core\Database\MigrationHelpers;
    };

    if ($driver === 'sqlite') {
        expect(Capsule::schema()->hasColumn('media_assets', 'folder_id'))->toBeTrue();

        return;
    }

    expect($harness->hasForeignKeyOnColumn('media_assets', 'folder_id'))->toBeTrue();
});

test('0097 is idempotent — up() can be re-run any number of times', function (): void {
    // The installer runs the migrations against an already-populated
    // database, so a re-run must not fail.
    $migration = require __DIR__ . '/../../../database/migrations/0097_create_media_folders_table.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasTable('media_folders'))->toBeTrue();
});

test('0097 down() drops the table and the two media_assets columns', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0097_create_media_folders_table.php';

    $migration->down();

    expect(Capsule::schema()->hasTable('media_folders'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('media_assets', 'folder_id'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('media_assets', 'kind'))->toBeFalse();
});

test('0097 down() is idempotent — a second call is a no-op', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0097_create_media_folders_table.php';

    $migration->down();

    expect(fn() => $migration->down())->not()->toThrow(Throwable::class);
});

test('0097 up() after down() restores the whole schema', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0097_create_media_folders_table.php';

    $migration->down();
    $migration->up();

    expect(Capsule::schema()->hasTable('media_folders'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('media_assets', 'folder_id'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('media_assets', 'kind'))->toBeTrue();
    expect(mediaFolderIndexes('media_assets'))->toContain('media_assets_folder_filename_unique');
});
