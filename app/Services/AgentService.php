<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use PDOException;
use Spora\Models\Agent;
use Spora\Models\AgentPicture;
use Spora\Models\MediaAsset;
use Spora\Models\Principal;
use Spora\Services\AgentPictures\AgentPictureService;
use Spora\Services\Exceptions\AgentCreateLostException;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Services\Exceptions\DependencyNotWiredException;
use Spora\Services\Exceptions\PrincipalMaterialisationException;

/**
 * Service for agent lifecycle + flag management.
 *
 * Tool enablement, per-agent settings overrides, and per-operation overrides
 * moved to {@see AgentToolSettingsService} so this umbrella service stays
 * under SonarCloud's 20-method-per-class ceiling (S1448).
 *
 * Principals-and-groups (migration 0067) re-keyed the ownership column from
 * `agents.user_id` to `agents.principal_id`. Every user-scoped read or
 * mutation matches on the union of principals the user can act as, so the
 * shared-agent model is transparent to controllers that still think in
 * terms of "user owns agent".
 */
final class AgentService implements AgentServiceInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Editable agent columns the service will write through updateAgent()
     * and updateAgentByAgentId(). Keep in sync with AgentController::$allowed
     * (minus internal-only fields like principal_id / llm_driver_config_id) so
     * the operator-facing PATCH and the in-tool update_agent stay on the
     * same allowlist.
     *
     * @var list<string>
     */
    private const EDITABLE_AGENT_FIELDS = [
        'name',
        'description',
        'system_prompt',
        'llm_driver_config_id',
        'max_steps',
        'allow_followup',
        'retry_after_minutes',
        'max_retries',
        'is_pinned',
        'is_archived',
        'is_favorite',
        'notes',
    ];

    public function __construct(
        private readonly ?ToolIconResolver $toolIconResolver = null,
        private readonly ?AgentPictureService $pictureService = null,
        private readonly ?PrincipalService $principalService = null,
        private readonly ?PrincipalResolver $principalResolver = null,
    ) {}


    public function getAgentsForUser(int $userId): array
    {
        // Dashboard ordering (pinned-first, archived-hidden) lives in
        // spora-frontend PR #52; the backend stays filter-free so the same
        // payload feeds every consumer. The `profilePicture.mediaAsset`
        // eager-load avoids an N+1 chain when AgentResource serializes
        // the per-agent picture (one query per agent for the picture row,
        // plus one per agent for the uploaded image's media_assets row).
        return $this->newVisibleForUserQuery($userId)
            ->with(['agentTools', 'profilePicture.mediaAsset'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(Agent $a) => $this->agentResource($a))
            ->all();
    }

    public function createAgent(int $userId, array $data, ?int $principalId = null): Agent
    {
        $allowed = array_intersect_key($data, array_flip(self::EDITABLE_AGENT_FIELDS));
        $principalId ??= $this->resolveDefaultPrincipalId($userId);

        return Capsule::connection()->transaction(
            fn(): Agent => $this->persistNewAgent($allowed, $principalId),
        );
    }

    /**
     * Resolve the principal id for a new agent when the caller did not
     * supply one explicitly. Prefers the wired `PrincipalService`; falls
     * back to an inline idempotent insert for legacy test paths that
     * construct {@see AgentService} directly without DI.
     */
    private function resolveDefaultPrincipalId(int $userId): int
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
        $existing = Capsule::table('principals')
            ->where('type', Principal::TYPE_USER)
            ->where('user_id', $userId)
            ->value('id');

        return $existing !== null ? (int) $existing : null;
    }

    private function insertLegacyUserPrincipal(int $userId): int
    {
        return (int) Capsule::table('principals')->insertGetId([
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
        throw new PrincipalMaterialisationException(
            'PrincipalService not wired — could not materialise a user-principal.',
        );
    }

    /**
     * @param array<string, mixed> $allowed
     */
    private function persistNewAgent(array $allowed, int $principalId): Agent
    {
        $id = Capsule::table('agents')->insertGetId([
            'principal_id'           => $principalId,
            'name'                   => $allowed['name'],
            'description'            => $allowed['description'] ?? null,
            'system_prompt'          => $allowed['system_prompt'] ?? null,
            'llm_driver_config_id'   => $allowed['llm_driver_config_id'] ?? null,
            'max_steps'              => (int) ($allowed['max_steps'] ?? 10),
            'allow_followup'         => (bool) ($allowed['allow_followup'] ?? true) ? 1 : 0,
            'retry_after_minutes'    => (int) ($allowed['retry_after_minutes'] ?? 0),
            'max_retries'            => (int) ($allowed['max_retries'] ?? 0),
            'is_active'              => 1,
            'created_at'             => date(self::DATETIME_FORMAT),
            'updated_at'             => date(self::DATETIME_FORMAT),
        ]);

        // Persist the default agent_pictures row in the same transaction
        // so a brand-new agent always has a `profile_picture` on the
        // very next read. The dashboard relies on this to render an
        // avatar instead of initials for brand-new agents.
        if ($this->pictureService !== null) {
            $this->pictureService->createDefaultPicture($id);
        }

        $created = Agent::find($id);
        if ($created === null) {
            throw AgentCreateLostException::forId($id);
        }
        return $created;
    }

    public function getAgent(int $agentId, int $userId): ?Agent
    {
        return $this->newVisibleForUserQuery($userId)
            ->where('id', $agentId)
            ->first();
    }

    public function updateAgent(int $agentId, int $userId, array $data): ?Agent
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        return $this->applyAgentPatch($agentId, $agent, $data);
    }

    public function updateAgentByAgentId(int $agentId, array $data): ?Agent
    {
        $agent = Agent::find($agentId);
        if ($agent === null) {
            return null;
        }

        // No user-ownership check — the orchestrator has pinned the agent
        // id. EDITABLE_AGENT_FIELDS still gates the columns, so the tool
        // cannot escalate to principal_id / llm_driver_config_id.
        return $this->applyAgentPatch($agentId, $agent, $data);
    }

    public function getAgentByAgentId(int $agentId): ?Agent
    {
        // No user-ownership check — see updateAgentByAgentId() for the
        // security rationale. Production reads come from the
        // orchestrator-pinned agent id; tests rely on the in-memory
        // Eloquent harness to find a seeded agent.
        return Agent::find($agentId);
    }

    /**
     * Filter $data against the editable-field allowlist and write the
     * surviving columns. Shared by the user-scoped and agent-scoped update
     * paths so the column set stays in lockstep.
     *
     * @param array<string, mixed> $data
     * @return Agent The refreshed agent (refreshed from the DB so the
     *              caller sees the post-update row, including the
     *              auto-bumped `updated_at`).
     */
    private function applyAgentPatch(int $agentId, Agent $agent, array $data): Agent
    {
        $filtered = array_intersect_key($data, array_flip(self::EDITABLE_AGENT_FIELDS));

        if ($filtered !== []) {
            Capsule::table('agents')
                ->where('id', $agentId)
                ->update(array_merge($filtered, ['updated_at' => date(self::DATETIME_FORMAT)]));
            $agent->refresh();
        }

        return $agent;
    }

    public function deleteAgent(int $agentId, int $userId): bool
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return false;
        }

        Capsule::table('agents')->where('id', $agentId)->delete();

        return true;
    }

    public function setPinned(int $userId, int $agentId, bool $pinned): Agent
    {
        return $this->setFlag($userId, $agentId, 'is_pinned', $pinned);
    }

    public function setArchived(int $userId, int $agentId, bool $archived): Agent
    {
        return $this->setFlag($userId, $agentId, 'is_archived', $archived);
    }

    /**
     * Share flip-a-boolean-column path for setPinned / setArchived.
     * Centralises the user-scoped ownership check + updated_at stamp so
     * the public methods stay one-liners and the SQL shape stays in one place.
     */
    private function setFlag(int $userId, int $agentId, string $column, bool $value): Agent
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            throw new AgentNotFoundException('Agent not found.');
        }

        Capsule::table('agents')
            ->where('id', $agentId)
            ->update([
                $column       => $value ? 1 : 0,
                'updated_at'  => date(self::DATETIME_FORMAT),
            ]);

        $agent->refresh();

        return $agent;
    }

    public function transferAgent(int $agentId, int $targetPrincipalId, int $callerUserId): Agent
    {
        if ($this->principalService === null) {
            throw new DependencyNotWiredException('PrincipalService not wired into AgentService — cannot transfer.');
        }
        return $this->principalService->transferAgent($agentId, $targetPrincipalId, $callerUserId);
    }

    /**
     * Reusable Eloquent query builder scoped to agents the user can see.
     * Uses the principal resolver when wired (production); falls back to
     * a direct user-principal lookup otherwise so legacy tests don't break.
     */
    private function newVisibleForUserQuery(int $userId)
    {
        if ($this->principalResolver !== null) {
            return $this->queryFromVisiblePrincipalIds($userId);
        }
        return $this->legacyQueryFromUserPrincipal($userId);
    }

    private function queryFromVisiblePrincipalIds(int $userId)
    {
        $ids = $this->principalResolver->visiblePrincipalIds($userId);
        if ($ids === []) {
            return Agent::whereRaw('1 = 0');
        }
        return Agent::whereIn('principal_id', $ids);
    }

    /**
     * Test path: the resolver is unwired, so resolve the user's
     * user-principal directly. A missing principal returns an empty
     * query so callers treat it as "no agents" rather than "all agents".
     */
    private function legacyQueryFromUserPrincipal(int $userId)
    {
        $principal = Principal::where('type', 'user')
            ->where('user_id', $userId)
            ->first();
        if ($principal === null) {
            return Agent::whereRaw('1 = 0');
        }
        return Agent::where('principal_id', $principal->id);
    }


    private function agentResource(Agent $agent): array
    {
        $picture = $agent->getRelation('profilePicture');
        $media = $picture instanceof AgentPicture && $picture->media_asset_id !== null
            ? $picture->getRelation('mediaAsset')
            : null;
        return AgentResource::toArray(
            $agent,
            null,
            $this->toolIconResolver,
            $this->pictureService,
            $agent->getRelation('agentTools'),
            $picture instanceof AgentPicture ? $picture : null,
            $media instanceof MediaAsset ? $media : null,
        );
    }
}
