<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\Memory;
use Spora\Tools\AgentMemoryTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\GlobalMemoryTool;

describe('Memory Tools', function (): void {

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Create a user and agent, return [userId, agentId].
     */
    function createMemoryTestUser(string $email = 'memory@example.com'): array
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

    /**
     * Extract tool name from #[Tool] attribute.
     */
    function getToolName(object $tool): string
    {
        $ref = new ReflectionClass($tool);
        $attr = $ref->getAttributes(Tool::class)[0];
        return $attr->newInstance()->name;
    }

    // -------------------------------------------------------------------------
    // AgentMemoryTool
    // -------------------------------------------------------------------------

    describe('AgentMemoryTool', function (): void {

        it('returns correct tool name from attribute', function (): void {
            expect(getToolName(new AgentMemoryTool()))->toBe('memory');
        });

        it('describeAction returns correct description', function (): void {
            $tool = new AgentMemoryTool();

            expect($tool->describeAction(['action' => 'save', 'name' => 'my_memory']))
                ->toContain('memory')
                ->toContain('save')
                ->toContain('my_memory');
        });

        it('list returns empty message when no memories exist', function (): void {
            [, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            $result = $tool->execute(['action' => 'list'], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('No memories found')
                ->and($result->content)->toContain('agent scope');
        });

        it('list returns memories for this agent only', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'my_memory',
                'summary'  => 'My summary',
                'content'  => 'My content',
            ]);

            $otherAgentId = Agent::create([
                'user_id'      => $userId,
                'name'         => 'Other Agent',
                'llm_provider' => 'mock',
                'llm_model'    => 'mock',
                'max_steps'    => 10,
                'is_active'    => true,
            ])->id;
            Memory::create([
                'user_id'  => $userId,
                'agent_id' => $otherAgentId,
                'name'     => 'other_memory',
                'content'  => 'Other content',
            ]);

            $result = $tool->execute(['action' => 'list'], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('my_memory')
                ->and($result->content)->toContain('My summary')
                ->and($result->content)->not->toContain('other_memory');
        });

        it('save creates a new memory', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            $result = $tool->execute([
                'action'  => 'save',
                'name'    => 'project_notes',
                'content' => '# Project Notes\n\nThese are the project notes.',
                'summary' => 'Project notes summary',
                'order'   => 5,
            ], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('Created memory [project_notes]')
                ->and($result->content)->toContain('agent scope');

            $memory = Memory::where('name', 'project_notes')->first();
            expect($memory)->not->toBeNull()
                ->and($memory->agent_id)->toBe($agentId)
                ->and($memory->content)->toContain('Project Notes')
                ->and($memory->summary)->toBe('Project notes summary')
                ->and($memory->order)->toBe(5);
        });

        it('save updates an existing memory', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'updatable',
                'content'  => 'Original content',
                'summary'  => 'Original summary',
            ]);

            $result = $tool->execute([
                'action'  => 'save',
                'name'    => 'updatable',
                'content' => 'Updated content',
            ], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('Updated memory [updatable]');

            $memory = Memory::where('name', 'updatable')->first();
            expect($memory->content)->toBe('Updated content')
                ->and($memory->summary)->toBe('Original summary');
        });

        it('save auto-derives summary from content when not provided', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            $longContent = '<p>This is a <strong>long</strong> content that should have a summary auto-derived from it.</p>';
            $tool->execute([
                'action'  => 'save',
                'name'    => 'auto_summary',
                'content' => $longContent,
            ], $agentId);

            $memory = Memory::where('name', 'auto_summary')->first();
            expect($memory->summary)->not->toBeNull()
                ->and(strlen($memory->summary))->toBeLessThanOrEqual(200)
                ->and($memory->summary)->not->toContain('<p>');
        });

        it('save returns error when name is missing', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            $result = $tool->execute([
                'action'  => 'save',
                'content' => 'Some content without a name',
            ], $agentId);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('name is required');
        });

        it('get retrieves a memory by name', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'get_test',
                'summary'  => 'Summary for get test',
                'content'  => '# Get Test Content\n\nThis is the content.',
            ]);

            $result = $tool->execute([
                'action' => 'get',
                'name'   => 'get_test',
            ], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('# Get Test Content')
                ->and($result->content)->toContain('Summary for get test')
                ->and($result->content)->toContain('This is the content');
        });

        it('get returns error when name is missing', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            $result = $tool->execute(['action' => 'get'], $agentId);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('name is required');
        });

        it('get returns error when memory not found', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            $result = $tool->execute([
                'action' => 'get',
                'name'   => 'nonexistent',
            ], $agentId);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('not found');
        });

        it('delete removes a memory by name', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'to_delete',
                'content'  => 'Will be deleted',
            ]);

            $result = $tool->execute([
                'action' => 'delete',
                'name'   => 'to_delete',
            ], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('Deleted memory [to_delete]');

            expect(Memory::where('name', 'to_delete')->first())->toBeNull();
        });

        it('delete returns error when name is missing', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            $result = $tool->execute(['action' => 'delete'], $agentId);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('name is required');
        });

        it('delete returns error when memory not found', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            $result = $tool->execute([
                'action' => 'delete',
                'name'   => 'nonexistent',
            ], $agentId);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('not found');
        });

        it('returns error for invalid action', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new AgentMemoryTool();

            $result = $tool->execute(['action' => 'invalid_action'], $agentId);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('Invalid action');
        });
    });

    // -------------------------------------------------------------------------
    // GlobalMemoryTool
    // -------------------------------------------------------------------------

    describe('GlobalMemoryTool', function (): void {

        it('returns correct tool name from attribute', function (): void {
            $tool = new GlobalMemoryTool();

            expect(getToolName($tool))->toBe('global_memory');
        });

        it('describeAction returns correct description', function (): void {
            $tool = new GlobalMemoryTool();

            expect($tool->describeAction(['action' => 'save', 'name' => 'my_memory']))
                ->toContain('memory')
                ->toContain('save')
                ->toContain('my_memory');
        });

        it('list returns empty message when no global memories exist', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new GlobalMemoryTool();

            $result = $tool->execute(['action' => 'list'], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('No memories found')
                ->and($result->content)->toContain('global scope');
        });

        it('list returns global memories only (not agent-scoped)', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new GlobalMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'global_pref',
                'content'  => 'Global preference content',
            ]);

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'agent_only',
                'content'  => 'Agent-only content',
            ]);

            $result = $tool->execute(['action' => 'list'], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('global_pref')
                ->and($result->content)->not->toContain('agent_only');
        });

        it('save creates a new global memory', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new GlobalMemoryTool();

            $result = $tool->execute([
                'action'  => 'save',
                'name'    => 'company_policy',
                'content' => 'Our company policy is to be excellent.',
                'summary' => 'Company policy',
            ], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('Created memory [company_policy]')
                ->and($result->content)->toContain('global scope');

            $memory = Memory::where('name', 'company_policy')->first();
            expect($memory)->not->toBeNull()
                ->and($memory->agent_id)->toBeNull()
                ->and($memory->user_id)->toBe($userId);
        });

        it('save updates an existing global memory', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new GlobalMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'global_update',
                'content'  => 'Original global content',
            ]);

            $result = $tool->execute([
                'action'  => 'save',
                'name'    => 'global_update',
                'content' => 'Updated global content',
            ], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('Updated memory [global_update]');

            $memory = Memory::where('name', 'global_update')->first();
            expect($memory->content)->toBe('Updated global content');
        });

        it('get retrieves a global memory by name', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new GlobalMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'global_get',
                'summary'  => 'Global get summary',
                'content'  => '# Global Get\n\nGlobal content here.',
            ]);

            $result = $tool->execute([
                'action' => 'get',
                'name'   => 'global_get',
            ], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('# Global Get')
                ->and($result->content)->toContain('Global get summary')
                ->and($result->content)->toContain('Global content here');
        });

        it('get does not find agent-scoped memory', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new GlobalMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'agent_scoped_get',
                'content'  => 'This should not be found by global tool',
            ]);

            $result = $tool->execute([
                'action' => 'get',
                'name'   => 'agent_scoped_get',
            ], $agentId);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('not found');
        });

        it('delete removes a global memory by name', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new GlobalMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'global_delete',
                'content'  => 'Will be deleted globally',
            ]);

            $result = $tool->execute([
                'action' => 'delete',
                'name'   => 'global_delete',
            ], $agentId);

            expect($result->success)->toBeTrue()
                ->and($result->content)->toContain('Deleted memory [global_delete]');

            expect(Memory::where('name', 'global_delete')->first())->toBeNull();
        });

        it('delete does not delete agent-scoped memory with same name', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new GlobalMemoryTool();

            Memory::create([
                'user_id'  => $userId,
                'agent_id' => null,
                'name'     => 'shared_name',
                'content'  => 'Global version',
            ]);
            Memory::create([
                'user_id'  => $userId,
                'agent_id' => $agentId,
                'name'     => 'shared_name',
                'content'  => 'Agent version',
            ]);

            $tool->execute(['action' => 'delete', 'name' => 'shared_name'], $agentId);

            expect(Memory::where('name', 'shared_name')->whereNull('agent_id')->first())->toBeNull();
            expect(Memory::where('name', 'shared_name')->where('agent_id', $agentId)->first())->not->toBeNull();
        });

        it('returns error for invalid action', function (): void {
            [$userId, $agentId] = createMemoryTestUser();
            $tool = new GlobalMemoryTool();

            $result = $tool->execute(['action' => 'hack'], $agentId);

            expect($result->success)->toBeFalse()
                ->and($result->content)->toContain('Invalid action');
        });
    });

    // -------------------------------------------------------------------------
    // Isolation between users
    // -------------------------------------------------------------------------

    describe('User isolation', function (): void {

        it('users cannot see each others global memories', function (): void {
            $authService1 = bootAuthLayer();
            $userId1 = bootAuth($authService1, 'user1@example.com', 'Password1!');
            $agentId1 = Agent::create([
                'user_id' => $userId1, 'name' => 'Agent 1', 'llm_provider' => 'mock',
                'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true,
            ])->id;

            $authService2 = bootAuthLayer();
            $userId2 = bootAuth($authService2, 'user2@example.com', 'Password1!');
            $agentId2 = Agent::create([
                'user_id' => $userId2, 'name' => 'Agent 2', 'llm_provider' => 'mock',
                'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true,
            ])->id;

            Memory::create([
                'user_id'  => $userId1,
                'agent_id' => null,
                'name'     => 'user1_private',
                'content'  => 'User 1 private global memory',
            ]);

            $tool = new GlobalMemoryTool();
            $result = $tool->execute(['action' => 'list'], $agentId2);

            expect($result->content)->not->toContain('user1_private');
        });

        it('users cannot see each others agent memories', function (): void {
            $authService1 = bootAuthLayer();
            $userId1 = bootAuth($authService1, 'user3@example.com', 'Password1!');
            $agentId1 = Agent::create([
                'user_id' => $userId1, 'name' => 'Agent 1', 'llm_provider' => 'mock',
                'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true,
            ])->id;

            $authService2 = bootAuthLayer();
            $userId2 = bootAuth($authService2, 'user4@example.com', 'Password1!');
            $agentId2 = Agent::create([
                'user_id' => $userId2, 'name' => 'Agent 2', 'llm_provider' => 'mock',
                'llm_model' => 'mock', 'max_steps' => 10, 'is_active' => true,
            ])->id;

            Memory::create([
                'user_id'  => $userId1,
                'agent_id' => $agentId1,
                'name'     => 'user1_agent_memory',
                'content'  => 'User 1 agent memory content',
            ]);

            $tool = new AgentMemoryTool();
            $result = $tool->execute(['action' => 'list'], $agentId2);

            expect($result->content)->not->toContain('user1_agent_memory');
        });
    });
});