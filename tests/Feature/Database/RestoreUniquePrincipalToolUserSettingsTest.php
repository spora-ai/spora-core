<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Migration 0082 restores the unique constraint on
 * `tool_user_settings.(principal_id, tool_class)` that 0067's
 * `rebuildSqliteTableWithoutUserId()` silently dropped. The test boots
 * the dependent tables (`users` + `principals` for the FK, `tool_user_settings`
 * with the post-0067 column shape) directly so the migration can be exercised
 * against fresh state.
 */
beforeEach(function (): void {
    // `freshConnectionOnly()`: the test builds its own minimal schema,
    // so we skip the framework's schema installer and just open a fresh
    // DB connection (per-test on MySQL/MariaDB so the manual CREATE
    // TABLE statements below don't collide with pre-existing tables).
    TestDatabaseFactory::freshConnectionOnly();

    // `principals.user_id` holds the FK to `users.id`, so the parent table
    // must exist before we can insert any rows the migration dedups.
    Capsule::schema()->create('users', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->string('email', 191);
        $t->string('username', 100);
        $t->timestamps();
    });
    Capsule::schema()->create('principals', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->enum('type', ['user', 'group'])->default('user');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->unsignedBigInteger('group_id')->nullable();
        $t->timestamps();
        $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    });
    Capsule::schema()->create('tool_user_settings', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('principal_id');
        $t->string('tool_class', 200);
        $t->text('settings')->nullable();
        $t->timestamp('created_at')->nullable();
        $t->timestamp('updated_at')->nullable();
        $t->foreign('principal_id')->references('id')->on('principals')->cascadeOnDelete();
    });
});

test('0082 dedupes two rows with the same (principal_id, tool_class), keeping the row with the highest (updated_at, id)', function (): void {
    $userId = (int) Capsule::table('users')->insertGetId(['email' => 'dup@example.com', 'username' => 'dup']);
    $principalId = (int) Capsule::table('principals')->insertGetId([
        'type' => 'user', 'user_id' => $userId, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    // Two rows for the SAME (principal_id, tool_class) — the duplicate
    // SELECT-then-INSERT race the migration exists to clean up. The
    // earlier `updated_at` + lower `id` is the stale loser; the later
    // pair is the canonical winner. The loser's settings blob is
    // stale by definition (a duplicate row from the race means the
    // operator only ever wrote once, the second write was a no-op),
    // so we don't merge.
    $sharedToolClass = 'App\\Tools\\SharedTool';
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $principalId,
        'tool_class'   => $sharedToolClass,
        'settings'     => '{"loser":true}',
        'created_at'   => '2026-01-01 00:00:00',
        'updated_at'   => '2026-01-01 00:00:00',
    ]);
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $principalId,
        'tool_class'   => $sharedToolClass,
        'settings'     => '{"winner":true}',
        'created_at'   => '2026-01-02 00:00:00',
        'updated_at'   => '2026-01-02 00:00:00',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0082_restore_unique_principal_tool_user_settings.php';
    $migration->up();

    // Only the winner row survived — the duplicate pair was collapsed.
    $rows = Capsule::table('tool_user_settings')->where('principal_id', $principalId)->get();
    expect($rows)->toHaveCount(1);
    expect((string) $rows[0]->tool_class)->toBe($sharedToolClass);
    expect($rows[0]->settings)->toBe('{"winner":true}');
});

