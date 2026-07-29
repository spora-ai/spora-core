<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Backfill default `agent_pictures` rows for every existing agent.
 *
 * The agent_pictures table is created without a `default` row — there is
 * no `DEFAULT` row concept, only per-agent rows. To keep the dashboard
 * rendering consistent on the day of rollout, every existing agent gets
 * a default archetype avatar: `assistant / v0 / slate` (the same defaults
 * {@see \Spora\Services\AgentPictures\AgentPictureService::DEFAULTS}
 * uses when read returns no row).
 *
 * Idempotent: the `(agent_id)` unique key on `agent_pictures` guarantees
 * only one row per agent. We use `insertOrIgnore` semantics via a
 * pre-fetch of existing agent_ids so the operator can re-run this
 * migration without breaking the unique constraint.
 *
 * Forward-only: `down()` is a no-op — removing backfilled rows would
 * wipe the default pictures (the dashboard would then fall back to the
 * legacy initial-letter circle, which is the pre-feature behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable('agent_pictures') || !$schema->hasTable('agents')) {
            return;
        }

        $existingAgentIds = Capsule::table('agent_pictures')->pluck('agent_id')->all();
        $existing = array_flip(array_map(static fn ($id): string => (string) $id, $existingAgentIds));

        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach (Capsule::table('agents')->select('id')->get() as $agent) {
            if (isset($existing[(string) $agent->id])) {
                continue;
            }
            $rows[] = [
                'agent_id'      => (int) $agent->id,
                'archetype'     => 'assistant',
                'variant_key'   => null,
                'palette_key'   => 'slate',
                'media_asset_id' => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if ($rows !== []) {
            Capsule::table('agent_pictures')->insert($rows);
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
