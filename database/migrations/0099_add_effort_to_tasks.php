<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * `tasks.effort` — the reasoning effort the turn was sent with.
 *
 * Nullable, and null means "send nothing": the provider's own default
 * applies. The column holds the *resolved* effort, not the one the request
 * asked for — the resolution (request → configuration default → nothing)
 * happens when the task row is written, so the tick reads a final answer and
 * never consults the configuration again.
 *
 * The value is a canonical level (`none`, `low`, `high`, …), never a
 * provider's spelling of it: the driver maps the canonical name onto the
 * wire.
 *
 * Idempotency: gated on `hasColumn()`, so a re-run after a partial failure
 * is a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('tasks') || $schema->hasColumn('tasks', 'effort')) {
            return;
        }

        $schema->table('tasks', static function (Blueprint $table): void {
            $table->string('effort', 16)->nullable()->after('max_steps');
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('tasks') || !$schema->hasColumn('tasks', 'effort')) {
            return;
        }

        $schema->table('tasks', static function (Blueprint $table): void {
            $table->dropColumn('effort');
        });
    }
};
