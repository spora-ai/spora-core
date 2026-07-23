<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill `media_assets.upload_source` to `'tool'` for rows that pre-date
 * migration 0056 (which added the column nullable) or otherwise landed as
 * NULL. Pre-migration tool rows have no `upload_source` value, and the
 * Media Archive cannot tell them apart from new tool rows without this
 * backfill — they would silently disappear from `?source=tool` and the
 * MediaPickerOverlay "Generated" mode.
 *
 * Forward-only. The down() no-op is intentional: undoing the backfill
 * would re-hide those rows and require a manual UPDATE again, so we
 * refuse to run it. Mirrors the rationale in
 * 0058_add_agent_favorite.php — see that file's docblock for the same
 * forward-only pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('media_assets')
            || !$schema->hasColumn('media_assets', 'upload_source')) {
            return;
        }

        // Single statement, guarded. Idempotent: a second run matches zero
        // NULL rows. The `upload_source` index already covers the WHERE
        // (`upload_source`, `created_at`), so the planner walks the NULL
        // prefix and updates the matching slice without a full table scan.
        Capsule::connection()
            ->table('media_assets')
            ->whereNull('upload_source')
            ->update(['upload_source' => 'tool']);
    }

    /**
     * Forward-only — see class docblock. The method is kept (rather than
     * removed) so the migrator's reflection-based rollback contract is
     * unchanged.
     */
    public function down(): void
    {
        // Forward-only — see class docblock.
    }
};
