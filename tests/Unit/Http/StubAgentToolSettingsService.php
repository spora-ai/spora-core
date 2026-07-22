<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Spora\Services\AgentToolSettingsServiceInterface;

/**
 * Test stub for AgentToolSettingsServiceInterface. Mirrors the shape of
 * StubAgentService but only implements the tool settings surface — created
 * when AgentService was split to satisfy SonarCloud S1448.
 */
class StubAgentToolSettingsService implements AgentToolSettingsServiceInterface
{
    public function enableTool(int $agentId, int $userId, string $toolClass): array
    {
        // Match StubAgentService::getAgent() — unknown agents return NOT_FOUND.
        if ($agentId === 999999) {
            return ['error' => 'NOT_FOUND'];
        }
        return ['tool' => ['tool_class' => $toolClass, 'tool_name' => 'stub']];
    }

    public function disableTool(int $agentId, int $userId, string $toolClass): void
    {
        // no-op
    }

    public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array
    {
        // Match StubAgentService::getAgent() — unknown agents return null.
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
        return [];
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
