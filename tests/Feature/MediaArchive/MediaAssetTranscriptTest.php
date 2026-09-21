<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Pin the migration: idempotent, nullable TEXT columns, no regression
 * on existing rows. The transcribe controller relies on these columns
 * to cache transcripts so chat re-renders don't re-bill the upstream
 * STT API.
 */

afterEach(function (): void {
    // The migration-suite schema installs `media_derivatives` (FK to
    // media_assets.id) before this test runs. SQLite's default FK
    // semantics don't block the drop, but MariaDB/InnoDB refuses to drop
    // a parent table while a child FK points at it. Suspend FK checks for
    // the duration of the drop — they're test-scoped and never re-enabled
    // inside this file's tests.
    $driver = Capsule::connection()->getDriverName();
    if ($driver === 'mysql' || $driver === 'mariadb') {
        Capsule::statement('SET FOREIGN_KEY_CHECKS = 0');
    }
    try {
        Capsule::schema()->dropIfExists('media_assets');
    } finally {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            Capsule::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
});

function createMediaAssetsTable(): void
{
    // The shared in-memory SQLite DB carries state from prior tests, so
    // every migration test starts from a clean table. MariaDB/InnoDB holds
    // an FK from `media_derivatives` to `media_assets.id` from earlier
    // migrations in the suite — suspend FK checks so the drop succeeds.
    $driver = Capsule::connection()->getDriverName();
    if ($driver === 'mysql' || $driver === 'mariadb') {
        Capsule::statement('SET FOREIGN_KEY_CHECKS = 0');
    }
    try {
        Capsule::schema()->dropIfExists('media_assets');
        Capsule::schema()->create('media_assets', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->text('markdown_content')->nullable();
            $t->timestamps();
        });
    } finally {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            Capsule::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}

function runTranscribeMigration(): mixed
{
    return require BASE_PATH . '/database/migrations/0080_add_transcript_to_media_assets.php';
}

test('migration adds nullable transcript + transcript_language columns', function (): void {
    createMediaAssetsTable();

    runTranscribeMigration()->up();

    $columns = collect(Capsule::select('PRAGMA table_info(media_assets)'))
        ->pluck('name')
        ->all();

    expect($columns)->toContain('transcript', 'transcript_language');
    // Both nullable — pre-existing audio rows must not block migration in.
    // PRAGMA returns rows as stdClass with named properties (not arrays).
    $colInfo = collect(Capsule::select('PRAGMA table_info(media_assets)'))->keyBy('name');
    expect((int) $colInfo['transcript']->notnull)->toBe(0)
        ->and((int) $colInfo['transcript_language']->notnull)->toBe(0);
});

test('migration is idempotent — running twice does not error', function (): void {
    createMediaAssetsTable();
    runTranscribeMigration()->up();

    expect(fn() => runTranscribeMigration()->up())->not->toThrow(Throwable::class);
});

test('existing rows survive migration with null transcript columns', function (): void {
    createMediaAssetsTable();
    Capsule::table('media_assets')->insert([
        'id'               => 'legacy-uuid-1',
        'markdown_content' => null,
        'created_at'       => date('Y-m-d H:i:s'),
        'updated_at'       => date('Y-m-d H:i:s'),
    ]);

    runTranscribeMigration()->up();

    $row = Capsule::table('media_assets')->where('id', 'legacy-uuid-1')->first();
    expect($row->transcript)->toBeNull()
        ->and($row->transcript_language)->toBeNull();
});

test('down() drops the columns', function (): void {
    createMediaAssetsTable();
    runTranscribeMigration()->up();
    expect(collect(Capsule::select('PRAGMA table_info(media_assets)'))->pluck('name'))
        ->toContain('transcript', 'transcript_language');

    runTranscribeMigration()->down();

    $columns = collect(Capsule::select('PRAGMA table_info(media_assets)'))->pluck('name')->all();
    expect($columns)->not->toContain('transcript', 'transcript_language');
});
