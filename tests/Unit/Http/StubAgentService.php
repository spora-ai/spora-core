<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Spora\Models\Agent;
use Spora\Services\AgentServiceInterface;

/**
 * Stub AgentServiceInterface that returns canned data.
 */
class StubAgentService implements AgentServiceInterface
{
    public function getAgentsForUser(int $userId): array
    {
        return [[
            'id'                     => 1,
            'user_id'                => $userId,
            'name'                   => 'Stub Agent',
            'description'            => null,
            'recipe_id'              => null,
            'system_prompt'          => null,
            'llm_driver_config_id'   => null,
            'max_steps'              => 10,
            'is_active'              => true,
            'retry_after_minutes'    => 0,
            'max_retries'            => 0,
        ]];
    }

    public function createAgent(int $userId, array $data): Agent
    {
        $agent = new Agent();
        $agent->id = 99;
        $agent->user_id = $userId;
        $agent->name = $data['name'] ?? 'New';
        $agent->description = $data['description'] ?? null;
        $agent->recipe_id = null;
        $agent->system_prompt = $data['system_prompt'] ?? null;
        $agent->llm_driver_config_id = $data['llm_driver_config_id'] ?? null;
        $agent->max_steps = $data['max_steps'] ?? 10;
        $agent->is_active = true;
        $agent->retry_after_minutes = 0;
        $agent->max_retries = 0;

        return $agent;
    }

    public function getAgent(int $agentId, int $userId): ?Agent
    {
        if ($agentId === 999999) {
            return null;
        }
        $agent = new Agent();
        $agent->id = $agentId;
        $agent->user_id = $userId;
        $agent->name = 'Stub Agent';
        $agent->description = null;
        $agent->recipe_id = null;
        $agent->system_prompt = null;
        $agent->llm_driver_config_id = null;
        $agent->max_steps = 10;
        $agent->is_active = true;
        $agent->retry_after_minutes = 0;
        $agent->max_retries = 0;

        return $agent;
    }

    public function updateAgent(int $agentId, int $userId, array $data): ?Agent
    {
        return $this->getAgent($agentId, $userId);
    }

    public function deleteAgent(int $agentId, int $userId): bool
    {
        return $agentId !== 999999;
    }

    public function enableTool(int $agentId, int $userId, string $toolClass): array
    {
        if ($agentId === 999999) {
            return ['error' => 'Agent not found'];
        }
        return ['tool' => ['tool_class' => $toolClass, 'is_enabled' => true]];
    }

    public function disableTool(int $agentId, int $userId, string $toolClass): void
    {
        // no-op
    }

    public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
    {
        if ($agentId === 999999) {
            return null;
        }
        return ['tool_class' => $toolClass, 'is_enabled' => false, 'missing_required' => [], 'can_enable' => true];
    }

    public function getAllToolsStatus(int $agentId, int $userId): ?array
    {
        if ($agentId === 999999) {
            return null;
        }
        return [];
    }

    public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array
    {
        if ($agentId === 999999) {
            return [];
        }
        return ['key' => 'val'];
    }

    public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array
    {
        return $settings;
    }

    public function deleteOverride(int $agentId, int $userId, string $toolClass): void
    {
        // no-op
    }

    public function getToolsOperations(int $agentId, int $userId): ?array
    {
        if ($agentId === 999999) {
            return null;
        }
        return [];
    }

    public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array
    {
        return [];
    }

    public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array
    {
        return ['operation' => $operation, 'tool_class' => $toolClass] + $data;
    }
}
