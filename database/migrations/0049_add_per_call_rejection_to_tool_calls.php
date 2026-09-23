<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Per-call approve/reject on tool_calls. Every step gates on
 * `hasColumn` / `hasForeignKeyOnColumn` so a re-run over a partial state
 * (e.g. a previous deploy where the `migrations` row wasn't recorded
 * but the columns landed) is a no-op instead of crashing with
 * SQLSTATE 42S21 — the regression that motivated this fix.
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

        // Drop FK by column reference, not by name: Laravel resolves the
        // constraint name on every driver, and the column drop below
        // would otherwise leave a dangling FK on SQLite.
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
