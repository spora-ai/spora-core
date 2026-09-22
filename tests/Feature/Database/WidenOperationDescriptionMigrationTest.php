<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

beforeEach(function (): void {
    TestDatabaseFactory::freshDatabase();
    // Migration test mutates the schema after boot; force the guard's
    // memoized column map to re-read so any subsequent asserts see the
    // post-migration shape.
    Spora\Agents\ToolCallInsertGuard::resetCache();
});

test('0085 migration runs without throwing on the test database', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0085_widen_tool_calls_operation_description_to_text.php';

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);
});

test('0085 migration is forward-only (down() is a no-op)', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0085_widen_tool_calls_operation_description_to_text.php';

    $migration->up();
    $migration->down();

    // Column must still exist after the no-op down().
    expect(Capsule::schema()->hasColumn('tool_calls', 'operation_description'))->toBeTrue();
});

test('0085 migration widens operation_description from VARCHAR to TEXT on MySQL/MariaDB', function (): void {
    $driver = Capsule::connection()->getDriverName();
    if (!in_array($driver, ['mysql', 'mariadb'], true)) {
        // SQLite stores TEXT as VARCHAR(255) under the hood, so the
        // type_name assertion below would be misleading there. The
        // CI matrix exercises both MySQL and MariaDB.
        $this->markTestSkipped('MySQL/MariaDB-only column-type assertion.');
    }

    $migration = require __DIR__ . '/../../../database/migrations/0085_widen_tool_calls_operation_description_to_text.php';
    $migration->up();

    foreach (Capsule::schema()->getColumns('tool_calls') as $column) {
        if ($column['name'] !== 'operation_description') {
            continue;
        }
        expect(strtolower((string) $column['type_name']))->toBe('text');
        return;
    }

    $this->fail('tool_calls.operation_description column not found after migration.');
});
