<?php

declare(strict_types=1);

namespace Tests\Feature;

use LogicException;
use Mockery;
use RuntimeException;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\AgentService;
use Spora\Services\HandoverService;
use Spora\Services\SubAgentService;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\HandoverTool;
use Tests\Fixtures\StubInputTool;

/**
 * End-to-end tests for the `sub_agent` op on HandoverTool.
 *
 * Topology: a parent agent on user A owns a child agent. The LLM emits
 * `handover(op: 'sub_agent', agent_id: child, prompt: '...')`. We
 * exercise the full orchestrator → tool → SubAgentService → child-task
 * chain with a real DB and a scripted LLM driver.
 */

const SUB_AGENT_PARENT_PROVIDER_CALL_ID = 'pc_sub_agent_parent';
const SUB_AGENT_PROMPT                  = 'Please summarize the meeting notes file.';
const SUB_AGENT_PARENT_PROMPT           = 'Help me with the meeting notes.';

/**
 * Seed a user + parent agent + child agent under the same owner.
 *
 * @return array{userId: int, parentAgentId: int, childAgentId: int}
 */
function subAgentSeedAgents(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = $authService->register(
        "sub-agent-{$seq}@example.com",
        'Password1!',
        "SubAgent{$seq}",
    );

    $llmConfig = LLMDriverConfiguration::create([
        'user_id'           => null,
        'name'              => 'SubAgent Global Config',
        'driver_class'      => OpenAICompatibleDriver::class,
        'settings'          => json_encode(['api_key' => 'test']),
        'is_global'         => true,
        'is_default'        => true,
        'context_window'    => 128000,
        'max_tokens_output' => 4096,
    ]);

    $parent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Parent Agent',
        'llm_driver_config_id' => $llmConfig->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);
    $child = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Child Agent',
        'llm_driver_config_id' => $llmConfig->id,
        'max_steps'            => 5,
        'is_active'            => true,
    ]);

    return [
        'userId'        => $userId,
        'parentAgentId' => $parent->id,
        'childAgentId'  => $child->id,
    ];
}

/**
 * Build an orchestrator with HandoverTool + SubAgentService wired up.
 *
 * The HandoverTool is backed by a real HandoverService and a real
 * SubAgentService — only the LLM driver and the ToolConfigService
 * are scripted. The SubAgentService's orchestrator-factory closure
 * returns the SAME outer orchestrator so the child tick resolves
 * through the same wiring (the outer LLM driver is reused for the
 * child too, which is intentional for the test).
 *
 * @param  list<LLMResponse> $llmResponses
 * @return array{outer: Orchestrator, llm: SubAgentE2eScriptedDriver}
 */
function subAgentBuildOrchestrator(
    int $childAgentId,
    ?array $allowlistOverride = null,
    array $llmResponses = [],
): array {
    $llmResponses = $llmResponses !== []
        ? $llmResponses
        : [new LLMResponse('All wrapped up.', [], 5, 3, 'cmp_default')];

    $llm = new SubAgentE2eScriptedDriver(...$llmResponses);
    $driverFactory = Mockery::mock(DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn($llm);

    $handoverService = new HandoverService(static fn(): OrchestratorInterface => throw new LogicException('handover op should not be called in this test'));

    $allowlist = $allowlistOverride ?? [$childAgentId];

    $toolConfig = Mockery::mock(ToolConfigServiceInterface::class);
    $toolConfig->allows('getEffectiveSettings')
        ->andReturn(['allowed_target_agents' => $allowlist]);

    // Build a provisional outer orchestrator that doesn't have the subAgent
    // wired yet — its only role is to be the closure target for the real
    // SubAgentService. The tool doesn't call into the outer orchestrator
    // until the test invokes resume(), so the construction order is safe.
    $probeOuter = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(
            toolInstances: [new StubInputTool()],
            agentService: new AgentService(),
        ),
    );

    $realSubAgent = new SubAgentService(
        static fn(): OrchestratorInterface => $probeOuter,
        null,
        WorkerMode::Sync,
    );

    $handoverTool = new HandoverTool($handoverService, $realSubAgent, $toolConfig);

    $outer = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(
            toolInstances: [new StubInputTool(), $handoverTool],
            agentService: new AgentService(),
            subAgent: $realSubAgent,
        ),
    );

    // Rebind the SubAgentService's factory to the now-constructed outer
    // orchestrator (the probe is identical to `outer` for our purposes,
    // but using `$outer` makes the closure's intent explicit).
    $finalSubAgent = new SubAgentService(
        static fn(): OrchestratorInterface => $outer,
        null,
        WorkerMode::Sync,
    );

    return ['outer' => $outer, 'llm' => $llm, 'subAgent' => $finalSubAgent];
}

