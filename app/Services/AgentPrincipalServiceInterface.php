<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;

/**
 * Interface for the principal-axis slice of the agent lifecycle.
 * {@see AgentPrincipalService} is the production implementation; tests
 * and stub consumers can supply their own implementation without
 * booting the full DI container.
 */
interface AgentPrincipalServiceInterface
{
    public function transferAgent(int $agentId, int $targetPrincipalId, int $callerUserId): Agent;

    public function resolveDefaultPrincipalId(int $userId): int;
}
