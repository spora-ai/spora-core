<?php

declare(strict_types=1);

namespace Tests\Feature;

use Mockery;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\AgentToolOperationOverride;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\AgentService;
use Spora\Services\HandoverService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskCompactionServiceInterface;
use Spora\Services\TaskService;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SubAgentTool;
use Tests\Fixtures\StubInputTool;

/**
 * End-to-end smoke test for the SubAgentTool feature, per the manual
 * checklist in the plan. Drives the real Orchestrator + real DB, with a
 * Mockery-stubbed LLM driver and a real HandoverService. The
 * ToolConfigService is stubbed to return the configured allowlist so we
 * exercise the full tool -> service -> orchestrator -> new-task pipeline.
 *
 * Per the plan: "No mocks for integration tests that already boot the
 * DB via beforeEach." The DB and Eloquent models are real; only the
 * LLM driver, the inner DriverFactory, and the ToolConfigService are
 * stubbed (the latter because seed-time encryption of the multi-select
 * setting is out of scope for this smoke test).
 */

const HANDOVER_E2E_PROVIDER_CALL_ID = 'pc_handover_e2e';
const HANDOVER_E2E_SUMMARY           = 'User asked for a refund; I do not handle billing, please take over.';
const HANDOVER_E2E_HAPPY_PROMPT      = 'I want a refund please';

/**
 * Seed: a user, a global LLMDriverConfiguration, and two agents
 * (Source, Target) under the same owner. Returns the relevant ids.
 *
 * @return array{userId: int, sourceAgentId: int, targetAgentId: int}
 */
function handoverE2eSeedAgents(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = $authService->register(
        "handover-e2e-{$seq}@example.com",
        'Password1!',
        "HandoverE2e{$seq}",
    );

    $llmConfig = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'             => 'HandoverE2e Global Config',
        'driver_class'     => OpenAICompatibleDriver::class,
        'settings'         => json_encode(['api_key' => 'test']),
        'is_global'        => true,
        'is_default'       => true,
        'context_window'   => 128000,
        'max_tokens_output' => 4096,
    ]);

    $sourceAgent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'                 => 'Source Agent',
        'llm_driver_config_id' => $llmConfig->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);
    $targetAgent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'                 => 'Target Agent',
        'llm_driver_config_id' => $llmConfig->id,
        'max_steps'            => 7,
        'is_active'            => true,
    ]);

    return [
        'userId'        => $userId,
        'sourceAgentId' => $sourceAgent->id,
        'targetAgentId' => $targetAgent->id,
    ];
}

/**
 * Enable the SubAgentTool on the source agent, with the `handover`
 * operation switched on.
 *
 * `handover` is opt-in (`enabledByDefault: false`) — an agent without an
 * explicit override never sees it. The override also pins the approval
 * default to auto-approve, which is the shipped default for the op.
 */
