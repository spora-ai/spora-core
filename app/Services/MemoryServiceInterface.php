<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Contract for memory persistence (global and agent-scoped).
 *
 * Implementations handle CRUD and reordering of key-value memories
 * that agents use to maintain context across task executions.
 */
interface MemoryServiceInterface
{
    /**
     * @return list<array>
     */
    public function listGlobalMemories(int $userId): ?array;

    /**
     * @return list<array>
     */
    public function listAgentMemories(int $agentId, int $userId): ?array;

    /**
     * @return array|null
     */
    public function getGlobalMemory(int $memoryId, int $userId): ?array;

    /**
     * @return array|null
     */
    public function getAgentMemory(int $memoryId, int $agentId, int $userId): ?array;

    /**
     * @return array
     */
    public function createGlobalMemory(int $userId, array $data): array;

    /**
     * @return array
     */
    public function createAgentMemory(int $agentId, int $userId, array $data): array;

    /**
     * @return array|null
     */
    public function updateGlobalMemory(int $memoryId, int $userId, array $data): ?array;

    /**
     * @return array|null
     */
    public function updateAgentMemory(int $memoryId, int $agentId, int $userId, array $data): ?array;

    public function deleteGlobalMemory(int $memoryId, int $userId): bool;

    public function deleteAgentMemory(int $memoryId, int $agentId, int $userId): bool;

    /**
     * @param list<int> $orderedIds Memory IDs in desired display order
     */
    public function reorderGlobalMemories(int $userId, array $orderedIds): void;

    /**
     * @param list<int> $orderedIds Memory IDs in desired display order
     */
    public function reorderAgentMemories(int $agentId, int $userId, array $orderedIds): void;
}
