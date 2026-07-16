<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\Agent;

/**
 * Service interface for agent lifecycle, tool management, and settings overrides.
 */
interface AgentServiceInterface
{
    // ── Agent lifecycle ─────────────────────────────────────────────────────────

    /**
     * @return list<array> Agent resource arrays for a user
     */
    public function getAgentsForUser(int $userId): array;

    public function createAgent(int $userId, array $data): Agent;

    public function getAgent(int $agentId, int $userId): ?Agent;

    public function updateAgent(int $agentId, int $userId, array $data): ?Agent;

    public function deleteAgent(int $agentId, int $userId): bool;

    /**
     * Pin or unpin an agent for the given user.
     *
     * Parameter order is (int $userId, int $agentId, bool $value) — read as
     * "user N sets agent M's pinned flag to V". This differs from the rest
     * of the service which takes (int $agentId, int $userId, ...).
     *
     * @throws Exceptions\AgentNotFoundException If the agent does not exist or is not owned by $userId
     */
    public function setPinned(int $userId, int $agentId, bool $pinned): Agent;

    /**
     * Archive or unarchive an agent for the given user.
     *
     * Parameter order is (int $userId, int $agentId, bool $value) — see
     * setPinned() for the rationale on the deliberate flip away from the
     * service-wide (int $agentId, int $userId, ...) convention.
     *
     * @throws Exceptions\AgentNotFoundException If the agent does not exist or is not owned by $userId
     */
    public function setArchived(int $userId, int $agentId, bool $archived): Agent;

    // ── Tool management ─────────────────────────────────────────────────────────

    /**
     * Enable a tool for an agent, seeding schema defaults if no config exists.
     *
     * @return array{tool: array, warning?: string, missing_required?: list<string>}|array{tool: array, warning?: string, missing_required?: list<string>, is_idempotent: true}|array{error: string}
     */
    public function enableTool(int $agentId, int $userId, string $toolClass): array;

    public function disableTool(int $agentId, int $userId, string $toolClass): void;

    // ── Tool status & settings ────────────────────────────────────────────────────

    /**
     * @return array{tool_class: string, is_enabled: bool, missing_required: list<string>, can_enable: bool}|null
     */
    public function getToolStatus(int $agentId, int $userId, string $toolClass): ?array;

    /**
     * @return list<array{tool_class: string, is_enabled: bool, missing_required: list<string>, can_enable: bool}>|null
     */
    public function getAllToolsStatus(int $agentId, int $userId): ?array;

    // ── Settings overrides ────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|array<string, array{value: mixed, source: string}>
     */
    public function getOverride(int $agentId, int $userId, string $toolClass, bool $rawOnly = false): array;

    public function putOverride(int $agentId, int $userId, string $toolClass, array $settings): array;

    public function deleteOverride(int $agentId, int $userId, string $toolClass): void;

    // ── Operation overrides ───────────────────────────────────────────────────────

    /**
     * @return list<array{tool_class: string, operation: string, enabled: int|null, default_requires_approval: int|null, effective_enabled: bool, effective_requires_approval: bool}>|null
     */
    public function getToolsOperations(int $agentId, int $userId): ?array;

    /**
     * @return array{operation: string, tool_class: string, enabled: int|null, default_requires_approval: int|null, effective_enabled: bool, effective_requires_approval: bool}|array{}
     */
    public function getOperationOverride(int $agentId, int $userId, string $toolClass, string $operation): array;

    public function patchOperationOverride(int $agentId, int $userId, string $toolClass, string $operation, array $data): array;
}
