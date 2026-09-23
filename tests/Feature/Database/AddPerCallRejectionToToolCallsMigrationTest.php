<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

beforeEach(function (): void {
    TestDatabaseFactory::freshDatabase();
});

test('0049 migration is a no-op against the post-install schema (the production regression guard)', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0049_add_per_call_rejection_to_tool_calls.php';

    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_at'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_by'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tool_calls', 'reject_reason'))->toBeTrue();

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
});

test('0049 migration is idempotent — up() can be re-run any number of times', function (): void {
    // Reproduces the production failure mode: a prior partial run added
    // the columns but the `migrations` row was never recorded, so every
    // subsequent spora:install re-attempted the ADD COLUMN and crashed
    // with SQLSTATE 42S21.
    $migration = require __DIR__ . '/../../../database/migrations/0049_add_per_call_rejection_to_tool_calls.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_at'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_by'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tool_calls', 'reject_reason'))->toBeTrue();
});

test('0049 migration down() removes all three columns', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0049_add_per_call_rejection_to_tool_calls.php';

    $migration->down();

    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_at'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_by'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('tool_calls', 'reject_reason'))->toBeFalse();
});

test('0049 migration down() is idempotent — second call is a no-op', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0049_add_per_call_rejection_to_tool_calls.php';

    expect(fn() => $migration->down())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->down())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_at'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_by'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('tool_calls', 'reject_reason'))->toBeFalse();
});

test('0049 migration lands the FK on rejected_by', function (): void {
    // Drives the gate through to a fresh-schema install: drop the FK +
    // columns first so up() runs the full create path, then verify the
    // FK is back. Catches regressions where hasForeignKeyOnColumn
    // accidentally returns true on a column with no FK.
    $migration = require __DIR__ . '/../../../database/migrations/0049_add_per_call_rejection_to_tool_calls.php';
    $migration->down();

    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_by'))->toBeFalse();

    $migration->up();

    $harness = new class {
        use Spora\Core\Database\MigrationHelpers;
    };
    expect($harness->hasForeignKeyOnColumn('tool_calls', 'rejected_by'))->toBeTrue();
});
