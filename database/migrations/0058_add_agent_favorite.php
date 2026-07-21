<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Add `is_favorite` flag to the `agents` table.
 *
 * Operator-facing toggle: a favorited agent surfaces in its own dashboard
 * section (above recency, below Pinned) regardless of activity, giving
 * quick access to the small set of agents an operator reaches for most
 * often. Defaults to false so the change is backward compatible — every
 * existing agent surfaces un-favorited on rollout.
 *
 * Forward-only: `down()` is a no-op — removing the column would orphan
 * dashboard favorites state. See 0056_add_agent_pin_archive for the
 * identical rationale applied to `is_pinned` / `is_archived`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('agents')) {
            return;
        }

        if (!$schema->hasColumn('agents', 'is_favorite')) {
            $schema->table('agents', static function (Blueprint $table): void {
                $table->boolean('is_favorite')->default(0);
            });
        }
    }

    /**
     * Intentional no-op. See the class docblock: dropping the column would
     * orphan dashboard favorites state. The method is kept (rather than
     * removed) so the migrator's reflection-based rollback contract is
     * unchanged.
     */
    public function down(): void
    {
        // Forward-only — see class docblock.
    }
};
