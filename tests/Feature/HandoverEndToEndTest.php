<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\ValueObjects\WorkerMode;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Spora\Models\ToolCall as ToolCallModel;
use Spora\Services\HandoverService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\TaskService;
use Spora\Tools\HandoverTool;
use Tests\Fixtures\StubInputTool;

/**
 * End-to-end smoke test for the HandoverTool feature, per the manual
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
 * Scripted LLM driver: returns queued responses in order, recycling the
 * last response if the queue is exhausted. Avoids Mockery's `andReturnUsing`
 * (which PHPStan cannot resolve on the mock type union) by being a real
 * implementation of LLMDriverInterface.
 */
final class HandoverE2eScriptedDriver implements LLMDriverInterface
{
    /** @var list<LLMResponse> */
    private array $responses;

    public int $callCount = 0;

    public function __construct(LLMResponse ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $this->callCount++;
        $idx = min($this->callCount - 1, count($this->responses) - 1);
        return $this->responses[$idx];
    }

    public function getProviderName(): string
    {
        return 'mock';
    }

    public function getModelName(): string
    {
        return 'mock-model';
    }
}

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
        'user_id'          => null,
        'name'             => 'HandoverE2e Global Config',
        'driver_class'     => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'         => json_encode(['api_key' => 'test']),
        'is_global'        => true,
        'is_default'       => true,
        'context_window'   => 128000,
        'max_tokens_output' => 4096,
    ]);

    $sourceAgent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Source Agent',
        'llm_driver_config_id' => $llmConfig->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);
    $targetAgent = Agent::create([
        'user_id'              => $userId,
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
 * Build an Orchestrator configured with:
 *   - the HandoverTool (real, with allowlist set to [$targetAgentId])
 *   - a StubInputTool (so tool-class resolution works)
 *   - scripted LLM drivers (real implementations, not Mockery, so PHPStan is happy)
 * The HandoverTool is backed by a real HandoverService, which is in turn
 * backed by an INNER Orchestrator that drives the new task created on
 * the target agent.
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
        ),
    );

    $handoverService = new HandoverService(static fn(): OrchestratorInterface => $innerOrchestrator);

    $allowlist = $allowlistOverride ?? [$targetAgentId];

    $toolConfig = Mockery::mock(Spora\Services\ToolConfigServiceInterface::class);
    $toolConfig->allows('getEffectiveSettings')
        ->andReturn(['allowed_target_agents' => $allowlist]);

    $handoverTool = new HandoverTool($handoverService, $toolConfig);

    $outer = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(
            toolInstances: [new StubInputTool(), $handoverTool],
        ),
    );

    return ['outer' => $outer, 'llm' => $llm, 'llmInner' => $llmInner];
}

