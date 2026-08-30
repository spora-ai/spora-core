<?php

declare(strict_types=1);

namespace Spora\Services;

use PDOException;
use Spora\Models\Agent;
use Spora\Models\Principal;
use Spora\Services\Exceptions\DependencyNotWiredException;
use Spora\Tools\HandoverTool;
use Spora\Tools\ToolSettingSchema;

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
        // principal_id) — the prune is a no-op itself when the agent's
        // override doesn't reference any cross-principal ids, so the
        // cost is at most one SELECT.
        $this->pruneHandoverAllowlist((int) $agent->id, $targetPrincipalId);

        return $agent;
    }

    /**
     * Prune the per-agent HandoverTool override so the
     * `allowed_target_agents` list no longer references agents in a
     * principal other than `$newPrincipalId`. Public so the behaviour
     * is unit-testable in isolation; the runtime entry point is
     * {@see transferAgent()}.
     *
     * Returns the number of agent ids removed, or 0 if nothing
     * matched (no override row, no agent-id settings, or every target
     * already shared the new principal).
     */
    public function pruneHandoverAllowlist(int $agentId, int $newPrincipalId): int
    {
        $existing = $this->loadExistingHandoverOverride($agentId);
        if ($existing === null) {
            return 0;
        }

        $agentIdKeys = $this->collectAgentIdSettingKeys(HandoverTool::class);
        if ($agentIdKeys === []) {
            return 0;
        }

        $removed = $this->filterOutCrossPrincipalTargets($existing, $agentIdKeys, $newPrincipalId);
        if ($removed > 0 && $this->toolConfigService !== null) {
            $this->toolConfigService->putAgentOverride(
                HandoverTool::class,
                $agentId,
                $existing,
            );
        }
        return $removed;
    }

    /**
     * Returns the decrypted override row for HandoverTool, or null
     * when there's no row or no `ToolConfigService` wired (test
     * stubs). Treating both empty-row and unwired-service as the
     * same "nothing to do" signal lets {@see pruneHandoverAllowlist()}
     * stay at three returns.
     *
     * @return array<string, mixed>|null
     */
    private function loadExistingHandoverOverride(int $agentId): ?array
    {
        if ($this->toolConfigService === null) {
            return null;
        }
        $existing = $this->toolConfigService->getRawAgentOverride(HandoverTool::class, $agentId);
        return $existing === [] ? null : $existing;
    }

    /**
     * Walk the tool's `#[ToolSetting]` schema and return the keys of
     * every multi-select setting with `resolveAs === 'agent'` (today
     * that's just HandoverTool's `allowed_target_agents`, but new tools
     * can declare the same shape without touching this method).
     *
     * @return list<string>
     */
    private function collectAgentIdSettingKeys(string $toolClass): array
    {
        $keys = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            if ($setting->type === 'multi-select' && $setting->resolveAs === 'agent') {
                $keys[] = $setting->key;
            }
        }
        return $keys;
    }

    /**
     * Filter each agent-id setting in `$existing` down to entries
     * whose `Agent.principal_id === $newPrincipalId`. Mutates `$existing`
     * in place (callers pass it through to `putAgentOverride`).
     *
     * Two short-circuits to keep complexity bounded:
     *   - no referenced agent ids → return 0 without a DB query
     *   - the schema carries no agent-id keys → return 0 immediately
     *
     * @param  array<string, mixed> $existing
     * @param  list<string> $agentIdKeys
     */
    private function filterOutCrossPrincipalTargets(array &$existing, array $agentIdKeys, int $newPrincipalId): int
    {
        $idsToCheck = [];
        foreach ($agentIdKeys as $key) {
            if (!isset($existing[$key]) || !is_array($existing[$key])) {
                continue;
            }
            foreach ($existing[$key] as $id) {
                $idsToCheck[(int) $id] = true;
            }
        }
        if ($idsToCheck === []) {
            return 0;
        }

        $principalsByAgentId = Agent::whereIn('id', array_keys($idsToCheck))
            ->pluck('principal_id', 'id')
            ->all();

        $totalRemoved = 0;
        foreach ($agentIdKeys as $key) {
            if (!isset($existing[$key]) || !is_array($existing[$key])) {
                continue;
            }
            $before = count($existing[$key]);
            $existing[$key] = array_values(array_filter(
                $existing[$key],
                fn(int $id): bool => isset($principalsByAgentId[$id])
                    && (int) $principalsByAgentId[$id] === $newPrincipalId,
            ));
            $totalRemoved += $before - count($existing[$key]);
        }
        return $totalRemoved;
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
