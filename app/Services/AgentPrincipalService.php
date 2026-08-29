<?php

declare(strict_types=1);

namespace Spora\Services;

use PDOException;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Services\Exceptions\DependencyNotWiredException;
use Spora\Tools\HandoverTool;

/**
 * Principal-aware slice of the agent lifecycle: materialising the
 * per-user principal that new agents belong to, and re-keying an
 * existing agent onto a different principal via the transfer endpoint.
 *
 * Split out of {@see AgentService} so the umbrella stays under the
 * SonarCloud S1448 20-method-per-class ceiling. The principal axis is
 * a self-contained responsibility — it talks to `principals` and
 * `PrincipalService::transferAgent()` only — so the boundary is real
 * and not a count-shuffling trait.
 */
final class AgentPrincipalService implements AgentPrincipalServiceInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly ?PrincipalService $principalService = null,
        private readonly ?ToolConfigServiceInterface $toolConfigService = null,
    ) {}

    public function transferAgent(int $agentId, int $targetPrincipalId, int $callerUserId): Agent
    {
        if ($this->principalService === null) {
            throw new DependencyNotWiredException('PrincipalService not wired into AgentPrincipalService — cannot transfer.');
        }

        $agent = $this->principalService->transferAgent($agentId, $targetPrincipalId, $callerUserId);

        // After a successful transfer the agent's principal_id may have
        // changed; any per-agent override on HandoverTool holds a
        // `allowed_target_agents` list that the operator picked before
        // the transfer — those ids now belong to the OLD principal and
        // would be rejected by HandoverTool::sharePrincipal() at runtime.
        // Prune them up-front so the LLM-facing tool definition reflects
        // the new principal scope immediately, instead of silently
        // failing every handover until the operator notices.
        //
        // No-op when the transfer was a no-op (source == target
        // principal_id) — `pruneAgentOverrideByPrincipal` is a no-op
        // itself when the agent's override doesn't reference any
        // cross-principal ids, so the cost is one SELECT.
        if ($this->toolConfigService !== null) {
            $this->toolConfigService->pruneAgentOverrideByPrincipal(
                HandoverTool::class,
                (int) $agent->id,
                $targetPrincipalId,
            );
        }

        return $agent;
    }

    /**
     * Resolve the principal id for a new agent when the caller did not
     * supply one explicitly. Prefers the wired `PrincipalService`; falls
     * back to an inline idempotent insert for legacy test paths that
     * construct {@see AgentService} directly without DI.
     */
    public function resolveDefaultPrincipalId(int $userId): int
    {
        if ($this->principalService !== null) {
            return (int) $this->principalService->ensureUserPrincipal($userId)->id;
        }

        return $this->materialiseLegacyUserPrincipal($userId);
    }

    private function materialiseLegacyUserPrincipal(int $userId): int
    {
        $existing = $this->findLegacyUserPrincipal($userId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->insertLegacyUserPrincipal($userId);
        } catch (PDOException) {
            return $this->recoverLegacyUserPrincipal($userId);
        }
    }

    private function findLegacyUserPrincipal(int $userId): ?int
    {
        $existing = \Illuminate\Database\Capsule\Manager::table('principals')
            ->where('type', Principal::TYPE_USER)
            ->where('user_id', $userId)
            ->value('id');

        return $existing !== null ? (int) $existing : null;
    }

    private function insertLegacyUserPrincipal(int $userId): int
    {
        return (int) \Illuminate\Database\Capsule\Manager::table('principals')->insertGetId([
            'type'       => Principal::TYPE_USER,
            'user_id'    => $userId,
            'created_at' => date(self::DATETIME_FORMAT),
            'updated_at' => date(self::DATETIME_FORMAT),
        ]);
    }

    private function recoverLegacyUserPrincipal(int $userId): int
    {
        $existing = $this->findLegacyUserPrincipal($userId);
        if ($existing !== null) {
            return $existing;
        }
        throw new Exceptions\PrincipalMaterialisationException(
            'PrincipalService not wired — could not materialise a user-principal.',
        );
    }
}
