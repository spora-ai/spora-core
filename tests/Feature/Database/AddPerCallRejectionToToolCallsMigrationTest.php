<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

beforeEach(function (): void {
    TestDatabaseFactory::freshDatabase();
});

test('0049 migration leaves all three columns and the FK in place on a fresh schema', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0049_add_per_call_rejection_to_tool_calls.php';

    // The fresh schema already ran 0049 via the installer, so the
    // columns are present before we touch the migration object. Running
    // up() must be a no-op (this is the production regression we're
    // guarding against) and must not throw.
    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_at'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_by'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tool_calls', 'reject_reason'))->toBeTrue();

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_at'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_by'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tool_calls', 'reject_reason'))->toBeTrue();
});

test('0049 migration is idempotent — up() can be re-run any number of times', function (): void {
    // Reproduces the production failure mode: a prior run added the
    // columns to tool_calls but the Laravel `migrations` row was never
    // recorded (e.g. FK add failed mid-statement), so every subsequent
    // spora:install re-attempted the ADD COLUMN and crashed with
    // SQLSTATE 42S21 ("Column already exists: rejected_at"). After the
    // fix, the gates must let the re-run pass through silently.
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

test('0049 migration down() is idempotent on a fresh schema (no-op)', function (): void {
    // Calling down() twice in a row — the second call should be a no-op
    // since the columns are already gone and the FK too. Mirrors the
    // up() idempotency contract for the reverse direction.
    $migration = require __DIR__ . '/../../../database/migrations/0049_add_per_call_rejection_to_tool_calls.php';

    expect(fn() => $migration->down())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->down())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_at'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('tool_calls', 'rejected_by'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('tool_calls', 'reject_reason'))->toBeFalse();
});
