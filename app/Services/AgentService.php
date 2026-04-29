<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ReflectionClass;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\AgentToolOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Tools\Traits\HasOperations;
use Throwable;

/**
 * Service for agent lifecycle, tool management, and operation overrides.
 * All DB access for Agent domain goes through this service.
 */
final class AgentService implements AgentServiceInterface
{
    public function __construct(
        private readonly ToolConfigService $toolConfig,
        private readonly LLMConfigService $llmConfig,
    ) {}

    // ── Agent lifecycle ─────────────────────────────────────────────────────────

    public function getAgentsForUser(int $userId): array
    {
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
            'is_active'              => 1,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
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

        $allowed = ['name', 'description', 'system_prompt', 'llm_driver_config_id', 'max_steps', 'retry_after_minutes', 'max_retries'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if ($filtered !== []) {
            Capsule::table('agents')
                ->where('id', $agentId)
                ->update(array_merge($filtered, ['updated_at' => date('Y-m-d H:i:s')]));
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

    // ── Tool management ─────────────────────────────────────────────────────────

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
            return ['tool' => $this->toolResource($existing), 'is_idempotent' => true];
        }

        $autoApprove = $this->resolveAutoApproveDefault($toolClass);

        Capsule::table('agent_tools')->insert([
            'agent_id'     => $agentId,
            'tool_class'   => $toolClass,
            'tool_name'    => $this->resolveToolName($toolClass),
            'auto_approve' => $autoApprove,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
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

        $result = ['tool' => $this->toolResource($tool)];
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

    public function patchTool(int $agentId, int $userId, string $toolClass, array $data): ?AgentTool
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $tool = AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->first();

        if ($tool === null) {
            return null;
        }

        if (array_key_exists('auto_approve', $data)) {
            $raw = $data['auto_approve'];
            $dbValue = $raw === null ? null : ($raw ? 1 : 0);

            Capsule::table('agent_tools')
                ->where('id', $tool->id)
                ->update(['auto_approve' => $dbValue, 'updated_at' => date('Y-m-d H:i:s')]);
            $tool->refresh();
        }

        return $tool;
    }

    // ── Tool status & settings ────────────────────────────────────────────────────

    public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $isEnabled = AgentTool::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->exists();

        $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId);
        $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

        return [
            'tool_class'       => $toolClass,
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
            $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId);
            $missing = $this->toolConfig->getMissingRequiredSettings($toolClass, $effective);

            $statuses[] = [
                'tool_class'       => $toolClass,
                'is_enabled'      => $isEnabled,
                'missing_required' => $missing,
                'can_enable'      => $missing === [],
            ];
        }

        return $statuses;
    }

