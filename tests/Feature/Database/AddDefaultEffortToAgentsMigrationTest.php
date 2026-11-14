<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Drivers\EffortLevelSet;
use Spora\Models\Agent;

/**
 * Migration 0102 — the `agents.default_effort` column.
 *
 * The column carries the reasoning effort the agent runs with when
 * nobody names one, and the installer keys on the highest numeric prefix
 * of the migration filenames: a migration numbered below the current
 * maximum never runs at all, so the number itself is part of the
 * contract.
 *
 * The width is the level-name cap, and the column is nullable because
 * null means "inherit": the effective LLM configuration's own default
 * applies.
 */
beforeEach(function (): void {
    // DDL mid-test → per-test fresh DB (transaction rollback isn't enough).
    TestDatabaseFactory::freshDatabase();
});

/**
 * The `default_effort` column's definition, as the engine reports it.
 *
 * @return array<string, mixed>|null
 */
function defaultEffortColumn(): ?array
{
    foreach (Capsule::schema()->getColumns('agents') as $column) {
        if ($column['name'] === 'default_effort') {
            return $column;
        }
    }

    return null;
}

test('0102 adds the default_effort column to agents', function (): void {
    expect(Capsule::schema()->getColumnListing('agents'))->toContain('default_effort');
});

test('0102 leaves the column a nullable varchar', function (): void {
    $column = defaultEffortColumn();

    expect($column)->not->toBeNull()
        ->and(strtolower((string) $column['type']))->toContain('varchar')
        ->and($column['nullable'])->toBeTrue();
});

test('0102 declares the width the level-name cap allows', function (): void {
    if (TestDatabaseFactory::driver() === 'sqlite') {
        $this->markTestSkipped('SQLite drops the VARCHAR width from the column type.');
    }

    $column = defaultEffortColumn();

    expect(strtolower((string) $column['type']))->toContain('varchar(16)');
});

test('the width the application enforces is the level-name cap', function (): void {
    expect(Agent::STRING_COLUMN_MAX_LENGTHS['default_effort'])->toBe(EffortLevelSet::MAX_NAME_LENGTH);
});

test('0102 is idempotent', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0102_add_default_effort_to_agents.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->getColumnListing('agents'))->toContain('default_effort');
});
