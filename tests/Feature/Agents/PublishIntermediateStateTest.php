<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\ToolCall as DriverToolCall;
use Spora\Models\Agent;
use Spora\Models\AgentTool;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Todo\TodoStoreRegistry;
use Spora\Tools\TodoTool;
use Tests\Fixtures\StubInputTool;
use Tests\Support\TestCapturingMercure;

defined('PUBINT_TEST_PASSWORD') || define('PUBINT_TEST_PASSWORD', 'Password1!');

function pubIntSeedAgent(int $userId, string $toolClass, string $toolName): Agent
{
    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'             => 'PubInt Config',
        'driver_class'     => Spora\Drivers\OpenAICompatibleDriver::class,
        'settings'         => json_encode(['api_key' => 'test']),
        'is_global'        => true,
        'is_default'       => true,
        'context_window'   => 128000,
        'max_tokens_output' => 4096,
    ]);

    $agent = Agent::create([
        'principal_id'       => createUserPrincipalPublic($userId),
        'name'               => 'PubIntAgent',
        'llm_driver_config_id' => $config->id,
        'max_steps'          => 10,
        'is_active'          => true,
    ]);

    AgentTool::insert([
        'agent_id'   => $agent->id,
        'tool_class' => $toolClass,
        'tool_name'  => $toolName,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return $agent;
}

function pubIntMockLlmForAutoApprovedTool(string $toolName, string $secondTurnText = 'Done.'): LLMDriverInterface
{
    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');
    $mock->allows('supportsImageInput')->andReturn(false);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount, $toolName, $secondTurnText) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [
                new DriverToolCall('call_pub_1', $toolName, []),
            ], 5, 3, 'cmp_pub_1');
        }
        return new LLMResponse($secondTurnText, [], 5, 3, 'cmp_pub_2');
    });

    return $mock;
}

test('publishIntermediateState includes tasks.data in the Mercure payload', function (): void {
    $userId = bootAuthLayer()->register('pubint-echo@example.com', PUBINT_TEST_PASSWORD, 'PubInt Echo');
    $agent  = pubIntSeedAgent($userId, StubInputTool::class, 'stub_input');

    $mercure = new TestCapturingMercure();
    $mock    = pubIntMockLlmForAutoApprovedTool('stub_input');
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($mock);

    $orch = new Orchestrator(
        $factory,
        new OrchestratorConfig(
            toolInstances: [new StubInputTool()],
            mercure: $mercure,
        ),
    );

    $task = $orch->start($agent->id, 'data echo test', maxSteps: 10);
    claimAndTick($orch, $task->id);

    expect($mercure->principalEvents)->not->toBeEmpty();
    $payload = $mercure->principalEvents[0]['data'];
    expect($payload)->toHaveKey('data');

    // `tasks.data` is cast to `array`; a task that no tool has touched
    // still serialises as a present-but-empty array.
    expect($payload['data'])->toBe([]);
})->afterEach(fn() => Spora\Core\Database::resetBootState());

test('publishIntermediateState reflects the latest TodoTool write in data.todos', function (): void {
    $userId = bootAuthLayer()->register('pubint-todo@example.com', PUBINT_TEST_PASSWORD, 'PubInt Todo');
    $agent  = pubIntSeedAgent($userId, TodoTool::class, 'todo');

    $mercure = new TestCapturingMercure();

    $callCount = 0;
    $mock      = Mockery::mock(LLMDriverInterface::class);
    $mock->allows('getProviderName')->andReturn('mock');
    $mock->allows('getModelName')->andReturn('mock-model');
    $mock->allows('supportsImageInput')->andReturn(false);
    $mock->allows('complete')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            return new LLMResponse(null, [
                new DriverToolCall('call_todo_1', 'todo', [
                    'op'    => 'write',
                    'todos' => [
                        ['content' => 'plan the migration', 'status' => 'in_progress', 'id' => 'plan'],
                        ['content' => 'run the migration',  'status' => 'pending',     'id' => 'run'],
                    ],
                ]),
            ], 5, 3, 'cmp_todo_1');
        }
        return new LLMResponse('Done.', [], 5, 3, 'cmp_todo_2');
    });

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($mock);

    $orch = new Orchestrator(
        $factory,
        new OrchestratorConfig(
            toolInstances: [new TodoTool(new TodoStoreRegistry())],
            mercure: $mercure,
        ),
    );

    $task = $orch->start($agent->id, 'todo live update', maxSteps: 10);
    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED');

    // The post-tool-batch publish must carry the just-written todos
    // payload so the chat UI can update without a `/show` poll.
    $postBatch = collect($mercure->principalEvents)
        ->first(fn(array $event) => isset($event['data']['data']['todos']));

    expect($postBatch)->not->toBeNull();
    $todos = $postBatch['data']['data']['todos'];
    expect($todos['items'])->toHaveCount(2)
        ->and($todos['items'][0]['id'])->toBe('plan')
        ->and($todos['items'][0]['content'])->toBe('plan the migration')
        ->and($todos['items'][0]['status'])->toBe('in_progress')
        ->and($todos['items'][1]['id'])->toBe('run')
        ->and($todos['items'][1]['status'])->toBe('pending');
})->afterEach(fn() => Spora\Core\Database::resetBootState());

test('publishIntermediateState keeps data untouched when status flips elsewhere in the tick', function (): void {
    $userId = bootAuthLayer()->register('pubint-keep@example.com', PUBINT_TEST_PASSWORD, 'PubInt Keep');
    $agent  = pubIntSeedAgent($userId, StubInputTool::class, 'stub_input');

    // Seed `tasks.data` with a payload that has nothing to do with todos —
    // mirrors the HandoverTool's `data.spawned_sub_task_ids` and similar
    // tool-managed keys.
    $seededData = [
        'spawned_sub_task_ids' => [101, 102],
        'custom_marker'        => 'keep-me',
    ];
    $task = Task::create([
        'agent_id'        => $agent->id,
        'principal_id'    => $agent->principal_id,
        'trigger_user_id' => $userId,
        'status'          => 'QUEUED',
        'user_prompt'     => 'preserve data through status flips',
        'max_steps'       => 5,
        'data'            => $seededData,
    ]);

    $mercure = new TestCapturingMercure();
    $mock    = pubIntMockLlmForAutoApprovedTool('stub_input');
    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($mock);

    $orch = new Orchestrator(
        $factory,
        new OrchestratorConfig(
            toolInstances: [new StubInputTool()],
            mercure: $mercure,
        ),
    );

    claimAndTick($orch, $task->id);

    $task->refresh();
    expect($task->status)->toBe('COMPLETED');

    expect($mercure->principalEvents)->not->toBeEmpty();
    $payload = $mercure->principalEvents[0]['data'];

    expect($payload)->toHaveKey('data')
        ->and($payload['data'])->toBe($seededData)
        ->and($payload['data']['spawned_sub_task_ids'])->toBe([101, 102])
        ->and($payload['data']['custom_marker'])->toBe('keep-me');
})->afterEach(fn() => Spora\Core\Database::resetBootState());