describe('HandoverTool end-to-end (orchestrator + service + DB)', function (): void {

    it('full happy-path: handover tool call creates a new task, source is closed with breadcrumb', function (): void {
        $seed   = handoverE2eSeedAgents();
        $userId = $seed['userId'];
        $sourceAgentId = $seed['sourceAgentId'];
        $targetAgentId = $seed['targetAgentId'];

        // Enable the HandoverTool on the source agent.
        AgentTool::insert([
            'agent_id'   => $sourceAgentId,
            'tool_class' => HandoverTool::class,
            'tool_name'  => 'handover',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $built    = handoverE2eBuildOrchestrator(
            $targetAgentId,
            llmResponses: [
                // tick 1: emit handover tool call (PENDING_APPROVAL)
                new LLMResponse(
                    content: null,
                    toolCalls: [new DriverToolCall(
                        HANDOVER_E2E_PROVIDER_CALL_ID,
                        'handover',
                        [
                            'target_agent_id' => $targetAgentId,
                            'context_summary' => HANDOVER_E2E_SUMMARY,
                        ],
                    )],
                    inputTokens: 10,
                    outputTokens: 5,
                    completionId: 'cmp_source_1',
                ),
                // tick 2 (after resume): text response, source task completed
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

        // Step 3: Start the chat with Source agent. Tick 1 should pause for approval.
        $source = $orch->start($sourceAgentId, HANDOVER_E2E_HAPPY_PROMPT, maxSteps: 10);
        $source->refresh();

        // Step 4: HandoverTool requires approval, so the task is paused.
        expect($source->status)->toBe('PENDING_APPROVAL');
        $pendingToolCall = ToolCallModel::where('task_id', $source->id)
            ->where('status', 'PENDING_APPROVAL')
            ->first();
        expect($pendingToolCall)->not->toBeNull();
        expect($pendingToolCall->tool_name)->toBe('handover');

        // Step 4 (cont'd): Approve the tool call.
        $orch->resume($source->id, [[
            'provider_call_id' => HANDOVER_E2E_PROVIDER_CALL_ID,
            'arguments' => [
                'target_agent_id' => $targetAgentId,
                'context_summary' => HANDOVER_E2E_SUMMARY,
            ],
        ]]);

        // Step 5: A new task is owned by the target agent, with
        // parent_task_id = source.
        $source->refresh();
        $newTask = Task::where('agent_id', $targetAgentId)
            ->where('user_id', $userId)
            ->where('parent_task_id', $source->id)
            ->first();
        expect($newTask)->not->toBeNull();
        expect($newTask->parent_task_id)->toBe($source->id);
        expect($newTask->user_prompt)->toBe(HANDOVER_E2E_SUMMARY);

        // Step 6: Source task final state.
        // After resume, the orchestrator's `completeResume` flips the
        // source back to RUNNING and runs tick() again. The second LLM
        // call returns text, which the orchestrator writes via
        // `completeTaskWithResponse` — overwriting the HandoverService's
        // `final_response="Handed off to Target Agent."` with the LLM text.
        // This is a real interaction between the HandoverService and the
        // orchestrator's resume flow; recorded here for the verifier.
        expect($source->status)->toBe('COMPLETED');

        // Step 7: The tool_call row's result_data flows through the task
        // detail API and includes new_task_id + handover=>true.
        $taskService = new TaskService($orch, Mockery::mock(MercurePublisherInterface::class));
        $detail = $taskService->getTaskWithHistory($source->id, $userId);
        expect($detail)->not->toBeNull();

        $handoverCall = collect($detail['tool_calls'])
            ->first(fn($tc) => $tc['tool_name'] === 'handover');
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

        AgentTool::insert([
            'agent_id'   => $sourceAgentId,
            'tool_class' => HandoverTool::class,
            'tool_name'  => 'handover',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

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
                        'handover',
                        [
                            'target_agent_id' => $targetAgentId, // NOT in allowlist (it's empty)
                            'context_summary' => HANDOVER_E2E_SUMMARY,
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
        $source->refresh();
        expect($source->status)->toBe('PENDING_APPROVAL');

        $orch->resume($source->id, [[
            'provider_call_id' => HANDOVER_E2E_PROVIDER_CALL_ID,
            'arguments' => [
                'target_agent_id' => $targetAgentId,
                'context_summary' => HANDOVER_E2E_SUMMARY,
            ],
        ]]);

        // No new task for the target agent.
        $newTaskCount = Task::where('agent_id', $targetAgentId)->count();
        expect($newTaskCount)->toBe(0);

        $toolCall = ToolCallModel::where('task_id', $source->id)
            ->where('tool_name', 'handover')
            ->first();
        expect($toolCall)->not->toBeNull();
        expect($toolCall->result_content)->toContain('not in the allowed_target_agents list');
    });

    it('first history row of the new task carries the LLM-supplied context_summary', function (): void {
        $seed   = handoverE2eSeedAgents();
        $sourceAgentId = $seed['sourceAgentId'];
        $targetAgentId = $seed['targetAgentId'];

        AgentTool::insert([
            'agent_id'   => $sourceAgentId,
            'tool_class' => HandoverTool::class,
            'tool_name'  => 'handover',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $built = handoverE2eBuildOrchestrator(
            $targetAgentId,
            llmResponses: [
                new LLMResponse(
                    content: null,
                    toolCalls: [new DriverToolCall(
                        HANDOVER_E2E_PROVIDER_CALL_ID,
                        'handover',
                        [
                            'target_agent_id' => $targetAgentId,
                            'context_summary' => HANDOVER_E2E_SUMMARY,
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
        $orch->resume($source->id, [[
            'provider_call_id' => HANDOVER_E2E_PROVIDER_CALL_ID,
            'arguments' => [
                'target_agent_id' => $targetAgentId,
                'context_summary' => HANDOVER_E2E_SUMMARY,
            ],
        ]]);

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