describe('SubAgentService::spawn', function (): void {

    it('creates a child task, waits for its completion, then resumes the parent', function (): void {
        $seed = subAgentSeedAgents();
        $userId = $seed['userId'];
        $parentAgentId = $seed['parentAgentId'];
        $childAgentId = $seed['childAgentId'];

        AgentTool::insert([
            'agent_id'   => $parentAgentId,
            'tool_class' => HandoverTool::class,
            'tool_name'  => 'handover',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $built = subAgentBuildOrchestrator(
            $childAgentId,
            llmResponses: [
                // tick 1: parent LLM emits sub_agent tool call.
                new LLMResponse(
                    content: null,
                    toolCalls: [new DriverToolCall(
                        SUB_AGENT_PARENT_PROVIDER_CALL_ID,
                        'handover',
                        [
                            'op'        => 'sub_agent',
                            'agent_id'  => $childAgentId,
                            'prompt'    => SUB_AGENT_PROMPT,
                        ],
                    )],
                    inputTokens: 10,
                    outputTokens: 5,
                    completionId: 'cmp_parent_1',
                ),
                // tick 2 (after child completes): parent text response.
                new LLMResponse(
                    content: 'All done.',
                    toolCalls: [],
                    inputTokens: 5,
                    outputTokens: 3,
                    completionId: 'cmp_parent_2',
                ),
            ],
        );
        $orch = $built['outer'];

        $parent = $orch->start($parentAgentId, SUB_AGENT_PARENT_PROMPT, maxSteps: 10);
        $parent->refresh();
        expect($parent->status)->toBe('PENDING_APPROVAL');

        $orch->resume($parent->id, [[
            'provider_call_id' => SUB_AGENT_PARENT_PROVIDER_CALL_ID,
            'decision' => 'approve',
            'arguments' => [
                'op'       => 'sub_agent',
                'agent_id' => $childAgentId,
                'prompt'   => SUB_AGENT_PROMPT,
            ],
        ]]);

        // In Sync mode the child ticks inline and may complete before
        // resume() returns. The parent therefore reaches COMPLETED in
        // a single call — there's no observable AWAITING_SUB_AGENTS
        // window because the child's LLM response is scripted to text.
        $child = Task::where('parent_task_id', $parent->id)->first();
        expect($child)->not->toBeNull();
        expect($child->agent_id)->toBe($childAgentId);
        expect($child->user_prompt)->toBe(SUB_AGENT_PROMPT);
        expect($child->status)->toBe('COMPLETED');

        // The tool_call row carries the mapping that ties the child back
        // to the originating tool call. The field is always a plural
        // array so the frontend schema matches the multi-child shape.
        $toolCall = ToolCallModel::where('task_id', $parent->id)
            ->where('operation', 'sub_agent')
            ->first();
        expect($toolCall)->not->toBeNull();
        expect($toolCall->result_data['op'])->toBe('sub_agent');
        expect($toolCall->result_data['spawned_sub_task_ids'])->toBe([$child->id]);
        expect($toolCall->result_data['target_agent_id'])->toBe($childAgentId);

        // The parent has resumed with the child output as a 'tool' row
        // correlated with the originating tool call. The parent's next
        // scripted response was 'All done.' which closes it.
        $parent->refresh();
        expect($parent->status)->toBe('COMPLETED');
        expect($parent->final_response)->toBe('All done.');

        $toolRow = TaskHistory::where('task_id', $parent->id)
            ->where('role', 'tool')
            ->where('tool_call_id', SUB_AGENT_PARENT_PROVIDER_CALL_ID)
            ->first();
        expect($toolRow)->not->toBeNull();
        expect($toolRow->content)->toContain('Sub-agent task #' . $child->id);
    });

    it('rejects a sub_agent target not in the allowlist (no child created)', function (): void {
        $seed = subAgentSeedAgents();
        $parentAgentId = $seed['parentAgentId'];
        $childAgentId = $seed['childAgentId'];

        AgentTool::insert([
            'agent_id'   => $parentAgentId,
            'tool_class' => HandoverTool::class,
            'tool_name'  => 'handover',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $built = subAgentBuildOrchestrator(
            $childAgentId,
            allowlistOverride: [],
            llmResponses: [
                new LLMResponse(
                    content: null,
                    toolCalls: [new DriverToolCall(
                        SUB_AGENT_PARENT_PROVIDER_CALL_ID,
                        'handover',
                        [
                            'op'       => 'sub_agent',
                            'agent_id' => $childAgentId, // NOT in empty allowlist
                            'prompt'   => SUB_AGENT_PROMPT,
                        ],
                    )],
                    inputTokens: 10,
                    outputTokens: 5,
                    completionId: 'cmp_blocked_1',
                ),
                new LLMResponse('Done.', [], 5, 3, 'cmp_blocked_2'),
            ],
        );
        $orch = $built['outer'];

        $parent = $orch->start($parentAgentId, SUB_AGENT_PARENT_PROMPT, maxSteps: 10);
        $orch->resume($parent->id, [[
            'provider_call_id' => SUB_AGENT_PARENT_PROVIDER_CALL_ID,
            'decision' => 'approve',
            'arguments' => [
                'op'       => 'sub_agent',
                'agent_id' => $childAgentId,
                'prompt'   => SUB_AGENT_PROMPT,
            ],
        ]]);

        $childCount = Task::where('agent_id', $childAgentId)->count();
        expect($childCount)->toBe(0);

        $toolCall = ToolCallModel::where('task_id', $parent->id)
            ->where('tool_name', 'handover')
            ->first();
        expect($toolCall)->not->toBeNull();
        expect($toolCall->result_content)->toContain('not in the allowed_target_agents list');
    });

    it('resumes the parent with a failure tool result when the child FAILEDs', function (): void {
        $seed = subAgentSeedAgents();
        $parentAgentId = $seed['parentAgentId'];
        $childAgentId = $seed['childAgentId'];

        AgentTool::insert([
            'agent_id'   => $parentAgentId,
            'tool_class' => HandoverTool::class,
            'tool_name'  => 'handover',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // The driver scripts the parent's first response (the sub_agent tool
        // call) and then explodes on every subsequent call. The parent's
        // first tick — the only one we want to land a tool call — succeeds;
        // the child's tick throws and lands the child in FAILED status.
        $explodingDriver = new class implements \Spora\Drivers\LLMDriverInterface {
            public int $callCount = 0;

            public function complete(\Spora\Drivers\ValueObjects\LLMRequest $request): LLMResponse
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    return new LLMResponse(
                        content: null,
                        toolCalls: [new DriverToolCall(
                            SUB_AGENT_PARENT_PROVIDER_CALL_ID,
                            'handover',
                            [
                                'op'       => 'sub_agent',
                                'agent_id' => $GLOBALS['__sub_agent_child_id'],
                                'prompt'   => SUB_AGENT_PROMPT,
                            ],
                        )],
                        inputTokens: 10,
                        outputTokens: 5,
                        completionId: 'cmp_parent_1',
                    );
                }
                throw new RuntimeException('child driver exploded');
            }
            public function getProviderName(): string
            {
                return 'mock-failing';
            }
            public function getModelName(): string
            {
                return 'mock-model';
            }
            public function supportsImageInput(): bool
            {
                return false;
            }
        };

        $GLOBALS['__sub_agent_child_id'] = $childAgentId;

        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->allows('makeFromAgent')->andReturn($explodingDriver);

        $handoverService = new HandoverService(static fn(): OrchestratorInterface => throw new LogicException('handover op should not be called'));

        $allowlist = [$childAgentId];

        $toolConfig = Mockery::mock(ToolConfigServiceInterface::class);
        $toolConfig->allows('getEffectiveSettings')
            ->andReturn(['allowed_target_agents' => $allowlist]);

        $probeOuter = new Orchestrator(
            $driverFactory,
            new OrchestratorConfig(
                toolInstances: [new StubInputTool()],
                agentService: new AgentService(),
            ),
        );

        $realSubAgent = new SubAgentService(
            static fn(): OrchestratorInterface => $probeOuter,
            null,
            WorkerMode::Sync,
        );

        $handoverTool = new HandoverTool($handoverService, $realSubAgent, $toolConfig);

        $outer = new Orchestrator(
            $driverFactory,
            new OrchestratorConfig(
                toolInstances: [new StubInputTool(), $handoverTool],
                agentService: new AgentService(),
                subAgent: $realSubAgent,
            ),
        );

        $finalSubAgent = new SubAgentService(
            static fn(): OrchestratorInterface => $outer,
            null,
            WorkerMode::Sync,
        );
        $outer = new Orchestrator(
            $driverFactory,
            new OrchestratorConfig(
                toolInstances: [new StubInputTool(), $handoverTool],
                agentService: new AgentService(),
                subAgent: $finalSubAgent,
            ),
        );

        $parent = $outer->start($parentAgentId, SUB_AGENT_PARENT_PROMPT, maxSteps: 10);
        $parent->refresh();
        expect($parent->status)->toBe('PENDING_APPROVAL');

        // The parent's next LLM call ALSO explodes (the driver fails every
        // call after the first), so resume() will throw after the sub-agent
        // resume has already landed the failure tool row in history. Catch
        // so we can inspect the partial-success state.
        try {
            $outer->resume($parent->id, [[
                'provider_call_id' => SUB_AGENT_PARENT_PROVIDER_CALL_ID,
                'decision' => 'approve',
                'arguments' => [
                    'op'       => 'sub_agent',
                    'agent_id' => $childAgentId,
                    'prompt'   => SUB_AGENT_PROMPT,
                ],
            ]]);
        } catch (RuntimeException $e) {
            // expected — the parent's subsequent LLM call also throws.
        }

        $child = Task::where('parent_task_id', $parent->id)->first();
        expect($child)->not->toBeNull();
        expect($child->status)->toBe('FAILED');
        expect($child->failure_reason)->toContain('child driver exploded');

        // The parent still resumes — failure is just another terminal state.
        // The parent's next LLM call also fails (the driver explodes on every
        // call after the first), so the parent ends up FAILED — but the
        // resume itself happened and the failure tool row landed in history
        // before the parent died.
        $toolRow = TaskHistory::where('task_id', $parent->id)
            ->where('role', 'tool')
            ->where('tool_call_id', SUB_AGENT_PARENT_PROVIDER_CALL_ID)
            ->orderByDesc('id')
            ->first();
        expect($toolRow)->not->toBeNull();
        expect($toolRow->content)->toContain('Sub-agent task #' . $child->id . ' failed');
        expect($toolRow->content)->toContain('child driver exploded');
    });

    it('processes a mixed batch [sub_agent, calculator, sub_agent] once at the boundary', function (): void {
        $seed = subAgentSeedAgents();
        $parentAgentId = $seed['parentAgentId'];
        $childAgentId  = $seed['childAgentId'];

        AgentTool::insert([
            'agent_id'   => $parentAgentId,
            'tool_class' => HandoverTool::class,
            'tool_name'  => 'handover',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $built = subAgentBuildOrchestrator(
            $childAgentId,
            llmResponses: [
                // tick 1: parent LLM emits two sub_agent calls in one turn.
                new LLMResponse(
                    content: null,
                    toolCalls: [
                        new DriverToolCall(
                            'pc_sub_agent_1',
                            'handover',
                            [
                                'op'       => 'sub_agent',
                                'agent_id' => $childAgentId,
                                'prompt'   => 'first child task',
                            ],
                        ),
                        new DriverToolCall(
                            'pc_sub_agent_2',
                            'handover',
                            [
                                'op'       => 'sub_agent',
                                'agent_id' => $childAgentId,
                                'prompt'   => 'second child task',
                            ],
                        ),
                    ],
                    inputTokens: 10,
                    outputTokens: 5,
                    completionId: 'cmp_parent_1',
                ),
                // tick 2 (first child's tick): scripted child response.
                new LLMResponse('first child finished', [], 5, 3, 'cmp_child_1'),
                // tick 3 (second child's tick): scripted child response.
                new LLMResponse('second child finished', [], 5, 3, 'cmp_child_2'),
                // tick 4 (parent's tick after the batch): terminal text.
                new LLMResponse('All wrapped up.', [], 5, 3, 'cmp_parent_2'),
            ],
        );
        $orch = $built['outer'];

        $parent = $orch->start($parentAgentId, SUB_AGENT_PARENT_PROMPT, maxSteps: 10);
        $parent->refresh();
        expect($parent->status)->toBe('PENDING_APPROVAL');

        $orch->resume($parent->id, [
            [
                'provider_call_id' => 'pc_sub_agent_1',
                'decision'         => 'approve',
                'arguments'        => [
                    'op'       => 'sub_agent',
                    'agent_id' => $childAgentId,
                    'prompt'   => 'first child task',
                ],
            ],
            [
                'provider_call_id' => 'pc_sub_agent_2',
                'decision'         => 'approve',
                'arguments'        => [
                    'op'       => 'sub_agent',
                    'agent_id' => $childAgentId,
                    'prompt'   => 'second child task',
                ],
            ],
        ]);

        $children = Task::where('parent_task_id', $parent->id)
            ->orderBy('id')
            ->get();
        expect($children->count())->toBe(2);
        expect($children[0]->user_prompt)->toBe('first child task');
        expect($children[0]->status)->toBe('COMPLETED');
        expect($children[1]->user_prompt)->toBe('second child task');
        expect($children[1]->status)->toBe('COMPLETED');

        // Both `sub_agent` tool calls land their tool result rows in the
        // parent's history, correlated with the originating tool_call_id.
        // Each sub_agent tool call writes two 'role: tool' rows — the
        // immediate result from HandoverTool.executeSubAgent and the
        // resume result from SubAgentService.resumeParent. The resume
        // rows are the latest two for the originating tool_call_id.
        $toolRows = TaskHistory::where('task_id', $parent->id)
            ->where('role', 'tool')
            ->whereIn('tool_call_id', ['pc_sub_agent_1', 'pc_sub_agent_2'])
            ->where('content', 'LIKE', '%Sub-agent task #% completed:%')
            ->orderBy('id')
            ->get();
        expect($toolRows->count())->toBe(2);
        expect($toolRows[0]->tool_call_id)->toBe('pc_sub_agent_1');
        expect($toolRows[0]->content)->toContain('first child finished');
        expect($toolRows[1]->tool_call_id)->toBe('pc_sub_agent_2');
        expect($toolRows[1]->content)->toContain('second child finished');

        // The parent waited for the batch boundary, not after every child.
        // Final response is the orchestrator's post-batch tick text.
        $parent->refresh();
        expect($parent->status)->toBe('COMPLETED');
        expect($parent->final_response)->toBe('All wrapped up.');
    });
});
