<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Create `user_agent_favorites` — a per-user, per-agent favourite toggle.
 *
 * Plan A from dashboard-and-subagent-fixes.md. Replaces the shared
 * `agents.is_favorite` column (migration 0058) which leaked across every
 * group member: any member of a group could flip the toggle and every
 * other member saw the change. The user-owned pivot makes favourites
 * private to each user.
 *
 * Schema is intentionally minimal — no surrogate id, no updated_at
 * (favouriting is an event, not a tracked state machine). The composite
 * PK enforces uniqueness; the per-agent index supports the dashboard's
 * "show all users who favourited this agent" lookup if it ever lands.
 *
 * The follow-up migration (0078) backfills from the old column and the
 * final migration (0079) drops the legacy `agents.is_favorite` column.
 * This three-step split makes each step independently reversible: rolling
 * back the drop leaves both columns populated, rolling back the backfill
 * leaves the pivot populated, rolling back the create leaves nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('user_agent_favorites', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('agent_id');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'agent_id']);
            $table->index('agent_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('agents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('user_agent_favorites');
    }
};
