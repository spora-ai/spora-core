<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\TickPhaseRunner;
use Spora\Agents\ValueObjects\HistoryMessageContext;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\ContentBlock;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\Usage;
use Spora\Models\Agent;
use Spora\Models\Task;
use Spora\Models\TaskHistory;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Verifies that the LLM response (contentBlocks + Usage) flows from the
 * driver through TickPhaseRunner into the task_history.content_blocks
 * column and the usage table via the real Orchestrator transaction.
 */
test('TickPhaseRunner persists contentBlocks and Usage into the DB', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('usage@example.com', 'Password1!', 'UsageUser');
    simulateLoggedInSession($userId, 'usage@example.com');

    $agent = Agent::create([
        'user_id' => $userId,
        'name' => 'UsageAgent',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    $task = Task::create([
        'user_id' => $userId,
        'agent_id' => $agent->id,
        'status' => 'RUNNING',
        'user_prompt' => 'hi',
        'max_steps' => 5,
    ]);

    $usage = new Usage(
        inputTokens: 7,
        outputTokens: 11,
        cachedTokens: 4,
        provider: 'openai',
    );
    $blocks = [
        ContentBlock::thinking('plan', 'sig-1'),
        ContentBlock::text('Done.'),
    ];
    $response = new LLMResponse(
        content: 'Done.',
        toolCalls: [],
        inputTokens: 7,
        outputTokens: 11,
        completionId: 'cmp_1',
        contentBlocks: $blocks,
        usage: $usage,
        displayReasoning: 'plan',
    );

    $driver = new AnthropicCompatibleDriver(
        apiKey: 'k',
        model: 'claude-3-5-sonnet-20241022',
        baseUrl: 'https://api.anthropic.com',
        httpClient: new MockHttpClient(),
        logger: new NullLogger(),
    );

    $driverFactory = Mockery::mock(DriverFactory::class);
    $driverFactory->allows('makeFromAgent')->andReturn($driver);

    $orchestrator = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(logger: new NullLogger()),
    );

    $runner = new TickPhaseRunner(
        orchestrator: $orchestrator,
        driverFactory: $driverFactory,
        toolInstances: [],
        logger: new NullLogger(),
    );

    $reflect = new ReflectionMethod($runner, 'recordAssistantToolCallBatch');
    $reflect->invoke($runner, $task, $response);

    $row = TaskHistory::where('task_id', $task->id)->orderByDesc('sequence')->first();
    expect($row)->not->toBeNull();
    expect($row->content_blocks)->toBeArray();
    expect($row->content_blocks)->toHaveCount(2);
    expect($row->content_blocks[0])->toMatchArray(['type' => 'thinking', 'text' => 'plan', 'signature' => 'sig-1']);
    expect($row->content_blocks[1])->toMatchArray(['type' => 'text', 'text' => 'Done.']);

    $persistedUsage = Capsule::table('usage')->where('task_history_id', $row->id)->first();
    expect($persistedUsage)->not->toBeNull();
    expect((int) $persistedUsage->input_tokens)->toBe(7);
    expect((int) $persistedUsage->output_tokens)->toBe(11);
    expect((int) $persistedUsage->cached_tokens)->toBe(4);
    expect($persistedUsage->provider)->toBe('openai');
});


test('Orchestrator::appendHistory persists displayReasoning into the reasoning column', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('reasoning@example.com', 'Password1!', 'ReasoningUser');
    simulateLoggedInSession($userId, 'reasoning@example.com');

    $agent = Agent::create([
        'user_id' => $userId,
        'name' => 'ReasoningAgent',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    $task = Task::create([
        'user_id' => $userId,
        'agent_id' => $agent->id,
        'status' => 'RUNNING',
        'user_prompt' => 'hi',
        'max_steps' => 5,
    ]);

    $driverFactory = Mockery::mock(DriverFactory::class);
    $orchestrator = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(logger: new NullLogger()),
    );

    // The MiniMax-M3 model wraps reasoning in `<think>…</think>` tags
    // inside the assistant text. ThinkingTagExtractor surfaces that as
    // LLMResponse::displayReasoning, which the orchestrator must persist
    // so the admin UI can render the per-message Reasoning foldout.
    $orchestrator->appendHistory(
        taskId: $task->id,
        role: 'assistant',
        content: 'Hi! How can I help?',
        context: new HistoryMessageContext(
            displayReasoning: 'The user is just saying hello.',
        ),
    );

    $row = TaskHistory::where('task_id', $task->id)->orderByDesc('sequence')->first();
    expect($row)->not->toBeNull();
    expect($row->reasoning)->toBe('The user is just saying hello.');
    expect($row->content)->toBe('Hi! How can I help?');
    expect($row->content_blocks)->toBeNull();
});

test('Orchestrator::appendHistory does NOT duplicate displayReasoning when a signed thinking block is present', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('signed@example.com', 'Password1!', 'SignedUser');
    simulateLoggedInSession($userId, 'signed@example.com');

    $agent = Agent::create([
        'user_id' => $userId,
        'name' => 'SignedAgent',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    $task = Task::create([
        'user_id' => $userId,
        'agent_id' => $agent->id,
        'status' => 'RUNNING',
        'user_prompt' => 'hi',
        'max_steps' => 5,
    ]);

    $driverFactory = Mockery::mock(DriverFactory::class);
    $orchestrator = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(logger: new NullLogger()),
    );

    // Anthropic extended thinking provides a signed `thinking` content
    // block. The structured block is the source of truth — duplicating the
    // text into the legacy `reasoning` column would risk drift and bloat
    // the row.
    $orchestrator->appendHistory(
        taskId: $task->id,
        role: 'assistant',
        content: 'The answer is 42.',
        context: new HistoryMessageContext(
            contentBlocks: [
                ContentBlock::thinking('plan', 'sig-xyz'),
                ContentBlock::text('The answer is 42.'),
            ],
            displayReasoning: 'plan',
        ),
    );

    $row = TaskHistory::where('task_id', $task->id)->orderByDesc('sequence')->first();
    expect($row)->not->toBeNull();
    expect($row->reasoning)->toBeNull();
    expect($row->content_blocks)->toHaveCount(2);
    expect($row->content_blocks[0])->toMatchArray(['type' => 'thinking', 'text' => 'plan', 'signature' => 'sig-xyz']);
});

test('Orchestrator::appendHistory skips the reasoning write when displayReasoning is null', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('nores@example.com', 'Password1!', 'NoResUser');
    simulateLoggedInSession($userId, 'nores@example.com');

    $agent = Agent::create([
        'user_id' => $userId,
        'name' => 'NoResAgent',
        'max_steps' => 5,
        'is_active' => true,
    ]);
    $task = Task::create([
        'user_id' => $userId,
        'agent_id' => $agent->id,
        'status' => 'RUNNING',
        'user_prompt' => 'hi',
        'max_steps' => 5,
    ]);

    $driverFactory = Mockery::mock(DriverFactory::class);
    $orchestrator = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(logger: new NullLogger()),
    );

    $orchestrator->appendHistory(
        taskId: $task->id,
        role: 'assistant',
        content: 'Just a plain answer.',
    );

    $row = TaskHistory::where('task_id', $task->id)->orderByDesc('sequence')->first();
    expect($row)->not->toBeNull();
    expect($row->reasoning)->toBeNull();
    expect($row->content)->toBe('Just a plain answer.');
});
