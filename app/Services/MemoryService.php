<?php

declare(strict_types=1);

namespace Spora\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use Spora\Models\Agent;
use Spora\Models\Memory;

/**
 * Service for memory management.
 * All DB access for Memory domain goes through this service.
 */
final class MemoryService implements MemoryServiceInterface
{
    public function listGlobalMemories(int $userId): array
    {
        $memories = Memory::global()
            ->where('user_id', $userId)
            ->orderBy('order')
            ->orderBy('name')
            ->get()
            ->map(fn(Memory $m) => $this->resource($m));

        return $memories->all();
    }

    public function listAgentMemories(int $agentId, int $userId): ?array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $memories = Memory::forAgent($agentId)
            ->orderBy('order')
            ->orderBy('name')
            ->get()
            ->map(fn(Memory $m) => $this->resource($m));

        return $memories->all();
    }

    public function getGlobalMemory(int $memoryId, int $userId): ?array
    {
        $memory = Memory::find($memoryId);
        if ($memory === null) {
            return null;
        }

        if ($memory->agent_id !== null || $memory->user_id !== $userId) {
            return null;
        }

        return ['memory' => $this->resource($memory)];
    }

    public function getAgentMemory(int $memoryId, int $agentId, int $userId): ?array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $memory = Memory::where('id', $memoryId)->where('agent_id', $agentId)->first();
        if ($memory === null) {
            return null;
        }

        return ['memory' => $this->resource($memory)];
    }

    public function createGlobalMemory(int $userId, array $data): array
    {
        $this->validate($data, isCreation: true);

        $id = Capsule::table('memories')->insertGetId([
            'user_id'    => $userId,
            'agent_id'   => null,
            'name'       => $data['name'],
            'summary'    => isset($data['summary']) ? trim((string) $data['summary']) : null,
            'content'    => isset($data['content']) ? trim((string) $data['content']) : null,
            'order'      => isset($data['order']) ? (int) $data['order'] : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $memory = Memory::findOrFail($id);

        return ['memory' => $this->resource($memory)];
    }

    public function createAgentMemory(int $agentId, int $userId, array $data): array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            throw new RuntimeException('Agent not found');
        }

        $this->validate($data, isCreation: true);

        $id = Capsule::table('memories')->insertGetId([
            'user_id'    => $userId,
            'agent_id'   => $agentId,
            'name'       => $data['name'],
            'summary'    => isset($data['summary']) ? trim((string) $data['summary']) : null,
            'content'    => isset($data['content']) ? trim((string) $data['content']) : null,
            'order'      => isset($data['order']) ? (int) $data['order'] : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $memory = Memory::findOrFail($id);

        return ['memory' => $this->resource($memory)];
    }

    public function updateGlobalMemory(int $memoryId, int $userId, array $data): ?array
    {
        $memory = Memory::find($memoryId);
        if ($memory === null) {
            return null;
        }

        if ($memory->agent_id !== null || $memory->user_id !== $userId) {
            return null;
        }

        $this->validate($data, isCreation: false);

        $allowed = ['name', 'summary', 'content', 'order'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if ($updateData !== []) {
            if (isset($updateData['order'])) {
                $updateData['order'] = (int) $updateData['order'];
            }
            Capsule::table('memories')
                ->where('id', $memoryId)
                ->update(array_merge($updateData, ['updated_at' => date('Y-m-d H:i:s')]));
            $memory->refresh();
        }

        return ['memory' => $this->resource($memory)];
    }

    public function updateAgentMemory(int $memoryId, int $agentId, int $userId, array $data): ?array
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return null;
        }

        $memory = Memory::where('id', $memoryId)->where('agent_id', $agentId)->first();
        if ($memory === null) {
            return null;
        }

        $this->validate($data, isCreation: false);

        $allowed = ['name', 'summary', 'content', 'order'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if ($updateData !== []) {
            if (isset($updateData['order'])) {
                $updateData['order'] = (int) $updateData['order'];
            }
            Capsule::table('memories')
                ->where('id', $memoryId)
                ->update(array_merge($updateData, ['updated_at' => date('Y-m-d H:i:s')]));
            $memory->refresh();
        }

        return ['memory' => $this->resource($memory)];
    }

    public function deleteGlobalMemory(int $memoryId, int $userId): bool
    {
        $memory = Memory::find($memoryId);
        if ($memory === null) {
            return false;
        }

        if ($memory->agent_id !== null || $memory->user_id !== $userId) {
            return false;
        }

        Capsule::table('memories')->where('id', $memoryId)->delete();

        return true;
    }

    public function deleteAgentMemory(int $memoryId, int $agentId, int $userId): bool
    {
        $agent = $this->findAgent($agentId, $userId);
        if ($agent === null) {
            return false;
        }

        $memory = Memory::where('id', $memoryId)->where('agent_id', $agentId)->first();
        if ($memory === null) {
            return false;
        }

        Capsule::table('memories')->where('id', $memoryId)->delete();

        return true;
    }

    private function findAgent(int $id, int $userId): ?Agent
    {
        return Agent::where('id', $id)->where('user_id', $userId)->first();
    }

    private function validate(array $data, bool $isCreation): void
    {
        if ($isCreation) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('name is required');
            }
        }
    }

    private function resource(Memory $memory): array
    {
        return [
            'id'         => (int) $memory->id,
            'user_id'    => $memory->user_id !== null ? (int) $memory->user_id : null,
            'agent_id'   => $memory->agent_id !== null ? (int) $memory->agent_id : null,
            'name'       => $memory->name,
            'summary'    => $memory->summary,
            'content'    => $memory->content,
            'order'      => (int) $memory->order,
            'created_at' => $memory->created_at->toIso8601String(),
            'updated_at' => $memory->updated_at->toIso8601String(),
        ];
    }
}
