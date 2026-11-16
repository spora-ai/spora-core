<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * `agent_prompt_templates.effort` and `scheduled_runs.effort_override` —
 * the reasoning effort a saved prompt and a scheduled run carry.
 *
 * Nullable, and null means "inherit": a run's override falls back to its
 * template's effort, and a template's effort falls back to the agent's
 * default. The column holds a level name, never a provider's spelling of
 * it.
 *
 * 16 characters, because `tasks.effort` is already `string(16)` and the
 * level names are capped at that length. Widening the column on SQLite is
 * a full table rebuild of a table that grows without bound.
 *
 * Idempotency: gated on `hasColumn()`, so a re-run after a partial failure
 * is a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('agent_prompt_templates') && !$schema->hasColumn('agent_prompt_templates', 'effort')) {
            $schema->table('agent_prompt_templates', static function (Blueprint $table): void {
                $table->string('effort', 16)->nullable()->after('max_steps');
            });
        }

        if ($schema->hasTable('scheduled_runs') && !$schema->hasColumn('scheduled_runs', 'effort_override')) {
            $schema->table('scheduled_runs', static function (Blueprint $table): void {
                $table->string('effort_override', 16)->nullable()->after('max_steps_override');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();

        if ($schema->hasTable('agent_prompt_templates') && $schema->hasColumn('agent_prompt_templates', 'effort')) {
            $schema->table('agent_prompt_templates', static function (Blueprint $table): void {
                $table->dropColumn('effort');
            });
        }

        if ($schema->hasTable('scheduled_runs') && $schema->hasColumn('scheduled_runs', 'effort_override')) {
            $schema->table('scheduled_runs', static function (Blueprint $table): void {
                $table->dropColumn('effort_override');
            });
        }
    }
};
