<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Support\Collection;
use Spora\Models\Agent;

/**
 * Per-user agent favourites.
 *
 * Split out of {@see AgentServiceInterface} so the umbrella service stays
 * under SonarCloud's 20-method-per-class ceiling (S1448). Plan A replaced
 * the shared `agents.is_favorite` column with a per-user pivot table
 * (migration 0077); this service owns the toggle, the per-visibility
 * check, AND the pre-loader that hydrates
 * `AgentResourceContext.favoritedAgentIds`.
 */
interface AgentFavoriteServiceInterface
{
    /**
     * Mark an agent as a favourite for the calling user. Idempotent —
     * a duplicate call on an already-favourited agent is a no-op.
     *
     * @throws Exceptions\AgentNotFoundException If the agent is not visible to $userId
     */
    public function setFavorite(int $userId, int $agentId): Agent;

    /**
     * Drop the favourite for the calling user. No-op if no row exists.
     *
     * @throws Exceptions\AgentNotFoundException If the agent is not visible to $userId
     */
    public function unsetFavorite(int $userId, int $agentId): Agent;

    /**
     * Pre-load the caller's favourited agent ids in a single query. Returns
     * a `Collection<int, int>` keyed by agent_id so {@see AgentResource}
     * can use `->has($agentId)` for O(1) per-agent lookups. Shared by
     * the list and single-agent endpoints so the per-viewer `is_favorite`
     * field never goes stale.
     *
     * @param  list<int> $agentIds
     * @return Collection<int, int>
     */
    public function loadFavoritedAgentIdsForViewer(int $userId, array $agentIds): Collection;
}
