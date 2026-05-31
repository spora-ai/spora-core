<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Database;

// Helpers

function makeTempSqliteConfig(): array
{
    return [
        'db_driver' => 'sqlite',
        'db_path'   => ':memory:',
    ];
}

function bootFreshDatabase(array $config = []): Database
{
    Database::resetBootState();

    $db = new Database($config ?: makeTempSqliteConfig());
    $db->boot();

    return $db;
}

// Tests

test('database boots successfully with in-memory SQLite', function (): void {
    expect(fn() => bootFreshDatabase())->not()->toThrow(Throwable::class);
});

test('all 8 tables are created after boot', function (): void {
    bootFreshDatabase();

    $schema = Capsule::schema();

    $expectedTables = [
        'users',
        'agents',
        'tool_configurations',
        'agent_tools',
        'agent_tool_overrides',
        'tasks',
        'tool_calls',
        'task_history',
    ];

    foreach ($expectedTables as $table) {
        expect($schema->hasTable($table))->toBeTrue("Expected table '{$table}' to exist.");
    }
});

test('booting twice is idempotent and does not throw', function (): void {
    Database::resetBootState();
    $db = new Database(makeTempSqliteConfig());

    $db->boot();

    // Reset the static flag to allow a second boot on the same connection
    Database::resetBootState();

    expect(fn() => $db->boot())->not()->toThrow(Throwable::class);
});

test('task_history table has no updated_at column', function (): void {
    bootFreshDatabase();

    $columns = Capsule::schema()->getColumnListing('task_history');

    expect($columns)->not()->toContain('updated_at');
    expect($columns)->toContain('created_at');
});

test('tasks table has pending_state column', function (): void {
    bootFreshDatabase();

    expect(Capsule::schema()->hasColumn('tasks', 'pending_state'))->toBeTrue();
});

test('agent_tools table has nullable auto_approve column', function (): void {
    bootFreshDatabase();

    expect(Capsule::schema()->hasColumn('agent_tools', 'auto_approve'))->toBeTrue();
});
