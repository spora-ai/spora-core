<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use RuntimeException;
use Spora\Models\Agent;
use Spora\Services\AgentPrincipalServiceInterface;

/**
 * Stub AgentPrincipalServiceInterface that returns canned data. Mirrors
 * the pattern in StubAgentService so AgentTransferControllerTest can
 * exercise the controller without booting the full DI container.
 */
class StubAgentPrincipalService implements AgentPrincipalServiceInterface
{
    public function transferAgent(int $agentId, int $targetPrincipalId, int $callerUserId): Agent
    {
        $agent = new Agent();
        $agent->id = $agentId;
        $agent->principal_id = $targetPrincipalId;
        $agent->name = 'Stub Agent';
        $agent->description = null;
        $agent->system_prompt = null;
        $agent->llm_driver_config_id = null;
        $agent->max_steps = 10;
        $agent->is_active = true;
        $agent->retry_after_minutes = 0;
        $agent->max_retries = 0;
        $agent->is_pinned = false;
        $agent->is_archived = false;

        if ($agentId === 999999) {
            throw new RuntimeException("Agent {$agentId} not found");
        }
        return $agent;
    }

    public function resolveDefaultPrincipalId(int $userId): int
    {
        return createUserPrincipalPublic($userId);
    }
}
