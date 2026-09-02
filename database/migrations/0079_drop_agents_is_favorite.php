<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Drop the legacy `agents.is_favorite` column.
 *
 * Plan A final step. The user-owned pivot (`user_agent_favorites`) is
 * now the source of truth, backfilled in migration 0078. The shared
 * column was the bug — every group member saw one toggle — so removing
 * it is the actual fix.
 *
 * `down()` recreates the column with `default(false)` so a partial
 * rollback is recoverable. Roll back the backfill migration first to
 * repopulate the column; rolling back just this migration would leave
 * a default-false column with no per-user preference data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('agents', function (Blueprint $table): void {
            $table->dropColumn('is_favorite');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('agents', function (Blueprint $table): void {
            $table->boolean('is_favorite')->default(false);
        });
    }
};
