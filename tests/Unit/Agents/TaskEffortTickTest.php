<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Agents\TaskStartOptions;
use Spora\Core\SecurityManager;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Services\EffortService;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;

/**
 * The effort the tick sends.
 *
 * The task row is the last word: the tick copies `tasks.effort` onto the
 * request and never consults the configuration again. The row is written
 * when the task starts, and the chain that decides it — the agent's own
 * default, then the configuration's, then nothing — belongs to
 * {@see EffortService::defaultFor()}.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

it('sends the effort the task row carries', function (): void {
    [$agentId] = effortTickSeedAgent('high');

    $captured = null;
    $orch = effortTickOrchestrator($captured);

    $task = $orch->start($agentId, 'hello', maxSteps: 5, options: new TaskStartOptions(effort: 'low'));
    Task::where('id', $task->id)->update(['effort' => 'low']);

    claimAndTick($orch, $task->id);

    expect($captured?->effort)->toBe('low');
});

it('sends the effort the request named, not the configured default', function (): void {
    [$agentId] = effortTickSeedAgent('high');

    $captured = null;
    $orch = effortTickOrchestrator($captured);

    $task = $orch->start($agentId, 'hello', maxSteps: 5, options: new TaskStartOptions(effort: 'low'));

    expect($task->effort)->toBe('low');

    claimAndTick($orch, $task->id);

    expect($captured?->effort)->toBe('low');
});

it('falls back to the agent\'s own default when the request names no effort', function (): void {
    [$agentId] = effortTickSeedAgent('medium', 'high');

    $captured = null;
    $orch = effortTickOrchestrator($captured);

    $task = $orch->start($agentId, 'hello', maxSteps: 5);

    expect($task->effort)->toBe('high');

    claimAndTick($orch, $task->id);

    expect($captured?->effort)->toBe('high');
});

it('falls back to the configuration\'s default when the agent names none', function (): void {
    [$agentId] = effortTickSeedAgent('medium');

    $captured = null;
    $orch = effortTickOrchestrator($captured);

    $task = $orch->start($agentId, 'hello', maxSteps: 5);

    expect($task->effort)->toBe('medium');

    claimAndTick($orch, $task->id);

    expect($captured?->effort)->toBe('medium');
});

it('sends nothing when neither the agent nor the configuration names an effort', function (): void {
    [$agentId] = effortTickSeedAgent(null);

    $captured = null;
    $orch = effortTickOrchestrator($captured);

    $task = $orch->start($agentId, 'hello', maxSteps: 5);

    expect($task->effort)->toBeNull();

    claimAndTick($orch, $task->id);

    expect($captured?->effort)->toBeNull();
});

it('stores null for an empty effort, so the model default is sent', function (): void {
    [$agentId] = effortTickSeedAgent('high');

    $captured = null;
    $orch = effortTickOrchestrator($captured);

    $task = $orch->start($agentId, 'hello', maxSteps: 5, options: new TaskStartOptions(effort: ''));

    expect($task->effort)->toBeNull();

    claimAndTick($orch, $task->id);

    expect($captured?->effort)->toBeNull();
});

/**
 * An agent whose effective configuration carries the given effort.
 *
 * @return array{0: int} the agent id
 */
function effortTickSeedAgent(?string $configuredEffort, ?string $agentDefault = null): array
{
    $authService = bootAuthLayer();
    $userId = $authService->register('effort-tick@example.com', 'Password1!', 'EffortTick');

    $settings = [
        'api_key' => 'test',
        'model' => 'gpt-4o-mini',
        'base_url' => 'https://example.invalid/v1',
    ];
    if ($configuredEffort !== null) {
        $settings['effort'] = $configuredEffort;
    }

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Effort Tick Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => json_encode($settings),
        'is_global' => true,
        'is_default' => true,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Effort Tick Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps' => 10,
        'default_effort' => $agentDefault,
        'is_active' => true,
    ]);

    return [$agent->id];
}

/**
 * An orchestrator whose driver records the request it was handed.
 *
 * The effort service is built from a real driver factory, because the
 * default chain asks the configuration's driver which levels it offers —
 * a factory that cannot build the driver would report no levels and the
 * chain would resolve to nothing.
 */
function effortTickOrchestrator(?LLMRequest &$captured): Orchestrator
{
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->allows('complete')->andReturnUsing(function (LLMRequest $request) use (&$captured): LLMResponse {
        $captured = $request;

        return new LLMResponse(
            content: 'Done.',
            toolCalls: [],
            inputTokens: 10,
            outputTokens: 5,
            completionId: 'cmp_1',
        );
    });
    $driver->allows('getProviderName')->andReturn('mock');
    $driver->allows('getModelName')->andReturn('mock-model');
    $driver->allows('supportsImageInput')->andReturn(false);

    $factory = Mockery::mock(DriverFactory::class);
    $factory->allows('makeFromAgent')->andReturn($driver);

    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmConfigService = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $effortService = new EffortService(
        new LLMConfigPreferences(),
        $llmConfigService,
        new DriverFactory(new NullLogger(), $llmConfigService, 60),
    );

    return new Orchestrator(
        $factory,
        new OrchestratorConfig(llmConfigService: $llmConfigService, effortService: $effortService),
    );
}
