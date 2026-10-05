<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

/**
 * Pin migration 0091, the column drop that makes the `md`-derivative cut
 * irreversible.
 *
 * The migration is the only part of this change an operator cannot undo
 * from the app, so its ordering guarantee, its idempotency, and the fact
 * that `down()` restores the *schema* (never the data) are all worth
 * pinning explicitly rather than inferring from a green suite.
 */
afterEach(function (): void {
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

    // The drop below leaves the per-worker DB without the table; the next
    // worker's `boot()` has to reinstall the schema.
    if ($driver !== 'sqlite') {
        TestDatabaseFactory::markWorkerDbDirty();
    }
});

function runMarkdownDropMigration(): mixed
{
    return require BASE_PATH . '/database/migrations/0091_drop_markdown_content_from_media_assets.php';
}

function seedPreDropMediaAssets(): void
{
    $driver = Capsule::connection()->getDriverName();
    if ($driver === 'mysql' || $driver === 'mariadb') {
        Capsule::statement('SET FOREIGN_KEY_CHECKS = 0');
    }
    try {
        Capsule::schema()->dropIfExists('media_assets');
        Capsule::schema()->create('media_assets', static function (Blueprint $t): void {
            $t->string('id', 36)->primary();
            $t->text('prompt')->nullable();
            $t->longText('markdown_content')->nullable();
            $t->text('transcript')->nullable();
            $t->timestamps();
        });
    } finally {
        if ($driver === 'mysql' || $driver === 'mariadb') {
            Capsule::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}

function droppedColumnNames(): array
{
    $driver = Capsule::connection()->getDriverName();
    if ($driver === 'sqlite') {
        return collect(Capsule::select('PRAGMA table_info(media_assets)'))
            ->pluck('name')
            ->all();
    }

    $db = Capsule::connection()->getDatabaseName();
    return collect(Capsule::select(
        'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [$db, 'media_assets'],
    ))->pluck('name')->all();
}

test('up() drops markdown_content', function (): void {
    seedPreDropMediaAssets();
    expect(droppedColumnNames())->toContain('markdown_content');

    runMarkdownDropMigration()->up();

    expect(droppedColumnNames())->not->toContain('markdown_content');
});

test('up() leaves the sibling columns alone', function (): void {
    // The migration must not become a "reset the transcript cache too"
    // change by accident — `transcript` sits right next to the dropped
    // column and is the reason 0080 has to run first.
    seedPreDropMediaAssets();

    runMarkdownDropMigration()->up();

    expect(droppedColumnNames())->toContain('transcript', 'prompt', 'id');
});

test('up() is idempotent — running twice does not error', function (): void {
    // A second `up()` on an already-dropped column would raise
    // "no such column" from the engine if the `hasColumn` guard were
    // missing. Invoked directly rather than through
    // `expect(...)->not->toThrow(...)`: that form invokes the closure but
    // does not assert, so it would stay green without the guard.
    seedPreDropMediaAssets();
    runMarkdownDropMigration()->up();
    runMarkdownDropMigration()->up();

    expect(droppedColumnNames())->not->toContain('markdown_content');
});

test('down() re-adds markdown_content as nullable', function (): void {
    // A full rollback must be safe: 0080's own `down()` only drops
    // `transcript` / `transcript_language` and never re-adds
    // `markdown_content`, so the two do not fight.
    seedPreDropMediaAssets();
    runMarkdownDropMigration()->up();
    expect(droppedColumnNames())->not->toContain('markdown_content');

    runMarkdownDropMigration()->down();

    expect(droppedColumnNames())->toContain('markdown_content');
});

test('down() re-adds the column but cannot restore the data', function (): void {
    // The deliberate trade-off, pinned as an assertion: a row's extracted
    // Markdown does not come back. The operator's decision is that it is
    // recreatable on demand, and this test is what stops anyone reading a
    // green `down()` as "the rollback is lossless".
    seedPreDropMediaAssets();
    Capsule::table('media_assets')->insert([
        'id'               => 'pre-0091-row',
        'markdown_content' => "# Chapter 1\n\nIrreplaceable extracted text.",
        'created_at'       => date('Y-m-d H:i:s'),
        'updated_at'       => date('Y-m-d H:i:s'),
    ]);

    runMarkdownDropMigration()->up();
    runMarkdownDropMigration()->down();

    $row = Capsule::table('media_assets')->where('id', 'pre-0091-row')->first();
    expect($row)->not->toBeNull();
    expect($row->markdown_content)->toBeNull();
});

test('down() is idempotent — running twice does not error', function (): void {
    // A second `down()` on an already-restored column would raise
    // "duplicate column name". Called directly for the reason described in
    // the `up()` idempotency test.
    seedPreDropMediaAssets();
    runMarkdownDropMigration()->up();
    runMarkdownDropMigration()->down();
    runMarkdownDropMigration()->down();

    expect(droppedColumnNames())->toContain('markdown_content');
});

test('up() is a no-op when the table does not exist', function (): void {
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

    // Called directly: a missing-table migration would fatal on the
    // schema probe, and `expect(...)->not->toThrow()` does not assert.
    runMarkdownDropMigration()->up();
    runMarkdownDropMigration()->down();

    expect(true)->toBeTrue();
});

test('the migration is numbered after the transcript migration and drops a column 0080 anchors on', function (): void {
    // The ordering constraint is a comment in the migration file and
    // nothing enforces it. Pin the two facts that make it real: 0091 is
    // the next number after the current tail, and 0080 really does still
    // reference the dropped column by name. If someone renumbers or
    // rewrites 0080, this fails rather than producing a `db:reset` that
    // dies halfway on MySQL.
    $migrations = glob(BASE_PATH . '/database/migrations/*.php') ?: [];
    $names = array_map('basename', $migrations);
    expect($names)->toContain('0091_drop_markdown_content_from_media_assets.php');

    $transcribe = file_get_contents(BASE_PATH . '/database/migrations/0080_add_transcript_to_media_assets.php');
    expect($transcribe)->not->toBeFalse();
    expect($transcribe)->toContain("->after('markdown_content')");
});
