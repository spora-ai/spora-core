<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

beforeEach(function (): void {
    // DDL mid-test → per-test fresh DB (transaction rollback isn't enough).
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
