<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;
use Spora\Models\UserAgentFavorite;
use Spora\Services\Exceptions\AgentNotFoundException;

/**
 * Per-user agent favourites.
 *
 * Split out of {@see AgentService} so the umbrella service stays under
 * SonarCloud's 20-method-per-class ceiling (S1448). Plan A replaced the
 * shared `agents.is_favorite` column with a per-user pivot table
 * (migration 0077); this service owns the toggle + the per-visibility
 * check. The pre-loader that hydrates `AgentResourceContext.favoritedAgentIds`
 * lives in {@see AgentService::getAgentsForUser()} — it pre-loads the
 * caller's pivot in one query and is too closely coupled to the agent
 * fetch to belong here.
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
}
