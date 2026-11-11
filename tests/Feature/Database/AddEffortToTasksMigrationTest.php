<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Migration 0099 — the `tasks.effort` column.
 *
 * The column carries the reasoning effort the turn was sent with, and the
 * installer keys on the highest numeric prefix of the migration filenames:
 * a migration numbered below the current maximum never runs at all, so the
 * number itself is part of the contract.
 */
beforeEach(function (): void {
    // DDL mid-test → per-test fresh DB (transaction rollback isn't enough).
    TestDatabaseFactory::freshDatabase();
});

test('0099 adds the effort column to tasks', function (): void {
    expect(Capsule::schema()->getColumnListing('tasks'))->toContain('effort');
});

test('0099 leaves the column nullable, so a task can carry no effort', function (): void {
    $column = Capsule::schema()->getColumnType('tasks', 'effort');

    expect(strtolower((string) $column))->toContain('varchar');
});

test('0099 is idempotent', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0099_add_effort_to_tasks.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->getColumnListing('tasks'))->toContain('effort');
});