test('0082 ties on updated_at fall back to the higher id', function (): void {
    $userId = (int) Capsule::table('users')->insertGetId(['email' => 'tie@example.com', 'username' => 'tie']);
    $principalId = (int) Capsule::table('principals')->insertGetId([
        'type' => 'user', 'user_id' => $userId, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $sharedToolClass = 'App\\Tools\\TiedTool';
    $ts = '2026-01-01 12:00:00';
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $principalId, 'tool_class' => $sharedToolClass,
        'settings' => '{"id":1}', 'created_at' => $ts, 'updated_at' => $ts,
    ]);
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $principalId, 'tool_class' => $sharedToolClass,
        'settings' => '{"id":2}', 'created_at' => $ts, 'updated_at' => $ts,
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0082_restore_unique_principal_tool_user_settings.php';
    $migration->up();

    $rows = Capsule::table('tool_user_settings')->where('principal_id', $principalId)->get();
    expect($rows)->toHaveCount(1);
    expect((string) $rows[0]->tool_class)->toBe($sharedToolClass);
    expect((int) $rows[0]->id)->toBeGreaterThan(1);
});

test('0082 NULL updated_at rows sort last (the epoch fallback)', function (): void {
    // Pre-0082 rows could have NULL updated_at — they should sort
    // AFTER any row with a real timestamp, so a NULL row loses a tie
    // against a row with a timestamp.
    $userId = (int) Capsule::table('users')->insertGetId(['email' => 'null@example.com', 'username' => 'null']);
    $principalId = (int) Capsule::table('principals')->insertGetId([
        'type' => 'user', 'user_id' => $userId, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $sharedToolClass = 'App\\Tools\\NullDatedTool';
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $principalId, 'tool_class' => $sharedToolClass,
        'settings' => '{"null":true}', 'created_at' => null, 'updated_at' => null,
    ]);
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $principalId, 'tool_class' => $sharedToolClass,
        'settings' => '{"dated":true}', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0082_restore_unique_principal_tool_user_settings.php';
    $migration->up();

    $rows = Capsule::table('tool_user_settings')->where('principal_id', $principalId)->get();
    expect($rows)->toHaveCount(1);
    expect((string) $rows[0]->tool_class)->toBe($sharedToolClass);
    expect($rows[0]->settings)->toBe('{"dated":true}');
});

test('0082 adds the unique constraint uq_tool_user_settings_principal_tool after dedup', function (): void {
    $userId = (int) Capsule::table('users')->insertGetId(['email' => 'idx@example.com', 'username' => 'idx']);
    $principalId = (int) Capsule::table('principals')->insertGetId([
        'type' => 'user', 'user_id' => $userId, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0082_restore_unique_principal_tool_user_settings.php';
    $migration->up();

    $rows = Capsule::select("PRAGMA index_list('tool_user_settings')");
    $names = array_map(static fn($r): string => $r->name, $rows);
    expect($names)->toContain('uq_tool_user_settings_principal_tool');

    // And it actually fires — a second insert with the same key raises.
    Capsule::table('tool_user_settings')->insert([
        'principal_id' => $principalId, 'tool_class' => 'App\\Tools\\Once',
        'settings' => null, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    expect(static function () use ($principalId): void {
        Capsule::table('tool_user_settings')->insert([
            'principal_id' => $principalId, 'tool_class' => 'App\\Tools\\Once',
            'settings' => null, 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
        ]);
    })->toThrow(PDOException::class);
});

test('0082 is idempotent — re-running on an already-migrated DB is a no-op', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0082_restore_unique_principal_tool_user_settings.php';
    $migration->up();

    expect(fn() => $migration->up())->not()->toThrow(Throwable::class);

    $rows = Capsule::select("PRAGMA index_list('tool_user_settings')");
    $names = array_map(static fn($r): string => $r->name, $rows);
    expect($names)->toContain('uq_tool_user_settings_principal_tool');
});

test('0082 is forward-only — down() leaves the constraint in place', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0082_restore_unique_principal_tool_user_settings.php';
    $migration->up();
    $migration->down();

    $rows = Capsule::select("PRAGMA index_list('tool_user_settings')");
    $names = array_map(static fn($r): string => $r->name, $rows);
    // The constraint must still exist after down() — dropping it would
    // re-enable the duplicate-row foot-gun that 0082 exists to close.
    expect($names)->toContain('uq_tool_user_settings_principal_tool');
});
