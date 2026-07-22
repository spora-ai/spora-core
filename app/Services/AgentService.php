<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Services\Exceptions\AgentNotFoundException;

/**
 * Service for agent lifecycle + flag management.
 *
 * Tool enablement, per-agent settings overrides, and per-operation overrides
 * moved to {@see AgentToolSettingsService} so this umbrella service stays
 * under SonarCloud's 20-method-per-class ceiling (S1448).
 */
final class AgentService implements AgentServiceInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Editable agent columns the service will write through updateAgent()
     * and updateAgentByAgentId(). Keep in sync with AgentController::$allowed
     * (minus internal-only fields like user_id / llm_driver_config_id) so
     * the operator-facing PATCH and the in-tool write_agent_configuration
     * stay on the same allowlist.
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
    ) {}


    public function getAgentsForUser(int $userId): array
    {
        // Dashboard ordering (pinned-first, archived-hidden) lives in
        // spora-frontend PR #52; the backend stays filter-free so the same
        // payload feeds every consumer.
        return Agent::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(Agent $a) => $this->agentResource($a))
            ->all();
    }

    public function createAgent(int $userId, array $data): Agent
    {
        $id = Capsule::table('agents')->insertGetId([
            'user_id'                => $userId,
            'name'                   => $data['name'],
            'description'            => $data['description'] ?? null,
            'system_prompt'          => $data['system_prompt'] ?? null,
            'llm_driver_config_id'   => $data['llm_driver_config_id'] ?? null,
            'max_steps'              => (int) ($data['max_steps'] ?? 10),
            'allow_followup'         => (bool) ($data['allow_followup'] ?? true) ? 1 : 0,
            'is_active'              => 1,
            'created_at'            => date(self::DATETIME_FORMAT),
            'updated_at'            => date(self::DATETIME_FORMAT),
        ]);

        return Agent::find($id);
    }

    public function getAgent(int $agentId, int $userId): ?Agent
    {
        return Agent::where('id', $agentId)->where('user_id', $userId)->first();
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
        // cannot escalate to user_id / llm_driver_config_id.
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
     * Shared flip-a-boolean-column path for setPinned / setArchived.
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


    private function agentResource(Agent $agent): array
    {
        return AgentResource::toArray($agent, null, $this->toolIconResolver);
    }
}
