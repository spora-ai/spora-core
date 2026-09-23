<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Per-call approve/reject on tool_calls: rejected_at / rejected_by / reject_reason
 * (PR #173, feat/orchestrator per-call approve/reject).
 *
 * Idempotency:
 *
 *   Every step gates on `hasColumn` / `hasForeignKeyOnColumn` so a re-run over a
 *   partially-applied schema is a no-op. The migration was originally
 *   written before the idempotency contract existed (PR #235 / migration
 *   helpers centralisation), and a production MariaDB instance hit a
 *   partial state — the columns were added but the `migrations` row was
 *   never recorded, so every subsequent `spora:install` re-attempted the
 *   ADD COLUMN and crashed with SQLSTATE 42S21 ("Column already exists:
 *   rejected_at"). Gating each ALTER on the column's presence means a
 *   future re-run over that state silently no-ops instead of failing the
 *   deploy.
 *
 *   The FK name is explicit (`tool_calls_rejected_by_foreign`) so the
 *   existence check is stable across Laravel versions. The check itself
 *   uses `hasForeignKeyOnColumn` rather than `foreignKeyExists` — the
 *   latter assumes the `fk_<table>_<column>` naming convention used by
 *   0084+, but this migration predates that convention and ships the FK
 *   under Laravel's default `<table>_<column>_foreign` name. Probing
 *   by column works regardless of how the FK was named originally.
 */
return new class extends Migration
{
    use Spora\Core\Database\MigrationHelpers;

    private const REJECTED_BY_FK = 'tool_calls_rejected_by_foreign';

    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('tool_calls')) {
            return;
        }

        if (!$schema->hasColumn('tool_calls', 'rejected_at')) {
            $schema->table('tool_calls', static function (Blueprint $table): void {
                $table->timestamp('rejected_at')->nullable();
            });
        }

        if (!$schema->hasColumn('tool_calls', 'rejected_by')) {
            $schema->table('tool_calls', static function (Blueprint $table): void {
                $table->unsignedBigInteger('rejected_by')->nullable();
            });
        }

        if (!$schema->hasColumn('tool_calls', 'reject_reason')) {
            $schema->table('tool_calls', static function (Blueprint $table): void {
                $table->text('reject_reason')->nullable();
            });
        }

        if (!$this->hasForeignKeyOnColumn('tool_calls', 'rejected_by')) {
            $schema->table('tool_calls', static function (Blueprint $table): void {
                $table->foreign('rejected_by', self::REJECTED_BY_FK)
                    ->references('id')->on('users')
                    ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('tool_calls')) {
            return;
        }

        // Drop the FK by column reference rather than by name — Laravel
        // resolves the constraint name from the column on every supported
        // driver, and a no-op when no FK is attached to that column. On
        // MySQL/MariaDB the FK must be dropped before the column; on
        // SQLite dropping the column would otherwise leave a dangling
        // FK in the table definition and the ALTER TABLE fails.
        if ($schema->hasColumn('tool_calls', 'rejected_by')) {
            $schema->table('tool_calls', static function (Blueprint $table): void {
                $table->dropForeign(['rejected_by']);
            });
        }

        if ($schema->hasColumn('tool_calls', 'reject_reason')) {
            $schema->table('tool_calls', static function (Blueprint $table): void {
                $table->dropColumn('reject_reason');
            });
        }

        if ($schema->hasColumn('tool_calls', 'rejected_by')) {
            $schema->table('tool_calls', static function (Blueprint $table): void {
                $table->dropColumn('rejected_by');
            });
        }

        if ($schema->hasColumn('tool_calls', 'rejected_at')) {
            $schema->table('tool_calls', static function (Blueprint $table): void {
                $table->dropColumn('rejected_at');
            });
        }
    }
};
