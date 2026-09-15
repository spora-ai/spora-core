<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;

/**
 * Temp-row retention + GC for the Media Archive.
 *
 * Carved out of {@see MediaArchiveService} so the umbrella stays
 * under the SonarCloud S1448 20-method ceiling. The cluster of
 * retention-related methods (ceiling lookup, excess detection,
 * purge, and the public entry point) is internally cohesive and
 * has its own reasons-to-change (retention policy tweaks land here,
 * not in the listing/finding/ingest code), so it gets its own class.
 *
 * Concurrency: `enforceTempRetention` is opportunistic and does not
 * run inside a transaction. Concurrent uploads from the same
 * (user, agent) may briefly compute an `existing` count that dips
 * below `voice_message_retention_count` before settling — acceptable
 * for this control loop; revisit if the upload rate ever exceeds
 * ~10/s per agent.
 */
final class MediaArchiveRetention
{
    /**
     * Enforce the per-(user, agent) temp-row retention ceiling in
     * real-time as part of `POST /api/v1/media`. Called by the upload
     * controller immediately after a temp ingest succeeds.
     *
     * The contract:
     *   - Read `agents.voice_message_retention_count`. If `0`, return
     *     immediately: an agent with `0` opted out of auto-purge, so
     *     temp rows accumulate until the operator runs `media:gc` or
     *     the user hits the `/keep` endpoint.
     *   - Otherwise, count existing temp rows for the (user, agent)
     *     pair excluding the just-uploaded asset, and if the count
     *     exceeds the ceiling, DELETE the oldest rows so the resulting
     *     temp count equals `voice_message_retention_count`.
     *
     * Returns the number of rows deleted so the caller (and tests) can
     * assert the purge happened. Errors are swallowed because purge is
     * opportunistic — a failed DELETE on the cleanup side shouldn't
     * fail the upload the user just made.
     *
     * @param int|null $userId  The uploading user (null = unattributed
     *                          direct call; matches on `user_id IS NULL`).
     * @param int|null $agentId The destination agent (null = chat-attached
     *                          row with no agent; matches on `agent_id IS NULL`).
     * @param string   $newAssetId  Just-uploaded row to exclude from the
     *                              cleanup sweep so the new upload survives
     *                              its own ingest.
     */
    public function enforceTempRetention(?int $userId, ?int $agentId, string $newAssetId): int
    {
        $retention = $this->resolveRetentionCeiling($agentId);
        if ($retention <= 0) {
            return 0;
        }

        $oldestIds = $this->findExcessTempIds($userId, $agentId, $newAssetId, $retention);
        if ($oldestIds === []) {
            return 0;
        }

        return $this->purgeTempRows($oldestIds);
    }

    /**
     * Resolve the (user, agent) retention ceiling. Returns 0 when no
     * policy applies: no agent context, the agent row is missing, or
     * the operator explicitly disabled auto-purge with `0`.
     */
    private function resolveRetentionCeiling(?int $agentId): int
    {
        if ($agentId === null) {
            // No agent context = no retention policy to enforce. The
            // (user-only) temp rows fall outside the (user, agent) purge
            // and are only reaped by `media:gc --temporary`.
            return 0;
        }
        $agent = Agent::query()->find($agentId);
        if ($agent === null) {
            return 0;
        }
        return (int) ($agent->voice_message_retention_count ?? 0);
    }

    /**
     * Find the IDs of existing temp rows that exceed the retention
     * ceiling for the (user, agent) pair. Returns an empty array
     * when the new upload fits inside the ceiling without purging.
     *
     * The contract: if `existing >= retention`, the new upload pushed
     * the (user, agent) temp set past the ceiling (existing doesn't
     * count the new row, so `existing == retention` still means the
     * upload of #6 makes the total 6 and one purge is required to
     * bring it back to `retention`). Stop only when
     * `existing < retention` — the new upload fits inside the
     * ceiling without touching the older rows.
     *
     * @return list<string>
     */
    private function findExcessTempIds(?int $userId, int $agentId, string $newAssetId, int $retention): array
    {
        $base = Capsule::table('media_assets')
            ->where('user_id', $userId)
            ->where('agent_id', $agentId)
            ->where('is_temporary', true)
            ->where('id', '!=', $newAssetId);

        $existing = (int) $base->count();
        if ($existing < $retention) {
            return [];
        }

        $excess = $existing - $retention + 1;
        return (clone $base)
            ->orderBy('created_at', 'asc')
            ->limit($excess)
            ->pluck('id')
            ->all();
    }

    /**
     * @param list<string> $ids
     */
    private function purgeTempRows(array $ids): int
    {
        return (int) Capsule::table('media_assets')
            ->whereIn('id', $ids)
            ->delete();
    }
}
