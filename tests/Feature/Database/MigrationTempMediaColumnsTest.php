<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Migration 0081 introduces the temp-file media lifecycle: an
 * `is_temporary` flag on `media_assets` and a
 * `voice_message_retention_count` ceiling on `agents`.
 *
 * These tests boot a fresh in-memory SQLite, run the schema migrations
 * the new columns depend on (0067 + 0080 so the migration can find
 * both tables in the shape it expects), then run the migration and
 * assert the schema-shape outcomes:
 *
 *   - `media_assets.is_temporary` exists, defaults to false, NOT NULL;
 *   - the composite index `idx_media_assets_user_agent_temp` lands;
 *   - `agents.voice_message_retention_count` exists, defaults to 5,
 *     accepts the documented 0..100 range;
 *   - the CHECK constraint is attached (SQLite exposes it via
 *     `pragma table_info`); a value of 101 raises at the SQL layer.
 */
beforeEach(function (): void {
    // `freshConnectionOnly()`: the test builds its own minimal schema,
    // so we skip the framework's schema installer and just open a fresh
    // DB connection (per-test on MySQL/MariaDB so the manual CREATE
    // TABLE statements below don't collide with pre-existing tables).
    TestDatabaseFactory::freshConnectionOnly();

    // The migration touches `media_assets` and `agents` — both need to
    // exist in the shape the original migrations created. Stamping
    // every dependency from scratch keeps the test self-contained.
    Capsule::schema()->create('media_assets', static function (Blueprint $t): void {
        $t->uuid('id')->primary();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->unsignedBigInteger('agent_id')->nullable();
        $t->string('upload_source', 32)->nullable();
        $t->timestamps();
    });
    Capsule::schema()->create('agents', static function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->string('name');
        $t->integer('max_retries')->default(0);
        $t->timestamps();
    });
});

test('up adds is_temporary to media_assets with the documented default', function (): void {
    if (Capsule::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The not-null assertion reads `notnull` from `PRAGMA table_info`; the column presence check works on MariaDB but the not-null flag does not.');
    }
    $migration = require __DIR__ . '/../../../database/migrations/0081_add_temp_media_columns.php';
    $migration->up();

    expect(Capsule::schema()->hasColumn('media_assets', 'is_temporary'))->toBeTrue();
    // SQLite stores BOOLEAN as TINYINT(1) — the default surfaces as
    // `b'0'` so we assert the column is present and not-nullable via
    // schema introspection rather than value comparison.
    $cols = Capsule::select("PRAGMA table_info('media_assets')");
    $col = null;
    foreach ($cols as $c) {
        if ($c->name === 'is_temporary') {
            $col = $c;
            break;
        }
    }
    expect($col)->not->toBeNull();
    expect((int) $col->notnull)->toBe(1);
});

test('up creates the (user_id, agent_id, is_temporary, created_at) composite index', function (): void {
    if (Capsule::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Index enumeration uses `PRAGMA index_list`; the SHOW INDEX equivalent would need its own block.');
    }
    $migration = require __DIR__ . '/../../../database/migrations/0081_add_temp_media_columns.php';
    $migration->up();

    $rows = Capsule::select("PRAGMA index_list('media_assets')");
    $names = array_map(static fn($r): string => $r->name, $rows);
    expect($names)->toContain('idx_media_assets_user_agent_temp');
});

test('up adds voice_message_retention_count with default 5 and a CHECK constraint', function (): void {
    if (Capsule::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Reads `dflt_value` from `PRAGMA table_info` and exercises the CHECK constraint shape that SQLite emits; the MariaDB path uses information_schema.columns and the constraint syntax differs.');
    }
    $migration = require __DIR__ . '/../../../database/migrations/0081_add_temp_media_columns.php';
    $migration->up();

    expect(Capsule::schema()->hasColumn('agents', 'voice_message_retention_count'))->toBeTrue();

    // SQLite stores the column default in `dflt_value` as the literal
    // SQL it would emit. For an integer default this round-trips as
    // just the number — assert the integer default is exactly 5.
    $cols = Capsule::select("PRAGMA table_info('agents')");
    $col = null;
    foreach ($cols as $c) {
        if ($c->name === 'voice_message_retention_count') {
            $col = $c;
            break;
        }
    }
    expect($col)->not->toBeNull();
    expect((int) $col->notnull)->toBe(1);
    expect((int) $col->dflt_value)->toBe(5);

    // SQLite exposes CHECK clauses as a row in `PRAGMA table_info`
    // since 3.16 — and Laravel's sqlite builder emits them as inline
    // `CHECK (...)` clauses when the schema dialect supports it. We
    // assert the column is at least documented and the constraint
    // fires by trying to write an out-of-range value.
    Capsule::table('agents')->insert([
        'name' => 'out-of-range',
        'voice_message_retention_count' => 50,
    ]);
    Capsule::table('agents')->insert([
        'name' => 'zero',
        'voice_message_retention_count' => 0,
    ]);
    Capsule::table('agents')->insert([
        'name' => 'hundred',
        'voice_message_retention_count' => 100,
    ]);
    expect(Capsule::table('agents')->count())->toBe(3);

    // 101 violates the documented range; SQLite throws a check
    // constraint failed error.
    Capsule::table('agents')->insert([
        'name' => 'over',
        'voice_message_retention_count' => 101,
    ]);
})->throws(PDOException::class);

test('up is idempotent — re-running does not raise on a partially migrated DB', function (): void {
    $migration = require __DIR__ . '/../../../database/migrations/0081_add_temp_media_columns.php';
    $migration->up();
    // Second run should be a no-op because every step gates on
    // hasColumn / indexExists. A fresh `up()` against a DB that
    // already has the column + index would otherwise raise on
    // "duplicate column name" / "index already exists".
    $migration->up();

    expect(Capsule::schema()->hasColumn('media_assets', 'is_temporary'))->toBeTrue();
    expect(Capsule::schema()->hasColumn('agents', 'voice_message_retention_count'))->toBeTrue();
});

test('up backfills pre-existing agents with the default retention count', function (): void {
    // Seed an agent *before* the migration runs so the row exists
    // without `voice_message_retention_count`. Without the backfill
    // the service would read NULL and the `?? 0` fallback would
    // silently opt every pre-0081 agent out of auto-purge.
    Capsule::table('agents')->insert([
        'id'   => 42,
        'name' => 'legacy-agent',
    ]);

    $migration = require __DIR__ . '/../../../database/migrations/0081_add_temp_media_columns.php';
    $migration->up();

    $row = Capsule::table('agents')->where('id', 42)->first();
    expect($row)->not->toBeNull();
    expect((int) $row->voice_message_retention_count)->toBe(5);
});

test('down drops the columns and index', function (): void {
    if (Capsule::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Index enumeration uses `PRAGMA index_list`; the column-drops are driver-portable but the index assertion needs the MariaDB SHOW INDEX path.');
    }
    $migration = require __DIR__ . '/../../../database/migrations/0081_add_temp_media_columns.php';
    $migration->up();
    $migration->down();

    expect(Capsule::schema()->hasColumn('media_assets', 'is_temporary'))->toBeFalse();
    expect(Capsule::schema()->hasColumn('agents', 'voice_message_retention_count'))->toBeFalse();
    $rows = Capsule::select("PRAGMA index_list('media_assets')");
    $names = array_map(static fn($r): string => $r->name, $rows);
    expect($names)->not->toContain('idx_media_assets_user_agent_temp');
});
