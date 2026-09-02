<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Support\Collection;
use Spora\Models\Agent;
use Spora\Models\UserAgentFavorite;
use Spora\Services\Exceptions\AgentNotFoundException;

/**
 * Per-user agent favourites.
 *
 * Split out of {@see AgentService} so the umbrella service stays under
 * SonarCloud's 20-method-per-class ceiling (S1448). Plan A replaced the
 * shared `agents.is_favorite` column with a per-user pivot table
 * (migration 0077); this service owns the toggle, the per-visibility
 * check, AND the pre-loader that hydrates
 * `AgentResourceContext.favoritedAgentIds`. The pre-loader is shared by
 * the list endpoint (AgentService::getAgentsForUser) and the single-agent
 * endpoints (AgentController::show/store/update + AgentTransferController)
 * so the per-viewer `is_favorite` field never goes stale.
 */
final class AgentFavoriteService implements AgentFavoriteServiceInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const AGENT_NOT_FOUND_MESSAGE = 'Agent not found.';

    public function __construct(
        private readonly AgentServiceInterface $agentService,
    ) {}

    public function setFavorite(int $userId, int $agentId): Agent
    {
        $agent = $this->agentService->getAgent($agentId, $userId);
        if ($agent === null) {
            throw new AgentNotFoundException(self::AGENT_NOT_FOUND_MESSAGE);
        }

        UserAgentFavorite::insertOrIgnore([
            'user_id'    => $userId,
            'agent_id'   => $agentId,
            'created_at' => date(self::DATETIME_FORMAT),
        ]);

        return $agent;
    }

    public function unsetFavorite(int $userId, int $agentId): Agent
    {
        $agent = $this->agentService->getAgent($agentId, $userId);
        if ($agent === null) {
            throw new AgentNotFoundException(self::AGENT_NOT_FOUND_MESSAGE);
        }

        UserAgentFavorite::where('user_id', $userId)
            ->where('agent_id', $agentId)
            ->delete();

        return $agent;
    }

    /**
     * Pre-load the caller's favourited agent ids in one query. Shared by
     * every single-agent endpoint that returns an `AgentResource` so the
     * per-viewer `is_favorite` field is consistent across them — without
     * this, the dashboard's re-fetch after a favourite toggle would
     * always see `is_favorite: false` and the toast would fire the
     * wrong message.
     *
     * Delegates to {@see UserAgentFavorite::loadFavoritedForViewer()} so
     * the SQL lives in one place (the list endpoint and the single-agent
     * endpoints share it without going through this service).
     *
     * @param  list<int> $agentIds
     * @return Collection<int, int>  Keyed by agent_id; presence = favourited.
     */
    public function loadFavoritedAgentIdsForViewer(int $userId, array $agentIds): Collection
    {
        return UserAgentFavorite::loadFavoritedForViewer($userId, $agentIds);
    }
}
