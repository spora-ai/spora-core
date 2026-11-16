<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Services\AgentStepCap;

/**
 * Migration 0104 — the step-cap columns widen to what the ceiling allows.
 *
 * Every column that holds a cap was a `TINYINT`: the agent and task columns
 * unsigned, the template and schedule columns signed. The signed pair tops
 * out at 127 and the unsigned pair at 255, while the ceiling
 * (`SPORA_MAX_STEPS`) defaults to 200 and is meant to be raised.
 *
 * So a template asking for 150 steps passed validation and then failed to
 * store on MySQL and MariaDB, while SQLite — which stores every integer it
 * is given — took it. The same divergence would have appeared on the agent
 * and task columns the moment an operator raised the ceiling past 255.
 *
 * The installer keys on the highest numeric prefix of the migration
 * filenames: a migration numbered below the current maximum never runs at
 * all, so the number itself is part of the contract.
 */
beforeEach(function (): void {
    // DDL mid-test → per-test fresh DB (transaction rollback isn't enough).
    TestDatabaseFactory::freshDatabase();
});

/**
 * The column's definition, as the engine reports it.
 *
 * @return array<string, mixed>|null
 */
function stepCapColumn(string $table, string $column): ?array
{
    foreach (Capsule::schema()->getColumns($table) as $reported) {
        if ($reported['name'] === $column) {
            return $reported;
        }
    }

    return null;
}

/**
 * The columns that hold a cap.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function stepCapColumns(): array
{
    return [
        'agents' => ['agents', 'max_steps'],
        'tasks' => ['tasks', 'max_steps'],
        'templates' => ['agent_prompt_templates', 'max_steps'],
        'schedules' => ['scheduled_runs', 'max_steps_override'],
    ];
}

test('0104 widens every column that holds a cap', function (): void {
    if (TestDatabaseFactory::driver() === 'sqlite') {
        $this->markTestSkipped('SQLite stores every integer as an INTEGER.');
    }

    foreach (stepCapColumns() as [$table, $column]) {
        $reported = stepCapColumn($table, $column);

        expect($reported)->not->toBeNull()
            ->and(strtolower((string) $reported['type']))->toContain('smallint');
    }
});

test('0104 keeps the nullability each column had', function (): void {
    expect(stepCapColumn('agents', 'max_steps')['nullable'])->toBeFalse()
        ->and(stepCapColumn('tasks', 'max_steps')['nullable'])->toBeFalse()
        ->and(stepCapColumn('agent_prompt_templates', 'max_steps')['nullable'])->toBeTrue()
        ->and(stepCapColumn('scheduled_runs', 'max_steps_override')['nullable'])->toBeTrue();
});

test('0104 aligns the column default with the application default', function (): void {
    // SQLite reports the default as the literal `'100'`, quotes included.
    expect((int) trim((string) stepCapColumn('agents', 'max_steps')['default'], "'"))->toBe(AgentStepCap::DEFAULT_CAP)
        ->and((int) trim((string) stepCapColumn('tasks', 'max_steps')['default'], "'"))->toBe(AgentStepCap::DEFAULT_CAP);
});

test('a cap the widened column can hold is stored', function (): void {
    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => createUserPrincipalPublic(1),
        'name'         => 'Wide Cap',
        'max_steps'    => 400,
        'is_active'    => true,
        'created_at'   => '2026-01-01 00:00:00',
        'updated_at'   => '2026-01-01 00:00:00',
    ]);

    expect((int) Capsule::table('agents')->where('id', $agentId)->value('max_steps'))->toBe(400);
});

test('0104 is idempotent', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0104_widen_the_step_cap_columns.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    expect(stepCapColumn('agents', 'max_steps'))->not->toBeNull();
});

test('the ceiling the application allows fits the column', function (): void {
    // The ceiling is what an operator may raise the cap to, and the column
    // is what holds the cap: a ceiling above the column's range would
    // accept a cap the database refuses to store.
    expect(AgentStepCap::COLUMN_MAX)->toBe(65535);
});
