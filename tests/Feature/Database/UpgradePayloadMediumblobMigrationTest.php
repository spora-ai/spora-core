<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;

beforeEach(function (): void {
    // `freshDatabase()` so the test sees an empty schema on every driver —
    // this test file issues DDL mid-test (calling a migration's `up()`
    // directly on top of the installed schema) so transaction-rollback
    // isolation is not enough; a per-test fresh database is the safe default.
    TestDatabaseFactory::freshDatabase();
});

test('0064 migration runs as a no-op on SQLite (no ALTER TABLE issued)', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0064_upgrade_payload_to_mediumblob.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
    expect(Capsule::schema()->hasColumn('media_assets', 'payload'))->toBeTrue();
});

test('0064 migration is forward-only (down() preserves the column)', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0064_upgrade_payload_to_mediumblob.php';

    $migration->up();
    $migration->down();

    expect(Capsule::schema()->hasColumn('media_assets', 'payload'))->toBeTrue();
});
