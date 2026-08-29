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

test('0070 migration adds lease_owner and lease_expires_at columns to tasks', function (): void {
    // boot() above ran DatabaseSchemaInstaller which already executed every
    // migration including 0070 — assert the post-condition directly rather
    // than running up() again (it's not idempotent on a non-empty tasks table).
    $columns = Capsule::schema()->getColumnListing('tasks');
    expect($columns)->toContain('lease_owner')
        ->and($columns)->toContain('lease_expires_at');
});

test('0070 migration creates the (status, lease_expires_at) composite index', function (): void {
    // Index names are driver-aware: SQLite stores them via PRAGMA index_list;
    // MySQL/MariaDB exposes them via SHOW INDEX. Both paths must surface the
    // composite (status, lease_expires_at) index the reaper's WHERE clause needs.
    $driver = Capsule::connection()->getDriverName();

    if ($driver === 'sqlite') {
        $indexes = Capsule::connection()->select("PRAGMA index_list('tasks')");
        $names = array_map(static fn($row): string => (string) $row->name, $indexes);
        expect($names)->toContain('tasks_status_lease_expires_at_index');
    } else {
        $indexes = Capsule::connection()->select(
            "SHOW INDEX FROM tasks WHERE Key_name = 'tasks_status_lease_expires_at_index'",
        );
        expect(count($indexes))->toBeGreaterThan(0);
    }
});

test('0071 migration creates the worker_housekeeping_locks table', function (): void {
    expect(Capsule::schema()->hasTable('worker_housekeeping_locks'))->toBeTrue();
});

test('0072 migration creates the ratelimit_hits table', function (): void {
    expect(Capsule::schema()->hasTable('ratelimit_hits'))->toBeTrue();
});
