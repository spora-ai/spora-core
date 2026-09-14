<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Introduce the temp-file media lifecycle **and** the agents-side
 * column for the new speech-to-text cascade.
 *
 * Temp-file lifecycle:
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
 * Speech-cascade tier-1:
 *
 *   3. `agents.speech_driver_config_id` — tier-1 of the new five-tier
 *      speech cascade. Mirrors `agents.llm_driver_config_id` (same
 *      column shape: nullable unsigned bigint). The
 *      {@see \Spora\Speech\SpeechToTextRegistry::resolveEffectiveClassWithSource()}
 *      reads this column directly and uses it as the highest-priority
 *      source for the agent's effective STT class.
 *
 *      The FK to `speech_provider_configurations(id) ON DELETE SET NULL`
 *      is added in migration 0082 — the referenced table doesn't exist
 *      yet at this point. We add the column here (nullable, indexed)
 *      so 0082 can layer the FK on top in a single
 *      `Schema::table('agents', …)` call. Splitting the column add from
 *      the FK add is the only way to land both before any code starts
 *      reading the column: 0081 → 0082 is a single transactional step
 *      from the agent-table's perspective, but two migration files so
 *      the table creation and its first consumer land in their natural
 *      order.
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
 * Backfill:
 *
 *   The column is added with `DEFAULT 5`, but a default only applies
 *   to rows inserted after the column lands. Agents that existed
 *   before this migration ran keep `NULL` until something writes the
 *   column — and `enforceTempRetention()` would treat that as the
 *   "manual cleanup only" branch via the `?? 0` fallback, which would
 *   silently opt every pre-0081 agent out of the auto-purge. The
 *   explicit `UPDATE … WHERE voice_message_retention_count IS NULL`
 *   applies the default to the legacy rows so the policy is consistent
 *   across the whole table the moment the migration completes.
 *
 * Idempotency:
 *
 *   Both columns and the index gate on `hasColumn` / `indexExists` so
 *   a re-run of this migration over a partially-applied schema is safe.
 *   The backfill is unconditional but idempotent: a re-run on an
 *   already-populated column finds no NULL rows and is a no-op.
 */
return new class extends Migration {
    use Spora\Core\Database\MigrationHelpers;

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

        // Backfill pre-existing agents with the column default. See
        // the class-level note: without this, the legacy rows would
        // surface `voice_message_retention_count = NULL` to the
        // service, which the `?? 0` fallback would interpret as
        // "operator opted out of auto-purge" — silent data drift.
        Capsule::table('agents')
            ->whereNull('voice_message_retention_count')
            ->update(['voice_message_retention_count' => 5]);

        if (!$schema->hasColumn('agents', 'speech_driver_config_id')) {
            // See class docblock: the FK to speech_provider_configurations
            // is added in migration 0082 (the table doesn't exist yet
            // here). We add the column + index now so 0082 can layer the
            // FK on top of an existing column without an ALTER-then-FK
            // dance that SQLite doesn't always honour.
            $schema->table('agents', static function (Blueprint $t): void {
                $t->unsignedBigInteger('speech_driver_config_id')->nullable()->after('llm_driver_config_id');
                $t->index('speech_driver_config_id', 'idx_agents_speech_driver_config_id');
            });
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

        if ($schema->hasColumn('agents', 'speech_driver_config_id')) {
            // Drop the index first — SQLite won't drop an index
            // implicitly tied to a column remove on every engine
            // version. The FK on this column was added in migration
            // 0082 and is dropped by 0082's `down()` before this
            // runs, so by the time we reach this branch the column is
            // unconstrained.
            if ($this->indexExists('agents', 'idx_agents_speech_driver_config_id')) {
                $schema->table('agents', static function (Blueprint $t): void {
                    $t->dropIndex('idx_agents_speech_driver_config_id');
                });
            }
            $schema->table('agents', static function (Blueprint $t): void {
                $t->dropColumn('speech_driver_config_id');
            });
        }
    }
};
