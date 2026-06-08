<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\Memory;
use Spora\Services\Exceptions\MemoryValidationException;
use Spora\Services\MemoryService;

const AGENT1_EMAIL = 'agent1@test.com';
const AGENT2_NAME = 'Agent 2';
const OWNER_EMAIL = 'owner@test.com';
defined('OTHER_EMAIL') || define('OTHER_EMAIL', 'other@test.com');
const IT_AGENT_NOT_FOUND = 'returns null when agent does not exist';
const IT_MEMORY_NOT_FOUND = 'returns null when memory does not exist';

describe('MemoryService', function (): void {

    //
    // Shared helpers
    //

    function makeMemoryService(): MemoryService
    {
        return new MemoryService();
    }

    /**
     * Create a user and agent, return [userId, agentId].
     */
    function createUserWithAgent(string $email = 'service@example.com'): array
    {
        static $seq = 0;
        $seq++;
        $authService = bootAuthLayer();
        $userId = bootAuth($authService, "{$seq}{$email}", 'Password1!');

        $agentId = Agent::create([
            'user_id'      => $userId,
            'name'         => 'Test Agent',
            'llm_provider' => 'mock',
            'llm_model'    => 'mock',
            'max_steps'    => 10,
            'is_active'    => true,
        ])->id;

        return [$userId, $agentId];
    }

    //
    // listGlobalMemories
    //

    describe('listGlobalMemories', function (): void {

        it('returns empty array when no global memories exist', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->listGlobalMemories($userId);

            expect($result)->toBeArray()
                ->and($result)->toBeEmpty();
        });

        it('returns only global memories for this user', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'global_1',
                'summary'  => 'Global summary 1',
                'order'    => 1,
            ]);
            Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'global_2',
                'summary'  => 'Global summary 2',
                'order'    => 2,
            ]);
            Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'agent_memory',
                'content'  => 'Should not appear',
            ]);

            $result = $service->listGlobalMemories($userId);

            expect($result)->toHaveCount(2)
                ->and(array_column($result, 'name'))->toContain('global_1', 'global_2')
                ->and(array_column($result, 'name'))->not->toContain('agent_memory');
        });

        it('does not return another users global memories', function (): void {
            [$userId1] = createUserWithAgent('user1@test.com');
            [$userId2] = createUserWithAgent('user2@test.com');
            $service = makeMemoryService();

            Memory::create([
                'user_id'  => $userId1,
                'agent_id' => null,
                'name'     => 'user1_global',
            ]);
            Memory::create([
                'user_id'  => $userId2,
                'agent_id' => null,
                'name'     => 'user2_global',
            ]);

            $result = $service->listGlobalMemories($userId1);

            expect($result)->toHaveCount(1)
                ->and($result[0]['name'])->toBe('user1_global');
        });

        it('orders by order field then by name', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            Memory::create(['user_id' => $userId, 'agent_id' => null, 'name' => 'zebra', 'order' => 1]);
            Memory::create(['user_id' => $userId, 'agent_id' => null, 'name' => 'alpha', 'order' => 1]);
            Memory::create(['user_id' => $userId, 'agent_id' => null, 'name' => 'beta', 'order' => 0]);

            $result = $service->listGlobalMemories($userId);

            expect(array_column($result, 'name'))->toBe(['beta', 'alpha', 'zebra']);
        });
    });

    //
    // listAgentMemories
    //

    describe('listAgentMemories', function (): void {

        it(IT_AGENT_NOT_FOUND, function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->listAgentMemories(9999, $userId);

            expect($result)->toBeNull();
        });

        it('returns empty array when no agent memories exist', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->listAgentMemories($agentId, $userId);

            expect($result)->toBeArray()
                ->and($result)->toBeEmpty();
        });

        it('returns only memories for the specified agent', function (): void {
            [$userId, $agentId1] = createUserWithAgent(AGENT1_EMAIL);
            $agentId2 = Agent::create([
                'user_id' => $userId, 'name' => AGENT2_NAME, 'llm_provider' => 'mock',
                'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true,
            ])->id;
            $service = makeMemoryService();

            Memory::create(['user_id' => $userId, 'agent_id' => $agentId1, 'name' => 'memory_for_agent1']);
            Memory::create(['user_id' => $userId, 'agent_id' => $agentId2, 'name' => 'memory_for_agent2']);

            $result = $service->listAgentMemories($agentId1, $userId);

            expect($result)->toHaveCount(1)
                ->and($result[0]['name'])->toBe('memory_for_agent1');
        });
    });

    //
    // createGlobalMemory
    //

    describe('createGlobalMemory', function (): void {

        it('creates a global memory with minimal data and auto-assigns order', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->createGlobalMemory($userId, ['name' => 'minimal']);

            expect($result['memory']['name'])->toBe('minimal')
                ->and($result['memory']['user_id'])->toBe($userId)
                ->and($result['memory']['agent_id'])->toBeNull()
                ->and($result['memory']['summary'])->toBeNull()
                ->and($result['memory']['content'])->toBeNull()
                ->and($result['memory']['order'])->toBe(1);
        });

        it('creates a global memory with full data and ignores explicit order (auto-assigns)', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->createGlobalMemory($userId, [
                'name'    => 'full_memory',
                'summary' => 'A summary',
                'content' => 'The content',
                'order'   => 42,
            ]);

            expect($result['memory']['name'])->toBe('full_memory')
                ->and($result['memory']['summary'])->toBe('A summary')
                ->and($result['memory']['content'])->toBe('The content')
                ->and($result['memory']['order'])->toBe(1);
        });

        it('trims whitespace from summary and content', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->createGlobalMemory($userId, [
                'name'    => 'trim_test',
                'summary' => '  trimmed summary  ',
                'content' => "  trimmed content\n",
            ]);

            expect($result['memory']['summary'])->toBe('trimmed summary')
                ->and($result['memory']['content'])->toBe('trimmed content');
        });

        it('throws when name is empty', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            expect(fn() => $service->createGlobalMemory($userId, ['name' => '']))
                ->toThrow(MemoryValidationException::class, 'name is required');
        });

        it('throws when name is missing', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            expect(fn() => $service->createGlobalMemory($userId, []))
                ->toThrow(MemoryValidationException::class, 'name is required');
        });
    });

    //
    // createAgentMemory
    //

    describe('createAgentMemory', function (): void {

        it('throws when agent does not exist', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            expect(fn() => $service->createAgentMemory(9999, $userId, ['name' => 'test']))
                ->toThrow(RuntimeException::class, 'Agent not found');
        });

        it('creates an agent-scoped memory and auto-assigns order', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->createAgentMemory($agentId, $userId, [
                'name'    => 'agent_memory',
                'content' => 'Agent-specific content',
            ]);

            expect($result['memory']['name'])->toBe('agent_memory')
                ->and($result['memory']['agent_id'])->toBe($agentId)
                ->and($result['memory']['user_id'])->toBe($userId)
                ->and($result['memory']['order'])->toBe(1);
        });
    });

    //
    // getGlobalMemory
    //

    describe('getGlobalMemory', function (): void {

        it(IT_MEMORY_NOT_FOUND, function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->getGlobalMemory(9999, $userId);

            expect($result)->toBeNull();
        });

        it('returns null when memory belongs to another user', function (): void {
            [$userId1] = createUserWithAgent(OWNER_EMAIL);
            [$userId2] = createUserWithAgent(OTHER_EMAIL);
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId1,
                'agent_id' => null,
                'name'     => 'private',
            ]);

            $result = $service->getGlobalMemory($memory->id, $userId2);

            expect($result)->toBeNull();
        });

        it('returns null when memory is agent-scoped', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'agent_only',
            ]);

            $result = $service->getGlobalMemory($memory->id, $userId);

            expect($result)->toBeNull();
        });

        it('returns the memory when found', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'findable',
                'summary'  => 'Found it',
            ]);

            $result = $service->getGlobalMemory($memory->id, $userId);

            expect($result)->not->toBeNull()
                ->and($result['memory']['name'])->toBe('findable')
                ->and($result['memory']['summary'])->toBe('Found it');
        });
    });

    //
    // getAgentMemory
    //

    describe('getAgentMemory', function (): void {

        it(IT_AGENT_NOT_FOUND, function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->getAgentMemory(9999, 9999, $userId);

            expect($result)->toBeNull();
        });

        it(IT_MEMORY_NOT_FOUND, function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->getAgentMemory(9999, $agentId, $userId);

            expect($result)->toBeNull();
        });

        it('returns null when memory belongs to different agent', function (): void {
            [$userId, $agentId1] = createUserWithAgent(OWNER_EMAIL);
            $agentId2 = Agent::create([
                'user_id' => $userId, 'name' => AGENT2_NAME, 'llm_provider' => 'mock',
                'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true,
            ])->id;
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId1,
                'name'     => 'agent1_only',
            ]);

            $result = $service->getAgentMemory($memory->id, $agentId2, $userId);

            expect($result)->toBeNull();
        });

        it('returns the memory when found', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'agent_findable',
            ]);

            $result = $service->getAgentMemory($memory->id, $agentId, $userId);

            expect($result)->not->toBeNull()
                ->and($result['memory']['name'])->toBe('agent_findable');
        });
    });

    //
    // updateGlobalMemory
    //

    describe('updateGlobalMemory', function (): void {

        it(IT_MEMORY_NOT_FOUND, function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->updateGlobalMemory(9999, $userId, ['name' => 'new']);

            expect($result)->toBeNull();
        });

        it('returns null when memory belongs to another user', function (): void {
            [$userId1] = createUserWithAgent(OWNER_EMAIL);
            [$userId2] = createUserWithAgent(OTHER_EMAIL);
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId1,
                'agent_id' => null,
                'name'     => 'private',
            ]);

            $result = $service->updateGlobalMemory($memory->id, $userId2, ['name' => 'hacked']);

            expect($result)->toBeNull();
        });

        it('updates only allowed fields', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'updatable',
                'summary'  => 'Original summary',
                'content'  => 'Original content',
                'order'    => 0,
            ]);

            $result = $service->updateGlobalMemory($memory->id, $userId, [
                'name'    => 'new_name',
                'content' => 'new content',
                'order'   => 5,
                'unknown' => 'should be ignored',
            ]);

            expect($result['memory']['name'])->toBe('new_name')
                ->and($result['memory']['content'])->toBe('new content')
                ->and($result['memory']['order'])->toBe(5)
                ->and($result['memory']['summary'])->toBe('Original summary');
        });

        it('updates without changes', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'no_change',
            ]);

            $result = $service->updateGlobalMemory($memory->id, $userId, []);

            expect($result['memory']['name'])->toBe('no_change');
        });
    });

    //
    // updateAgentMemory
    //

    describe('updateAgentMemory', function (): void {

        it(IT_AGENT_NOT_FOUND, function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->updateAgentMemory(9999, 9999, $userId, ['name' => 'new']);

            expect($result)->toBeNull();
        });

        it(IT_MEMORY_NOT_FOUND, function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->updateAgentMemory(9999, $agentId, $userId, ['name' => 'new']);

            expect($result)->toBeNull();
        });

        it('updates agent memory', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'agent_updatable',
                'content'  => 'Original',
            ]);

            $result = $service->updateAgentMemory($memory->id, $agentId, $userId, [
                'content' => 'Updated',
                'order'   => 10,
            ]);

            expect($result['memory']['content'])->toBe('Updated')
                ->and($result['memory']['order'])->toBe(10);
        });
    });

    //
    // deleteGlobalMemory
    //

    describe('deleteGlobalMemory', function (): void {

        it('returns false when memory does not exist', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->deleteGlobalMemory(9999, $userId);

            expect($result)->toBeFalse();
        });

        it('returns false when memory belongs to another user', function (): void {
            [$userId1] = createUserWithAgent(OWNER_EMAIL);
            [$userId2] = createUserWithAgent(OTHER_EMAIL);
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId1,
                'agent_id' => null,
                'name'     => 'private',
            ]);

            $result = $service->deleteGlobalMemory($memory->id, $userId2);

            expect($result)->toBeFalse()
                ->and(Memory::find($memory->id))->not->toBeNull();
        });

        it('returns false when memory is agent-scoped', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'agent_only',
            ]);

            $result = $service->deleteGlobalMemory($memory->id, $userId);

            expect($result)->toBeFalse()
                ->and(Memory::find($memory->id))->not->toBeNull();
        });

        it('deletes the memory and returns true', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'to_delete',
            ]);

            $result = $service->deleteGlobalMemory($memory->id, $userId);

            expect($result)->toBeTrue()
                ->and(Memory::find($memory->id))->toBeNull();
        });
    });

    //
    // deleteAgentMemory
    //

    describe('deleteAgentMemory', function (): void {

        it('returns false when agent does not exist', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->deleteAgentMemory(9999, 9999, $userId);

            expect($result)->toBeFalse();
        });

        it('returns false when memory does not exist', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->deleteAgentMemory(9999, $agentId, $userId);

            expect($result)->toBeFalse();
        });

        it('returns false when memory belongs to different agent', function (): void {
            [$userId, $agentId1] = createUserWithAgent(OWNER_EMAIL);
            $agentId2 = Agent::create([
                'user_id' => $userId, 'name' => AGENT2_NAME, 'llm_provider' => 'mock',
                'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true,
            ])->id;
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId1,
                'name'     => 'agent1_only',
            ]);

            $result = $service->deleteAgentMemory($memory->id, $agentId2, $userId);

            expect($result)->toBeFalse()
                ->and(Memory::find($memory->id))->not->toBeNull();
        });

        it('deletes the agent memory and returns true', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $memory = Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'agent_to_delete',
            ]);

            $result = $service->deleteAgentMemory($memory->id, $agentId, $userId);

            expect($result)->toBeTrue()
                ->and(Memory::find($memory->id))->toBeNull();
        });
    });

    //
    // Auto-assigned order on creation
    //

    describe('createGlobalMemory auto-assigns order', function (): void {

        it('assigns sequential order values to global memories', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $m1 = $service->createGlobalMemory($userId, ['name' => 'first']);
            $m2 = $service->createGlobalMemory($userId, ['name' => 'second']);
            $m3 = $service->createGlobalMemory($userId, ['name' => 'third']);

            expect($m1['memory']['order'])->toBe(1);
            expect($m2['memory']['order'])->toBe(2);
            expect($m3['memory']['order'])->toBe(3);
        });

        it('orders global memories independently from agent memories', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $g1 = $service->createGlobalMemory($userId, ['name' => 'global_first']);
            $a1 = $service->createAgentMemory($agentId, $userId, ['name' => 'agent_first']);
            $g2 = $service->createGlobalMemory($userId, ['name' => 'global_second']);
            $a2 = $service->createAgentMemory($agentId, $userId, ['name' => 'agent_second']);

            expect($g1['memory']['order'])->toBe(1);
            expect($g2['memory']['order'])->toBe(2);
            expect($a1['memory']['order'])->toBe(1);
            expect($a2['memory']['order'])->toBe(2);
        });

        it('orders memories per-agent independently', function (): void {
            [$userId, $agentId1] = createUserWithAgent(AGENT1_EMAIL);
            $agentId2 = Agent::create([
                'user_id' => $userId, 'name' => AGENT2_NAME, 'llm_provider' => 'mock',
                'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true,
            ])->id;
            $service = makeMemoryService();

            $a1 = $service->createAgentMemory($agentId1, $userId, ['name' => 'agent1_first']);
            $a2 = $service->createAgentMemory($agentId2, $userId, ['name' => 'agent2_first']);
            $a1b = $service->createAgentMemory($agentId1, $userId, ['name' => 'agent1_second']);

            expect($a1['memory']['order'])->toBe(1);
            expect($a2['memory']['order'])->toBe(1);
            expect($a1b['memory']['order'])->toBe(2);
        });
    });

    //
    // reorderGlobalMemories
    //

    describe('reorderGlobalMemories', function (): void {

        it('updates order values based on provided ID array', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            $m1 = $service->createGlobalMemory($userId, ['name' => 'first']);
            $m2 = $service->createGlobalMemory($userId, ['name' => 'second']);
            $m3 = $service->createGlobalMemory($userId, ['name' => 'third']);

            $service->reorderGlobalMemories($userId, [$m3['memory']['id'], $m1['memory']['id'], $m2['memory']['id']]);

            $result = $service->listGlobalMemories($userId);

            expect(array_column($result, 'order'))->toBe([1, 2, 3]);
            expect(array_column($result, 'id'))->toBe([$m3['memory']['id'], $m1['memory']['id'], $m2['memory']['id']]);
        });

        it('only updates memories belonging to the specified user', function (): void {
            [$userId1] = createUserWithAgent('user1@test.com');
            [$userId2] = createUserWithAgent('user2@test.com');
            $service = makeMemoryService();

            $u1m = $service->createGlobalMemory($userId1, ['name' => 'u1_memory']);

            $service->reorderGlobalMemories($userId2, [$u1m['memory']['id']]);

            $m = Memory::find($u1m['memory']['id']);
            expect($m->order)->toBe(1);
        });
    });

    //
    // reorderAgentMemories
    //

    describe('reorderAgentMemories', function (): void {

        it('updates order values for the specified agent only', function (): void {
            [$userId, $agentId1] = createUserWithAgent(AGENT1_EMAIL);
            $agentId2 = Agent::create([
                'user_id' => $userId, 'name' => AGENT2_NAME, 'llm_provider' => 'mock',
                'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true,
            ])->id;
            $service = makeMemoryService();

            $a1 = $service->createAgentMemory($agentId1, $userId, ['name' => 'a1_first']);
            $a2 = $service->createAgentMemory($agentId2, $userId, ['name' => 'a2_first']);
            $a1b = $service->createAgentMemory($agentId1, $userId, ['name' => 'a1_second']);

            $service->reorderAgentMemories($agentId1, $userId, [$a1b['memory']['id'], $a1['memory']['id']]);

            $result = $service->listAgentMemories($agentId1, $userId);
            expect(array_column($result, 'id'))->toBe([$a1b['memory']['id'], $a1['memory']['id']]);

            $result2 = $service->listAgentMemories($agentId2, $userId);
            expect($result2[0]['id'])->toBe($a2['memory']['id'])
                ->and($result2[0]['order'])->toBe(1);
        });

        it('throws when agent does not exist', function (): void {
            [$userId] = createUserWithAgent();
            $service = makeMemoryService();

            expect(fn() => $service->reorderAgentMemories(9999, $userId, []))
                ->toThrow(RuntimeException::class, 'Agent not found');
        });
    });

    //
    // Resource transformation
    //

    describe('resource transformation', function (): void {

        it('includes all expected fields in resource output', function (): void {
            [$userId, $agentId] = createUserWithAgent();
            $service = makeMemoryService();

            $result = $service->createAgentMemory($agentId, $userId, [
                'name'    => 'resource_test',
                'summary' => 'Test summary',
                'content' => 'Test content',
                'order'   => 7,
            ]);

            $memory = $result['memory'];
            expect($memory)->toHaveKeys([
                'id', 'user_id', 'agent_id', 'name', 'summary',
                'content', 'order', 'created_at', 'updated_at',
            ]);
            expect($memory['id'])->toBeInt();
            expect($memory['user_id'])->toBe($userId);
            expect($memory['agent_id'])->toBe($agentId);
            expect($memory['order'])->toBe(1);
            expect($memory['created_at'])->not->toBeEmpty();
            expect($memory['updated_at'])->not->toBeEmpty();
        });
    });
});