function handoverE2eEnableTool(int $sourceAgentId): void
{
    AgentTool::insert([
        'agent_id'   => $sourceAgentId,
        'tool_class' => SubAgentTool::class,
        'tool_name'  => 'sub_agent',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    AgentToolOperationOverride::create([
        'agent_id'                  => $sourceAgentId,
        'tool_class'                => SubAgentTool::class,
        'operation'                 => 'handover',
        'enabled'                   => 1,
        'default_requires_approval' => 0,
    ]);
}

/**
 * Build an Orchestrator configured with:
 *   - the SubAgentTool (real, with allowlist set to [$targetAgentId])
 *   - a StubInputTool (so tool-class resolution works)
 *   - scripted LLM drivers (real implementations, not Mockery, so PHPStan is happy)
 * The SubAgentTool is backed by a real HandoverService, which is in turn
 * backed by an INNER Orchestrator that drives the new task created on the
 * target agent.
 *
 * @param  list<LLMResponse>  $llmResponses       Sequence of responses for the outer driver (defaults to one text).
 * @param  list<LLMResponse>  $llmInnerResponses  Sequence for the inner driver (default one text).
 * @return array{outer: Orchestrator, llm: HandoverE2eScriptedDriver, llmInner: HandoverE2eScriptedDriver}
 */
function handoverE2eBuildOrchestrator(
    int $targetAgentId,
    ?array $allowlistOverride = null,
    array $llmResponses = [],
    array $llmInnerResponses = [],
): array {
    $llmResponses = $llmResponses !== []
        ? $llmResponses
        : [new LLMResponse('Done.', [], 5, 3, 'cmp_default_outer')];
    $llmInnerResponses = $llmInnerResponses !== []
        ? $llmInnerResponses
        : [new LLMResponse('Got it.', [], 5, 3, 'cmp_default_inner')];

    $llm = new HandoverE2eScriptedDriver(...$llmResponses);
    $driverFactory = Mockery::mock(DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn($llm);

    $llmInner = new HandoverE2eScriptedDriver(...$llmInnerResponses);
    $driverFactoryInner = Mockery::mock(DriverFactory::class);
    $driverFactoryInner->allows('makeFromAgent')->andReturn($llmInner);

    $innerOrchestrator = new Orchestrator(
        $driverFactoryInner,
        new OrchestratorConfig(
            toolInstances: [new StubInputTool()],
            agentService: new AgentService(),
        ),
    );

    $handoverService = new HandoverService(static fn(): OrchestratorInterface => $innerOrchestrator);

    $allowlist = $allowlistOverride ?? [$targetAgentId];

    $toolConfig = Mockery::mock(ToolConfigServiceInterface::class);
    $toolConfig->allows('getEffectiveSettings')
        ->andReturn(['allowed_target_agents' => $allowlist]);

    $subAgentService = Mockery::mock(\Spora\Services\SubAgentServiceInterface::class);
    $subAgentService->shouldNotReceive('spawn');

    $handoverTool = new SubAgentTool($handoverService, $subAgentService, $toolConfig);

    $outer = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(
            toolInstances: [new StubInputTool(), $handoverTool],
            agentService: new AgentService(),
        ),
    );

    return ['outer' => $outer, 'llm' => $llm, 'llmInner' => $llmInner];
}

describe('SubAgentTool end-to-end (orchestrator + service + DB)', function (): void {

    it('full happy-path: the handover delegates in the background and the source ends on its own words', function (): void {
        $seed   = handoverE2eSeedAgents();
        $userId = $seed['userId'];
        $sourceAgentId = $seed['sourceAgentId'];
        $targetAgentId = $seed['targetAgentId'];

        handoverE2eEnableTool($sourceAgentId);

        $built    = handoverE2eBuildOrchestrator(
            $targetAgentId,
            llmResponses: [
                // tick 1: emit the handover tool call
                new LLMResponse(
                    content: null,
                    toolCalls: [new DriverToolCall(
                        HANDOVER_E2E_PROVIDER_CALL_ID,
                        'sub_agent',
                        [
                            'op' => 'handover',
                            'target_agent_id' => $targetAgentId,
                            'prompt' => HANDOVER_E2E_SUMMARY,
                        ],
                    )],
                    inputTokens: 10,
                    outputTokens: 5,
                    completionId: 'cmp_source_1',
                ),
                // tick 2: the source takes its own next turn.
                new LLMResponse(
                    content: 'All wrapped up.',
                    toolCalls: [],
                    inputTokens: 5,
                    outputTokens: 3,
                    completionId: 'cmp_source_2',
                ),
            ],
        );
        $orch = $built['outer'];

        // Step 3: Start the chat with Source agent. The handover op is
        // auto-approved, so the call runs inline and the source keeps
        // going — it takes one more LLM turn and ends on its own words.
        $source = $orch->start($sourceAgentId, HANDOVER_E2E_HAPPY_PROMPT, maxSteps: 10);
        claimAndTick($orch, $source->id);
        $source->refresh();

        // Step 4: A new task is owned by the target agent, with
        // parent_task_id = source. It is a background delegation: the
        // source never waits for it, so it is still queued.
        $newTask = Task::where('agent_id', $targetAgentId)
            ->where('principal_id', createUserPrincipalPublic($userId))
            ->where('parent_task_id', $source->id)
            ->first();
        expect($newTask)->not->toBeNull();
        expect($newTask->parent_task_id)->toBe($source->id);
        expect($newTask->user_prompt)->toBe(HANDOVER_E2E_SUMMARY);
        expect($newTask->status)->toBe('QUEUED');

        // Step 5: The source is NOT closed by the handover. It took its own
        // next LLM turn and ended with the LLM's text — not with a canned
        // "Handed off to …" note.
        expect($source->status)->toBe('COMPLETED');
        expect($source->final_response)->toBe('All wrapped up.');

        // Step 6: The breadcrumb records the delegation.
        expect($source->data['handover']['target_task_id'])->toBe($newTask->id);
        expect($source->data['handover']['target_agent_id'])->toBe($targetAgentId);
        expect($source->data['handover']['target_agent_name'])->toBe('Target Agent');

        // Step 7: The tool_call row's result_data flows through the task
        // detail API and includes new_task_id + handover=>true.
        $mercure_mock = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
        /** @var Mockery\MockInterface&MercurePublisherInterface $mercure_mock */
        $taskService = new TaskService($orch, $mercure_mock, Mockery::mock(TaskCompactionServiceInterface::class), null, new \Spora\Services\PrincipalResolver());
        $detail = $taskService->getTaskWithHistory($source->id, $userId);
        expect($detail)->not->toBeNull();

        $handoverCall = collect($detail['tool_calls'])
            ->first(fn($tc) => $tc['tool_name'] === 'sub_agent');
        expect($handoverCall)->not->toBeNull();
        expect($handoverCall['result_data'])->toBeArray();
        expect($handoverCall['result_data']['handover'])->toBeTrue();
        expect($handoverCall['result_data']['new_task_id'])->toBe($newTask->id);
        expect($handoverCall['result_data']['target_agent_id'])->toBe($targetAgentId);
    });

    it('rejects handover to a target not in the allowlist (no new task created)', function (): void {
        $seed   = handoverE2eSeedAgents();
        $sourceAgentId = $seed['sourceAgentId'];
        $targetAgentId = $seed['targetAgentId'];

        handoverE2eEnableTool($sourceAgentId);

        // Build with an EMPTY allowlist so any target_agent_id is rejected.
        $built = handoverE2eBuildOrchestrator(
            $targetAgentId,
            allowlistOverride: [],
            llmResponses: [
                // tick 1: LLM emits the handover tool call (target not in empty allowlist)
                new LLMResponse(
                    content: null,
                    toolCalls: [new DriverToolCall(
                        HANDOVER_E2E_PROVIDER_CALL_ID,
                        'sub_agent',
                        [
                            'op' => 'handover',
                            'target_agent_id' => $targetAgentId, // NOT in allowlist (it's empty)
                            'prompt' => HANDOVER_E2E_SUMMARY,
                        ],
                    )],
                    inputTokens: 10,
                    outputTokens: 5,
                    completionId: 'cmp_blocked_1',
                ),
                // tick 2: text after the rejected tool call
                new LLMResponse('Done.', [], 5, 3, 'cmp_blocked_2'),
            ],
        );
        $orch = $built['outer'];

        $source = $orch->start($sourceAgentId, HANDOVER_E2E_HAPPY_PROMPT, maxSteps: 10);
        claimAndTick($orch, $source->id);
        $source->refresh();

        // No new task for the target agent.
        $newTaskCount = Task::where('agent_id', $targetAgentId)->count();
        expect($newTaskCount)->toBe(0);

        $toolCall = ToolCallModel::where('task_id', $source->id)
            ->where('tool_name', 'sub_agent')
            ->first();
        expect($toolCall)->not->toBeNull();
        expect($toolCall->result_content)->toContain('not in the allowed_target_agents list');
    });

    it('first history row of the new task carries the LLM-supplied prompt', function (): void {
        $seed   = handoverE2eSeedAgents();
        $sourceAgentId = $seed['sourceAgentId'];
        $targetAgentId = $seed['targetAgentId'];

        handoverE2eEnableTool($sourceAgentId);

        $built = handoverE2eBuildOrchestrator(
            $targetAgentId,
            llmResponses: [
                new LLMResponse(
                    content: null,
                    toolCalls: [new DriverToolCall(
                        HANDOVER_E2E_PROVIDER_CALL_ID,
                        'sub_agent',
                        [
                            'op' => 'handover',
                            'target_agent_id' => $targetAgentId,
                            'prompt' => HANDOVER_E2E_SUMMARY,
                        ],
                    )],
                    inputTokens: 10,
                    outputTokens: 5,
                    completionId: 'cmp_source_1',
                ),
                new LLMResponse('Done.', [], 5, 3, 'cmp_source_2'),
            ],
        );
        $orch = $built['outer'];

        $source = $orch->start($sourceAgentId, HANDOVER_E2E_HAPPY_PROMPT, maxSteps: 10);
        claimAndTick($orch, $source->id);

        $newTask = Task::where('parent_task_id', $source->id)->first();
        expect($newTask)->not->toBeNull();

        $firstHistory = TaskHistory::where('task_id', $newTask->id)
            ->orderBy('sequence')
            ->first();
        expect($firstHistory)->not->toBeNull();
        expect($firstHistory->role)->toBe('user');
        expect($firstHistory->content)->toBe(HANDOVER_E2E_SUMMARY);
    });
});
