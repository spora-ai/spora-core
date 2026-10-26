<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Rate-limit hits get a row each, rather than one per (key, second).
 *
 * The composite primary key `(key, hit_at)` deduplicated hits that landed in
 * the same second, and the insert of the second one raised a constraint
 * violation that `DbRateLimiter::attempt()` swallowed as "fail open". A
 * caller sending a hundred requests inside one second was therefore counted as
 * having sent one — which is exactly the shape of traffic a polling
 * integrator produces, and the reason the Agent API could not state its rate
 * limit as a fact.
 *
 * The table is append-and-prune: nothing reads a hit by primary key, so a
 * surrogate one costs nothing and buys every hit a row. The window query keeps
 * using `(key, hit_at)`, now as a plain index.
 *
 * SQLite cannot `ALTER TABLE … ADD COLUMN … PRIMARY KEY`, so that driver gets
 * the create-copy-drop-rename rebuild instead. Nothing holds a foreign key into
 * this table, so the rebuild has no cascade to suppress.
 */
return new class extends Migration
{
    private const INDEX = 'idx_ratelimit_hits_key_hit_at';

    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('ratelimit_hits') || $schema->hasColumn('ratelimit_hits', 'id')) {
            return;
        }

        if (Capsule::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqlite(
                'CREATE TABLE ratelimit_hits_new ('
                . '"id" integer primary key autoincrement not null, '
                . '"key" varchar(100) not null, '
                . '"hit_at" datetime not null)',
            );

            return;
        }

        $schema->table('ratelimit_hits', static function (Blueprint $table): void {
            $table->dropPrimary();
            $table->increments('id');
            $table->index(['key', 'hit_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('ratelimit_hits') || !$schema->hasColumn('ratelimit_hits', 'id')) {
            return;
        }

        // One hit per (key, second) is the old key, so collapse to it before
        // restoring the constraint — keeping the duplicates would fail the
        // primary key the very statement meant to recreate.
        Capsule::table('ratelimit_hits')
            ->whereNotIn('id', Capsule::table('ratelimit_hits')
                ->selectRaw('MIN(id)')
                ->groupBy('key', 'hit_at'))
            ->delete();

        if (Capsule::connection()->getDriverName() === 'sqlite') {
            $this->rebuildSqlite(
                'CREATE TABLE ratelimit_hits_new ('
                . '"key" varchar(100) not null, '
                . '"hit_at" datetime not null, '
                . 'primary key ("key", "hit_at"))',
            );

            return;
        }

        $schema->table('ratelimit_hits', static function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn('id');
            $table->primary(['key', 'hit_at']);
        });
    }

    /** Copy the rows across a table SQLite can rebuild and move into place. */
    private function rebuildSqlite(string $createSql): void
    {
        Capsule::connection()->statement($createSql);
        Capsule::statement('INSERT INTO ratelimit_hits_new ("key", "hit_at") SELECT "key", "hit_at" FROM ratelimit_hits');
        Capsule::statement('DROP TABLE ratelimit_hits');
        Capsule::statement('ALTER TABLE ratelimit_hits_new RENAME TO ratelimit_hits');
        Capsule::statement('CREATE INDEX ' . self::INDEX . ' ON ratelimit_hits ("key", "hit_at")');
    }
};
