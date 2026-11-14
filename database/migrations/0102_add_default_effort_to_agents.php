<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * `agents.default_effort` — the reasoning effort the agent runs with when
 * nobody names one.
 *
 * Nullable, and null means "inherit": the effective LLM configuration's
 * own default applies. The column holds a level name, never a provider's
 * spelling of it — the driver maps the name onto the wire.
 *
 * The width matches `tasks.effort` and the level-name cap: the value
 * travels through both, and widening a VARCHAR is a full table rebuild on
 * SQLite.
 *
 * Idempotency: gated on `hasColumn()`, so a re-run after a partial failure
 * is a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('agents') || $schema->hasColumn('agents', 'default_effort')) {
            return;
        }

        $schema->table('agents', static function (Blueprint $table): void {
            $table->string('default_effort', 16)->nullable()->after('max_steps');
        });
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('agents') || !$schema->hasColumn('agents', 'default_effort')) {
            return;
        }

        $schema->table('agents', static function (Blueprint $table): void {
            $table->dropColumn('default_effort');
        });
    }
};
