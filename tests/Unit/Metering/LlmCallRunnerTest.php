<?php

declare(strict_types=1);

namespace Tests\Unit\Metering;

use Mockery;
use Psr\Log\NullLogger;
use RuntimeException;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Drivers\ValueObjects\Usage;
use Spora\Metering\Exceptions\UsageLimitExceededException;
use Spora\Metering\LlmCallRunner;
use Spora\Metering\MeteringContextFactory;
use Spora\Metering\MeteringSubject;
use Spora\Metering\UsageDecision;
use Spora\Models\Agent;
use Spora\Models\Task;

/**
 * The runner is the only place a provider is called, so it is the only place
 * the meter can stop a request from leaving.
 */
function meteringRunnerRequest(): LLMRequest
{
    return new LLMRequest(
        systemPrompt: 'You are a helpful AI assistant.',
        messages: [['role' => 'user', 'content' => 'hello']],
        tools: [],
    );
}

function meteringRunnerDriver(int &$calls = 0): LLMDriverInterface
{
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('getProviderName')->andReturn('openai_compatible');
    $driver->allows('getModelName')->andReturn('gpt-4o');
    $driver->allows('complete')->andReturnUsing(static function () use (&$calls): LLMResponse {
        $calls++;
        return new LLMResponse(
            content: 'Hi.',
            toolCalls: [],
            inputTokens: 12,
            outputTokens: 7,
            completionId: 'cmp_metered',
            contentBlocks: [],
            usage: new Usage(inputTokens: 12, outputTokens: 7, provider: 'openai'),
        );
    });

    return $driver;
}

it('records the tokens the provider reported, with the provider and the model', function (): void {
    $userId = bootAuthLayer()->register('metering-runner@example.com', 'Password1!', 'Metering');
    $agentId = $this->makeAgentWithPrincipal([], $userId);

    $task = Task::create([
        'agent_id'     => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'status'       => 'RUNNING',
        'user_prompt'  => 'meter me',
        'step_count'   => 0,
        'max_steps'    => 10,
    ]);

    $meter = new StubUsageMeter();
    $calls = 0;
    $driver = meteringRunnerDriver($calls);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    $runner = new LlmCallRunner($factory, $meter, new MeteringContextFactory(), new NullLogger());
    $runner->complete(Task::find($task->id), Agent::findOrFail($agentId), meteringRunnerRequest());

    expect($calls)->toBe(1)
        ->and($meter->llmUsage)->toHaveCount(1)
        ->and($meter->llmUsage[0][1]->provider)->toBe('openai_compatible')
        ->and($meter->llmUsage[0][1]->model)->toBe('gpt-4o')
        ->and($meter->llmUsage[0][1]->inputTokens())->toBe(12)
        ->and($meter->llmUsage[0][1]->outputTokens())->toBe(7);
});

it('does not send the request when the meter returns a hard decision', function (): void {
    $userId = bootAuthLayer()->register('metering-blocked@example.com', 'Password1!', 'Metering');
    $agentId = $this->makeAgentWithPrincipal([], $userId);

    $task = Task::create([
        'agent_id'     => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'status'       => 'RUNNING',
        'user_prompt'  => 'meter me',
        'step_count'   => 0,
        'max_steps'    => 10,
    ]);

    $meter = new StubUsageMeter();
    $meter->decision = new UsageDecision('Monthly credit limit reached.', UsageDecision::MODE_HARD, 'month:credits');

    $calls = 0;
    $driver = meteringRunnerDriver($calls);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    $runner = new LlmCallRunner($factory, $meter, new MeteringContextFactory(), new NullLogger());

    $thrown = null;

    try {
        $runner->complete(Task::find($task->id), Agent::findOrFail($agentId), meteringRunnerRequest());
    } catch (UsageLimitExceededException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->decision()->reason)->toBe('Monthly credit limit reached.')
        ->and($thrown->decision()->scope)->toBe('month:credits')
        // The whole point: the tokens were never spent.
        ->and($calls)->toBe(0)
        ->and($meter->llmUsage)->toHaveCount(0);
});

it('sends the request when the meter returns a soft decision', function (): void {
    $userId = bootAuthLayer()->register('metering-soft@example.com', 'Password1!', 'Metering');
    $agentId = $this->makeAgentWithPrincipal([], $userId);

    $task = Task::create([
        'agent_id'     => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'status'       => 'RUNNING',
        'user_prompt'  => 'meter me',
        'step_count'   => 0,
        'max_steps'    => 10,
    ]);

    $meter = new StubUsageMeter();
    $meter->decision = new UsageDecision('Halfway to the cap.', UsageDecision::MODE_SOFT, 'month:credits');

    $calls = 0;
    $driver = meteringRunnerDriver($calls);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    $runner = new LlmCallRunner($factory, $meter, new MeteringContextFactory(), new NullLogger());
    $runner->complete(Task::find($task->id), Agent::findOrFail($agentId), meteringRunnerRequest());

    expect($calls)->toBe(1)
        ->and($meter->llmUsage)->toHaveCount(1);
});

it('sends the request when the meter itself throws', function (): void {
    $userId = bootAuthLayer()->register('metering-broken@example.com', 'Password1!', 'Metering');
    $agentId = $this->makeAgentWithPrincipal([], $userId);

    $task = Task::create([
        'agent_id'     => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'status'       => 'RUNNING',
        'user_prompt'  => 'meter me',
        'step_count'   => 0,
        'max_steps'    => 10,
    ]);

    $meter = new StubUsageMeter();
    $meter->evaluateThrows = new RuntimeException('the price book is corrupt');

    $calls = 0;
    $driver = meteringRunnerDriver($calls);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    $runner = new LlmCallRunner($factory, $meter, new MeteringContextFactory(), new NullLogger());
    $runner->complete(Task::find($task->id), Agent::findOrFail($agentId), meteringRunnerRequest());

    expect($calls)->toBe(1);
});

it('asks about the provider request before it leaves', function (): void {
    $userId = bootAuthLayer()->register('metering-subject@example.com', 'Password1!', 'Metering');
    $agentId = $this->makeAgentWithPrincipal([], $userId);

    $task = Task::create([
        'agent_id'     => $agentId,
        'principal_id' => createUserPrincipalPublic($userId),
        'status'       => 'RUNNING',
        'user_prompt'  => 'meter me',
        'step_count'   => 0,
        'max_steps'    => 10,
    ]);

    $meter = new StubUsageMeter();

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn(meteringRunnerDriver());

    $runner = new LlmCallRunner($factory, $meter, new MeteringContextFactory(), new NullLogger());
    $runner->complete(Task::find($task->id), Agent::findOrFail($agentId), meteringRunnerRequest());

    expect($meter->evaluations)->toHaveCount(1)
        ->and($meter->evaluations[0][1])->toBeInstanceOf(MeteringSubject::class)
        ->and($meter->evaluations[0][1]->kind())->toBe(MeteringSubject::KIND_LLM)
        ->and($meter->evaluations[0][0]->taskId)->toBe((int) $task->id);
});
