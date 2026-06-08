<?php

declare(strict_types=1);

namespace Spora\Services\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Tools\Traits\HasOperations;

/**
 * Resolves per-tool-operation enable/approval state, including the
 * "effective" values that fold override rows into tool defaults.
 */
final class AgentToolOperationsResolver
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly AgentToolInstanceResolver $instanceResolver,
        private readonly AgentToolOverrideResolver $overrideResolver,
    ) {}

    /**
     * @return list<array{tool_class: string, tool_name: string, operation: string, enabled: int|null, default_requires_approval: int|null, effective_enabled: bool, effective_requires_approval: bool}>|null
     */
    public function getToolsOperations(int $agentId, int $userId): ?array
    {
        $agent = Agent::where('id', $agentId)->where('user_id', $userId)->first();
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

            $instance = $this->instanceResolver->resolveToolInstance($tool->tool_class);
            if ($instance === null) {
                continue;
            }

            foreach ($instance->getOperations() as $op) {
                $key = $tool->tool_class . '::' . $op->name;
                $row = $overrides->get($key);

                $operations[] = [
                    'tool_class'                   => $tool->tool_class,
                    'tool_name'                    => $tool->tool_name,
                    'operation'                    => $op->name,
                    'enabled'                      => $this->overrideResolver->extractOverrideFlag($row, 'enabled'),
                    'default_requires_approval'    => $this->overrideResolver->extractOverrideFlag($row, 'default_requires_approval'),
                    'effective_enabled'            => $this->resolveOperationEffectiveEnabled($tool->tool_class, $op->name, $agentId),
                    'effective_requires_approval'  => $this->resolveOperationEffectiveRequiresApproval($tool->tool_class, $op->name, $agentId),
                ];
            }
        }

        return $operations;
    }

    /**
     * @return array{operation: string, tool_class: string, enabled: int|null, default_requires_approval: int|null, effective_enabled: bool, effective_requires_approval: bool}|array{}
     */
    public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array
    {
        $agent = Agent::where('id', $agentId)->where('user_id', $userId)->first();
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
            'enabled'                      => $this->overrideResolver->extractOverrideFlag($row, 'enabled'),
            'default_requires_approval'    => $this->overrideResolver->extractOverrideFlag($row, 'default_requires_approval'),
            'effective_enabled'            => $this->resolveOperationEffectiveEnabled($toolClass, $operation, $agentId),
            'effective_requires_approval'  => $this->resolveOperationEffectiveRequiresApproval($toolClass, $operation, $agentId),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{operation: string, tool_class: string, enabled: int|null, default_requires_approval: int|null, effective_enabled: bool, effective_requires_approval: bool}|array{}
     */
    public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array
    {
        $agent = Agent::where('id', $agentId)->where('user_id', $userId)->first();
        if ($agent === null) {
            return [];
        }

        $enabled = $this->overrideResolver->parseOverrideFlag($data, 'enabled');
        $defaultRequiresApproval = $this->overrideResolver->parseOverrideFlag($data, 'default_requires_approval');

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
                $updateData['updated_at'] = date(self::DATETIME_FORMAT);
                Capsule::table('agent_tool_operation_overrides')
                    ->where('id', $existing->id)
                    ->update($updateData);
            }
        } else {
            $insertData = [
                'agent_id'    => $agentId,
                'tool_class'  => $toolClass,
                'operation'   => $operation,
                'created_at'  => date(self::DATETIME_FORMAT),
                'updated_at'  => date(self::DATETIME_FORMAT),
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

        $instance = $this->instanceResolver->resolveToolInstance($toolClass);
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

        $instance = $this->instanceResolver->resolveToolInstance($toolClass);
        if ($instance !== null && in_array(HasOperations::class, class_uses_recursive($toolClass), true)) {
            return $instance->requiresApprovalByDefault($operation);
        }

        return true;
    }
}
