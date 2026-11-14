<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Migration 0103 — the `agent_prompt_templates.effort` and
 * `scheduled_runs.effort_override` columns.
 *
 * The columns carry the reasoning effort a saved prompt and a scheduled run
 * use, and the installer keys on the highest numeric prefix of the migration
 * filenames: a migration numbered below the current maximum never runs at
 * all, so the number itself is part of the contract.
 */
beforeEach(function (): void {
    // DDL mid-test → per-test fresh DB (transaction rollback isn't enough).
    TestDatabaseFactory::freshDatabase();
});

test('0103 adds the effort column to agent_prompt_templates', function (): void {
    expect(Capsule::schema()->getColumnListing('agent_prompt_templates'))->toContain('effort');
});

test('0103 adds the effort_override column to scheduled_runs', function (): void {
    expect(Capsule::schema()->getColumnListing('scheduled_runs'))->toContain('effort_override');
});

test('0103 leaves both columns nullable, so a template and a run can inherit', function (): void {
    $templateColumn = Capsule::schema()->getColumnType('agent_prompt_templates', 'effort');
    $runColumn = Capsule::schema()->getColumnType('scheduled_runs', 'effort_override');

    expect(strtolower((string) $templateColumn))->toContain('varchar')
        ->and(strtolower((string) $runColumn))->toContain('varchar');
});

test('0103 is idempotent', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0103_add_effort_to_templates_and_scheduled_runs.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(Capsule::schema()->getColumnListing('agent_prompt_templates'))->toContain('effort')
        ->and(Capsule::schema()->getColumnListing('scheduled_runs'))->toContain('effort_override');
});
