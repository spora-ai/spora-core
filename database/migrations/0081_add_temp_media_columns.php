<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Introduce the temp-file media lifecycle.
 *
 *   1. `media_assets.is_temporary` — flag for rows that should be eligible
 *      for automatic cleanup (default false so every pre-existing row is
 *      already permanent). The composite index covers the count-based
 *      purge query at ingest (`MediaArchiveService::enforceTempRetention`):
 *      a (user_id, agent_id) lookup that filters on `is_temporary=TRUE` and
 *      orders by `created_at ASC` to drop the oldest.
 *
 *   2. `agents.voice_message_retention_count` — per-agent ceiling on the
 *      number of temp rows a (user, agent) pair is allowed to keep at
 *      once. Default 5 matches the dashboard's mental model ("keep the
 *      last 5 voice clips per agent per user"); `0` means "manual cleanup
 *      only" so an operator who wants to disable the auto-purge can do
 *      it without dropping the column. The 0–100 range keeps the purge
 *      loop bounded — a tenant who flips this to 100 won't accidentally
 *      trigger a row-by-row DELETE that pins the DB.
 *
 * Why a CHECK constraint:
 *
 *   The contract (`0..100`) is enforced at the column level on every
 *   supported driver. MySQL 8.0.16+ / MariaDB 10.2.1+ accept an inline
 *   `CHECK (...)` on `ALTER TABLE … ADD COLUMN`; SQLite's table-rebuild
 *   path for `ADD COLUMN` with an inline CHECK would otherwise
 *   reference the new column before it exists, so SQLite receives the
 *   column + CHECK as a single raw statement (same SQL grammar, no
 *   table rebuild).
 *
 * Idempotency:
 *
 *   Both columns and the index gate on `hasColumn` / `indexExists` so
 *   a re-run of this migration over a partially-applied schema is safe.
 */
return new class extends Migration
{
    use \Spora\Core\Database\MigrationHelpers;

    public function up(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        if (!$schema->hasColumn('media_assets', 'is_temporary')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->boolean('is_temporary')->default(false)->after('upload_source');
            });
        }

        if (!$this->indexExists('media_assets', 'idx_media_assets_user_agent_temp')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->index(
                    ['user_id', 'agent_id', 'is_temporary', 'created_at'],
                    'idx_media_assets_user_agent_temp',
                );
            });
        }

        if (!$schema->hasColumn('agents', 'voice_message_retention_count')) {
            // Driver-specific column add: the Laravel Blueprint on
            // SQLite won't emit a CHECK clause on `addColumn`, so we
            // use a raw `ALTER TABLE … ADD COLUMN` with the inline
            // `CHECK (...)` to keep both columns valid out of one SQL
            // statement. MySQL 8.0.16+ / MariaDB 10.2.1+ accept the
            // same shape and the Blueprint path produces an equivalent
            // result without a raw statement.
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $schema->table('agents', static function (Blueprint $t): void {
                    $t->integer('voice_message_retention_count')->default(5)->after('max_retries');
                });
                Capsule::statement(
                    'ALTER TABLE agents ADD CONSTRAINT chk_agents_voice_retention_count '
                    . 'CHECK (voice_message_retention_count >= 0 AND voice_message_retention_count <= 100)',
                );
            } else {
                Capsule::statement(
                    'ALTER TABLE agents ADD COLUMN voice_message_retention_count '
                    . 'INTEGER NOT NULL DEFAULT 5 '
                    . 'CHECK (voice_message_retention_count >= 0 AND voice_message_retention_count <= 100)',
                );
            }
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        $driver = Capsule::connection()->getDriverName();

        // Drop the CHECK constraint before the column on MySQL/MariaDB —
        // SQLite drops the inline CHECK automatically when the column is
        // removed because the CHECK is a property of the column itself,
        // not a standalone constraint.
        if ($driver === 'mysql' || $driver === 'mariadb') {
            Capsule::statement('ALTER TABLE agents DROP CHECK chk_agents_voice_retention_count');
        }

        if ($this->indexExists('media_assets', 'idx_media_assets_user_agent_temp')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropIndex('idx_media_assets_user_agent_temp');
            });
        }

        if ($schema->hasColumn('media_assets', 'is_temporary')) {
            $schema->table('media_assets', static function (Blueprint $t): void {
                $t->dropColumn('is_temporary');
            });
        }

        if ($schema->hasColumn('agents', 'voice_message_retention_count')) {
            $schema->table('agents', static function (Blueprint $t): void {
                $t->dropColumn('voice_message_retention_count');
            });
        }
    }
};