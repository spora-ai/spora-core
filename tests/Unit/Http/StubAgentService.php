<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use RuntimeException;
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
            'system_prompt'          => null,
            'llm_driver_config_id'   => null,
            'max_steps'              => 10,
            'is_active'              => true,
            'retry_after_minutes'    => 0,
            'max_retries'            => 0,
            'is_pinned'              => false,
            'is_archived'            => false,
            'created_at'             => null,
        ]];
    }

    public function createAgent(int $userId, array $data): Agent
    {
        $agent = new Agent();
        $agent->id = 99;
        $agent->user_id = $userId;
        $agent->name = $data['name'] ?? 'New';
        $agent->description = $data['description'] ?? null;
        $agent->system_prompt = $data['system_prompt'] ?? null;
        $agent->llm_driver_config_id = $data['llm_driver_config_id'] ?? null;
        $agent->max_steps = $data['max_steps'] ?? 10;
        $this->seedAgentDefaults($agent);

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
        $agent->system_prompt = null;
        $agent->llm_driver_config_id = null;
        $agent->max_steps = 10;
        $this->seedAgentDefaults($agent);

        return $agent;
    }

    public function updateAgent(int $agentId, int $userId, array $data): ?Agent
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }
        // Reflect the partial-update payload onto the returned model so the
        // controller's resource serialization picks up the new values.
        foreach (['is_pinned', 'is_archived'] as $boolKey) {
            if (array_key_exists($boolKey, $data)) {
                $agent->$boolKey = (bool) $data[$boolKey];
            }
        }

        return $agent;
    }

    public function deleteAgent(int $agentId, int $userId): bool
    {
        return $agentId !== 999999;
    }

    public function setPinned(int $userId, int $agentId, bool $pinned): Agent
    {
        return $this->setFlag($userId, $agentId, 'is_pinned', $pinned);
    }

    public function setArchived(int $userId, int $agentId, bool $archived): Agent
    {
        return $this->setFlag($userId, $agentId, 'is_archived', $archived);
    }
    public function setFavorite(int $userId, int $agentId, bool $favorite): Agent
    {
        return $this->setFlag($userId, $agentId, 'is_favorite', $favorite);
    }

    /**
     * Apply the static default scalars to a stubbed Agent. Mirrors the
     * migration defaults for the new flag columns plus the long-standing
     * scalar fields the test fixtures expect.
     */
    private function seedAgentDefaults(Agent $agent): void
    {
        $agent->is_active = true;
        $agent->retry_after_minutes = 0;
        $agent->max_retries = 0;
        $agent->is_pinned = false;
        $agent->is_archived = false;
    }

    /**
     * Shared flag-flip body for setPinned / setArchived in the stub. Mirrors
     * the production setFlag shape: 404-on-missing then mutate.
     */
    private function setFlag(int $userId, int $agentId, string $column, bool $value): Agent
    {
        $agent = $this->getAgent($agentId, $userId);
        if ($agent === null) {
            throw new RuntimeException('Agent not found');
        }
        $agent->$column = $value;

        return $agent;
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