    public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return [];
        }

        // llm_configuration is a special case — no registered tool class
        if ($toolClass === 'llm_configuration') {
            return $this->getLlmConfigurationSettings($userId);
        }

        if ($rawOnly) {
            $settings = $this->toolConfig->getRawAgentOverride($toolClass, $agentId);
            return $this->toolConfig->maskForApi($settings, $toolClass);
        }

        $annotated = $this->toolConfig->getEffectiveSettingsWithSource($toolClass, $agentId);
        $masked = [];

        foreach ($annotated as $key => $item) {
            $passwordKeys = $this->getToolPasswordKeys($toolClass);
            $value = $item['value'];
            if ($value !== null && $value !== '' && in_array($key, $passwordKeys, true)) {
                $value = '***';
            }
            $masked[$key] = [
                'value'  => $value,
                'source' => $item['source'],
            ];
        }

        return $masked;
    }

    public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return [];
        }

        $this->toolConfig->putAgentOverride($toolClass, $agentId, $settings);

        $effective = $this->toolConfig->getEffectiveSettings($toolClass, $agentId);
        return $this->toolConfig->maskForApi($effective, $toolClass);
    }

    public function deleteOverride(int $agentId, int $userId, string $toolClass): void
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return;
        }

        $this->toolConfig->deleteAgentOverride($toolClass, $agentId);
    }

    // ── Operation overrides ─────────────────────────────────────────────────────

    public function getToolsOperations(int $agentId, int $userId): ?array
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $enabledTools = AgentTool::where('agent_id', $agentId)->get();
        $operations = [];

        $overrides = AgentToolOperationOverride::where('agent_id', $agentId)
            ->get()
            ->keyBy(fn($row) => $row->tool_class . '::' . $row->operation);

        foreach ($enabledTools as $tool) {
            if (!class_exists($tool->tool_class)) {
                continue;
            }
            if (!in_array(HasOperations::class, class_uses_recursive($tool->tool_class), true)) {
                continue;
            }

            $instance = $this->resolveToolInstance($tool->tool_class);
            if ($instance === null) {
                continue;
            }

            foreach ($instance->getOperations() as $op) {
                $key = $tool->tool_class . '::' . $op->name;
                $row = $overrides->get($key);

                $operations[] = [
                    'tool_class'                   => $tool->tool_class,
                    'operation'                    => $op->name,
                    'enabled'                      => $this->extractOverrideFlag($row, 'enabled'),
                    'default_requires_approval'    => $this->extractOverrideFlag($row, 'default_requires_approval'),
                    'effective_enabled'            => $this->resolveOperationEffectiveEnabled($tool->tool_class, $op->name, $agentId),
                    'effective_requires_approval'  => $this->resolveOperationEffectiveRequiresApproval($tool->tool_class, $op->name, $agentId),
                ];
            }
        }

        return $operations;
    }

    public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return [];
        }

        $row = AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->where('operation', $operation)
            ->first();

        return [
            'operation'                   => $operation,
            'tool_class'                  => $toolClass,
            'enabled'                      => $this->extractOverrideFlag($row, 'enabled'),
            'default_requires_approval'    => $this->extractOverrideFlag($row, 'default_requires_approval'),
            'effective_enabled'            => $this->resolveOperationEffectiveEnabled($toolClass, $operation, $agentId),
            'effective_requires_approval'  => $this->resolveOperationEffectiveRequiresApproval($toolClass, $operation, $agentId),
        ];
    }

    public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return [];
        }

        $enabled = $this->parseOverrideFlag($data, 'enabled');
        $defaultRequiresApproval = $this->parseOverrideFlag($data, 'default_requires_approval');

        $existing = AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->where('operation', $operation)
            ->first();

        if ($existing !== null) {
            $updateData = [];
            if ($enabled !== null) {
                $updateData['enabled'] = $enabled;
            }
            if ($defaultRequiresApproval !== null) {
                $updateData['default_requires_approval'] = $defaultRequiresApproval;
            }
            if ($updateData !== []) {
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                Capsule::table('agent_tool_operation_overrides')
                    ->where('id', $existing->id)
                    ->update($updateData);
            }
        } else {
            $insertData = [
                'agent_id'    => $agentId,
                'tool_class'  => $toolClass,
                'operation'   => $operation,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
            if ($enabled !== null) {
                $insertData['enabled'] = $enabled;
            }
            if ($defaultRequiresApproval !== null) {
                $insertData['default_requires_approval'] = $defaultRequiresApproval;
            }
            Capsule::table('agent_tool_operation_overrides')->insert($insertData);
        }

        return $this->getOperationOverride($agentId, $userId, $toolClass, $operation);
    }

    // ── Private helpers ─────────────────────────────────────────────────────────

    private function resolveAutoApproveDefault(string $toolClass): ?bool
    {
        if (!class_exists($toolClass)) {
            return null;
        }

        $ref = new ReflectionClass($toolClass);
        if (!in_array(HasOperations::class, class_uses_recursive($toolClass), true)) {
            return null;
        }

        $instance = $ref->newInstanceWithoutConstructor();
        $operations = $instance->getOperations();
        if ($operations === []) {
            return null;
        }

        if (array_all($operations, fn($op) => $op->requiresApprovalByDefault === false)) {
            return true;
        }

        return null;
    }

    private function resolveOperationEffectiveEnabled(string $toolClass, string $operation, int $agentId): bool
    {
        $override = AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->where('operation', $operation)
            ->first();

        if ($override !== null) {
            $raw = $override->getRawOriginal('enabled');
            if ($raw !== null) {
                return (bool) $raw;
            }
        }

        $instance = $this->resolveToolInstance($toolClass);
        if ($instance !== null && in_array(HasOperations::class, class_uses_recursive($toolClass), true)) {
            return $instance->isEnabledByDefault($operation);
        }

        return true;
    }

    private function resolveOperationEffectiveRequiresApproval(string $toolClass, string $operation, int $agentId): bool
    {
        $override = AgentToolOperationOverride::where('agent_id', $agentId)
            ->where('tool_class', $toolClass)
            ->where('operation', $operation)
            ->first();

        if ($override !== null) {
            $raw = $override->getRawOriginal('default_requires_approval');
            if ($raw !== null) {
                return (bool) $raw;
            }
        }

        $instance = $this->resolveToolInstance($toolClass);
        if ($instance !== null && in_array(HasOperations::class, class_uses_recursive($toolClass), true)) {
            return $instance->requiresApprovalByDefault($operation);
        }

        return true;
    }

    private function resolveToolInstance(string $toolClass): ?object
    {
        static $instances = [];
        if (!class_exists($toolClass)) {
            return null;
        }
        if (!isset($instances[$toolClass])) {
            try {
                $instances[$toolClass] = (new ReflectionClass($toolClass))->newInstanceWithoutConstructor();
            } catch (Throwable) {
                return null;
            }
        }
        return $instances[$toolClass];
    }

    private function resolveToolName(string $toolClass): string
    {
        if (!class_exists($toolClass)) {
            return basename(str_replace('\\', '/', $toolClass));
        }

        $reflection = new ReflectionClass($toolClass);
        $attrs = $reflection->getAttributes(\Spora\Tools\Attributes\Tool::class);

        if ($attrs !== []) {
            return $attrs[0]->newInstance()->name;
        }

        return $reflection->getShortName();
    }

    /**
     * @return list<string>
     */
    private function getToolPasswordKeys(string $toolClass): array
    {
        if (!class_exists($toolClass)) {
            return [];
        }

        $keys = [];
        foreach ((new ReflectionClass($toolClass))->getAttributes(\Spora\Tools\Attributes\ToolSetting::class) as $attr) {
            /** @var \Spora\Tools\Attributes\ToolSetting $instance */
            $instance = $attr->newInstance();
            if ($instance->type === 'password') {
                $keys[] = $instance->key;
            }
        }

        return $keys;
    }

    private function getLlmConfigurationSettings(int $userId): array
    {
        $config = LLMDriverConfiguration::where('user_id', $userId)->where('is_default', true)->first();
        try {
            $settings = $config !== null
                ? $this->llmConfig->decodeSettings($config->driver_class, $config->getRawOriginal('settings'))
                : [];
        } catch (Throwable) {
            $settings = [];
        }

        $drivers = $this->llmConfig->getDrivers();
        $schema = null;
        foreach ($drivers as $driver) {
            if ($config !== null && $driver['driver_class'] === $config->driver_class) {
                $schema = $driver['settings_schema'];
                break;
            }
        }

        return $schema !== null ? $this->llmConfig->maskForApi($settings, $schema) : $settings;
    }

    private function agentResource(Agent $agent): array
    {
        $tools = AgentTool::where('agent_id', $agent->id)->get();

        return [
            'id'                   => (int) $agent->id,
            'name'                 => $agent->name,
            'description'          => $agent->description,
            'recipe_id'            => $agent->recipe_id,
            'system_prompt'        => $agent->system_prompt,
            'llm_driver_config_id' => $agent->llm_driver_config_id,
            'max_steps'            => (int) $agent->max_steps,
            'is_active'            => (bool) $agent->is_active,
            'retry_after_minutes'  => (int) ($agent->retry_after_minutes ?? 0),
            'max_retries'          => (int) ($agent->max_retries ?? 0),
            'tools'                => $tools->map(fn(AgentTool $t) => $this->toolResource($t))->values()->toArray(),
        ];
    }

    private function toolResource(AgentTool $tool): array
    {
        $raw = $tool->getRawOriginal('auto_approve');

        return [
            'tool_class'   => $tool->tool_class,
            'tool_name'    => $tool->tool_name,
            'auto_approve' => $raw === null ? null : (bool) $raw,
        ];
    }

    private function extractOverrideFlag(?AgentToolOperationOverride $row, string $field): ?int
    {
        if ($row === null) {
            return null;
        }
        $raw = $row->getRawOriginal($field);
        if ($raw === null) {
            return null;
        }
        return (int) $raw === 1 ? 1 : 0;
    }

    private function parseOverrideFlag(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }
        $value = $data[$key];
        return $value === null ? null : (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
    }
}
