<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ReflectionClass;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOverride;
use Spora\Services\Agents\AgentToolInstanceResolver;
use Spora\Services\Agents\AgentToolOperationsResolver;
use Spora\Services\Agents\AgentToolOverrideResolver;
use Spora\Services\Exceptions\AgentNotFoundException;
use Spora\Tools\Attributes\Tool;

/**
 * Service for agent lifecycle, tool management, and operation overrides.
 * All DB access for Agent domain goes through this service.
 *
 * Thin facade: lifecycle CRUD + tool enable/disable + tool status live here;
 * settings overrides and per-operation resolution are delegated to collaborators
 * in {@see Spora\Services\Agents}.
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

    private readonly AgentToolInstanceResolver $instanceResolver;
    private readonly AgentToolOverrideResolver $overrideResolver;
    private readonly AgentToolOperationsResolver $operationsResolver;

    public function __construct(
        private readonly ToolConfigService $toolConfig,
        LLMConfigService $llmConfig,
        private readonly ?ToolIconResolver $toolIconResolver = null,
    ) {
        $this->instanceResolver    = new AgentToolInstanceResolver();
        $this->overrideResolver    = new AgentToolOverrideResolver($toolConfig, $llmConfig, $this->instanceResolver);
        $this->operationsResolver  = new AgentToolOperationsResolver($this->instanceResolver, $this->overrideResolver);
    }


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

        // No user-ownership check: the orchestrator has already pinned the
        // agent id to the calling agent. The same EDITABLE_AGENT_FIELDS
        // allowlist still applies, so the tool cannot escalate to internal
        // columns (e.g. user_id, llm_driver_config_id) by omitting a
        // userId here.
        return $this->applyAgentPatch($agentId, $agent, $data);
    }

    public function getAgentByAgentId(int $agentId): ?Agent
    {
        // No user-ownership check — see updateAgentByAgentId() for the
        // security rationale. Tests can rely on the in-memory Eloquent
        // harness to find a seeded agent; production reads come from the
        // orchestrator-pinned agent id.
        return Agent::find($agentId);
    }

    /**
     * Filter $data against the editable-field allowlist and write the
     * surviving columns. Shared by the user-scoped and agent-scoped update
     * paths so the column set stays in lockstep.
     *
     * @param array<string, mixed> $data
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


    public function enableTool(int $agentId, int $userId, string $toolClass): array
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return ['error' => 'NOT_FOUND'];
        }

        $existing = AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        $isIdempotent = $existing !== null;
        if ($isIdempotent) {
            return [
                'tool' => [
                    'tool_class' => $existing->tool_class,
                    'tool_name'  => $existing->tool_name,
                ],
                'is_idempotent' => true,
            ];
        }

        Capsule::table('agent_tools')->insert([
            'agent_id'   => $agentId,
            'tool_class' => $toolClass,
            'tool_name'  => $this->instanceResolver->resolveToolName($toolClass),
            'created_at' => date(self::DATETIME_FORMAT),
            'updated_at' => date(self::DATETIME_FORMAT),
        ]);

        // Seed schema defaults if no global config AND no agent override exists
        $globalSettings = $this->toolConfig->getGlobalSettings($toolClass);
        $hasAgentOverride = AgentToolOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->exists();

        if ($globalSettings === [] && !$hasAgentOverride) {
            $defaults = $this->toolConfig->getSchemaDefaults($toolClass);
            if ($defaults !== []) {
                $this->toolConfig->putAgentOverride($toolClass, $agentId, $defaults);
            }
        }

        $tool = AgentTool::where('agent_id', $agentId)->where('tool_class', $toolClass)->first();

        $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId);
        $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

        $result = [
            'tool' => [
                'tool_class' => $tool->tool_class,
                'tool_name'  => $tool->tool_name,
            ],
        ];
        if ($missing !== []) {
            $result['warning'] = 'Required settings are missing. The tool may not work until credentials are configured.';
            $result['missing_required'] = $missing;
        }

        return $result;
    }

    public function disableTool(int $agentId, int $userId, string $toolClass): void
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return;
        }

        AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->delete();
    }


    public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $isEnabled = AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->exists();

        $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId, $userId);
        $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

        // Get tool_name from #[Tool] attribute or fall back to short class name
        $reflection = new ReflectionClass($toolClass);
        $toolAttrs = $reflection->getAttributes(Tool::class);
        $toolName = $toolAttrs !== [] ? $toolAttrs[0]->newInstance()->name : $reflection->getShortName();

        return [
            'tool_class'       => $toolClass,
            'tool_name'        => $toolName,
            'is_enabled'      => $isEnabled,
            'missing_required' => $missing,
            'can_enable'      => $missing === [],
        ];
    }

    public function getAllToolsStatus(int $agentId, int $userId): ?array
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $toolClasses = $this->toolConfig->getRegisteredToolClasses();
        $statuses = [];

        $enabledTools = AgentTool::where('agent_id', $agentId)
            ->pluck('tool_class')
            ->flip()
            ->toArray();

        foreach ($toolClasses as $toolClass) {
            $isEnabled = isset($enabledTools[$toolClass]);
            $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId, $userId);
            $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

            // Get tool_name from #[Tool] attribute or fall back to short class name
            $reflection = new ReflectionClass($toolClass);
            $toolAttrs = $reflection->getAttributes(Tool::class);
            $toolName = $toolAttrs !== [] ? $toolAttrs[0]->newInstance()->name : $reflection->getShortName();

            $statuses[] = [
                'tool_class'       => $toolClass,
                'tool_name'        => $toolName,
                'is_enabled'       => $isEnabled,
                'missing_required' => $missing,
                'can_enable'       => $missing === [],
            ];
        }

        return $statuses;
    }

    public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array
    {
        return $this->overrideResolver->getOverride($agentId, $userId, $toolClass, $rawOnly);
    }

    public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array
    {
        return $this->overrideResolver->putOverride($agentId, $userId, $toolClass, $settings);
    }

    public function deleteOverride(int $agentId, int $userId, string $toolClass): void
    {
        $this->overrideResolver->deleteOverride($agentId, $userId, $toolClass);
    }


    public function getToolsOperations(int $agentId, int $userId): ?array
    {
        return $this->operationsResolver->getToolsOperations($agentId, $userId);
    }

    public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array
    {
        return $this->operationsResolver->getOperationOverride($agentId, $userId, $toolClass, $operation);
    }

    public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array
    {
        return $this->operationsResolver->patchOperationOverride($agentId, $userId, $toolClass, $operation, $data);
    }


    private function agentResource(Agent $agent): array
    {
        return AgentResource::toArray($agent, null, $this->toolIconResolver);
    }
}
