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

test('0066 migration runs as a no-op on SQLite (no ALTER TABLE issued)', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0066_widen_text_columns_to_mediumtext.php';

    // Every targeted table+column pair must already exist after the boot
    // migration runs — assert they're still there after the no-op upgrade.
    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    $expected = [
        ['task_history', 'content'],
        ['task_history', 'tool_call_payload'],
        ['tasks', 'user_prompt'],
        ['tasks', 'final_response'],
        ['tasks', 'failure_reason'],
        ['tasks', 'error_message'],
        ['agents', 'system_prompt'],
        ['tool_calls', 'proposed_arguments'],
        ['tool_calls', 'approved_arguments'],
        ['tool_calls', 'result_content'],
        ['tool_calls', 'result_data'],
        ['tool_calls', 'human_description'],
    ];
    foreach ($expected as [$table, $column]) {
        expect(Capsule::schema()->hasTable($table))->toBeTrue("table {$table} should exist");
        expect(Capsule::schema()->hasColumn($table, $column))->toBeTrue("column {$table}.{$column} should exist");
    }
});

test('0066 migration is forward-only (down() preserves all widened columns)', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0066_widen_text_columns_to_mediumtext.php';

    $migration->up();
    $migration->down();

    // Downgrade intentionally does nothing — a real downgrade would re-
    // introduce the SQLSTATE 22001 truncation. Verify no columns dropped.
    expect(Capsule::schema()->hasColumn('task_history', 'content'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tasks', 'final_response'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('tool_calls', 'result_content'))->toBeTrue();
});
