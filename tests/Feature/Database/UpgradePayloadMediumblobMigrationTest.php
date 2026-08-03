<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;

beforeEach(function (): void {
    Database::resetBootState();
    $db = new Database([
        'db_driver' => 'sqlite',
        'db_path'   => ':memory:',
    ]);
    $db->boot();
});

test('0064 migration runs as a no-op on SQLite (no ALTER TABLE issued)', function (): void {
    // The migration must short-circuit on non-MySQL drivers so SQLite
    // installs (the dev default) don't error on a statement that the
    // engine doesn't understand.
    $migration = require __DIR__ . '/../../../database/migrations/0064_upgrade_payload_to_mediumblob.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    // `media_assets.payload` exists from migration 0052 and stays as
    // SQLite's `binary` affinity (no length cap).
    expect(Capsule::schema()->hasColumn('media_assets', 'payload'))->toBeTrue();
});

test('0064 migration is forward-only (down() preserves the column)', function (): void {
    // The class docblock explains why — a rollback would re-introduce
    // the truncation bug. down() exists only so Laravel's
    // migrate:rollback doesn't error out; it must not actually drop
    // the column or change its type.
    $migration = require __DIR__ . '/../../../database/migrations/0064_upgrade_payload_to_mediumblob.php';

    $migration->up();
    $migration->down();

    expect(Capsule::schema()->hasColumn('media_assets', 'payload'))->toBeTrue();
});
