<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill default `group_pictures` rows for every existing group.
 *
 * Mirrors {@see 0063_backfill_default_agent_pictures}: the `group_pictures`
 * table is created without a DEFAULT row concept. To keep the dashboard
 * rendering consistent on the day of rollout, every existing group gets
 * a default archetype avatar: `collaborative / null / slate` (same defaults
 * {@see \Spora\Services\ProfilePictures\GroupPictureService} uses when
 * `getOrCreate` returns no row).
 *
 * Idempotent: the `(group_id)` unique key on `group_pictures` guarantees
 * only one row per group. We use `insertOrIgnore` semantics via a
 * pre-fetch of existing group_ids so the operator can re-run this
 * migration without breaking the unique constraint.
 *
 * Forward-only: `down()` is a no-op — removing backfilled rows would
 * wipe the default pictures.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('group_pictures') || !$schema->hasTable('groups')) {
            return;
        }

        $existingGroupIds = Capsule::table('group_pictures')->pluck('group_id')->all();
        $existing = array_flip(array_map(static fn ($id): string => (string) $id, $existingGroupIds));

        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach (Capsule::table('groups')->select('id')->get() as $group) {
            if (isset($existing[(string) $group->id])) {
                continue;
            }
            $rows[] = [
                'group_id'       => (int) $group->id,
                'archetype'      => 'collaborative',
                'variant_key'    => null,
                'palette_key'    => 'slate',
                'media_asset_id' => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        if ($rows !== []) {
            Capsule::table('group_pictures')->insert($rows);
        }
    }

    /**
     * Intentional no-op. See the class docblock.
     */
    public function down(): void
    {
        // Forward-only — see class docblock.
    }
};
