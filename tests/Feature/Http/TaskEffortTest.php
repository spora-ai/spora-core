<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Mockery;
use Psr\Log\NullLogger;
use Spora\Agents\Orchestrator;
use Spora\Agents\OrchestratorConfig;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\AnswerQuestionRequestValidator;
use Spora\Http\ContinueTaskDispatcher;
use Spora\Http\DecisionsRequestValidator;
use Spora\Http\TaskController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\Task;
use Spora\Services\LLMConfigService;
use Spora\Services\MediaArchive\TaskMediaCapabilityService;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PrincipalResolver;
use Spora\Services\TaskCompactionServiceInterface;
use Spora\Services\TaskService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The `effort` field of a task request body.
 *
 * Three states travel from the body to the task row: absent (the
 * configuration default applies), an empty string (the model default) and a
 * level. The row holds the resolved value, and an unknown level is refused
 * rather than silently dropped.
 */
afterEach(fn() => \Spora\Core\Database::resetBootState());

test('a task created with an effort stores it on the row', function (): void {
    [$controller, $userId, $agentId] = effortHttpController('high');

    $response = $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
        'effort' => 'low',
    ]));

    expect($response->getStatusCode())->toBe(201);

    $task = Task::query()->orderByDesc('id')->first();
    expect($task?->effort)->toBe('low');
});

test('a task created without an effort gets the configured default', function (): void {
    [$controller, , $agentId] = effortHttpController('high');

    $response = $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
    ]));

    expect($response->getStatusCode())->toBe(201);

    $task = Task::query()->orderByDesc('id')->first();
    expect($task?->effort)->toBe('high');
});

test('a task created with an empty effort stores null, so the model default is sent', function (): void {
    [$controller, , $agentId] = effortHttpController('high');

    $response = $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
        'effort' => '',
    ]));

    expect($response->getStatusCode())->toBe(201);

    $task = Task::query()->orderByDesc('id')->first();
    expect($task?->effort)->toBeNull();
});

test('an unknown effort level is refused with 422', function (): void {
    [$controller, , $agentId] = effortHttpController('high');

    $response = $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
        'effort' => 'turbo',
    ]));

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED');
});

test('a level the configuration names is accepted, canonical or not', function (): void {
    [$controller, , $agentId] = effortHttpController('high', OpenAICompatibleDriver::class, ['effort_levels' => 'low, turbo']);

    $response = $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
        'effort' => 'turbo',
    ]));

    expect($response->getStatusCode())->toBe(201);

    $task = Task::query()->orderByDesc('id')->first();
    expect($task?->effort)->toBe('turbo');
});

test('a level the configuration does not name is refused', function (): void {
    [$controller, , $agentId] = effortHttpController('high', OpenAICompatibleDriver::class, ['effort_levels' => 'low, turbo']);

    $response = $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
        'effort' => 'medium',
    ]));

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED')
        ->and($payload['error']['message'])->toContain('Supported levels: low, turbo');
});

test('a non-string effort is refused with 422', function (): void {
    [$controller, , $agentId] = effortHttpController('high');

    $response = $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
        'effort' => 3,
    ]));

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('INVALID_EFFORT');
});

test('a continued task stores the effort of the follow-up', function (): void {
    [$controller, , $agentId] = effortHttpController('high');

    $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
        'effort' => 'low',
    ]));

    $task = Task::query()->orderByDesc('id')->first();
    Task::where('id', $task?->id)->update(['status' => 'COMPLETED']);

    $request = Request::create(
        '/api/v1/tasks/' . $task?->id . '/continue',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['prompt' => 'again', 'effort' => 'max']),
    );
    $request->attributes->set('taskId', (int) $task?->id);

    $response = $controller->continue($request);

    expect($response->getStatusCode())->toBe(200);
    expect(Task::query()->find($task?->id)?->effort)->toBe('max');
});

test('a continued task without an effort gets the configured default', function (): void {
    [$controller, , $agentId] = effortHttpController('medium');

    $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
        'effort' => 'low',
    ]));

    $task = Task::query()->orderByDesc('id')->first();
    Task::where('id', $task?->id)->update(['status' => 'COMPLETED']);

    $request = Request::create(
        '/api/v1/tasks/' . $task?->id . '/continue',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['prompt' => 'again']),
    );
    $request->attributes->set('taskId', (int) $task?->id);

    $response = $controller->continue($request);

    expect($response->getStatusCode())->toBe(200);
    expect(Task::query()->find($task?->id)?->effort)->toBe('medium');
});

test('a continued task with an unknown effort level is refused with 422', function (): void {
    [$controller, , $agentId] = effortHttpController('high');

    $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
    ]));

    $task = Task::query()->orderByDesc('id')->first();
    Task::where('id', $task?->id)->update(['status' => 'COMPLETED']);

    $request = Request::create(
        '/api/v1/tasks/' . $task?->id . '/continue',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['prompt' => 'again', 'effort' => 'turbo']),
    );
    $request->attributes->set('taskId', (int) $task?->id);

    $response = $controller->continue($request);

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED');
});

test('an effort the agent\'s driver does not offer is refused with 422', function (): void {
    [$controller, , $agentId] = effortHttpController(null, AnthropicCompatibleDriver::class);

    $response = $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
        'effort' => 'none',
    ]));

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED')
        ->and($payload['error']['message'])->toContain('Supported levels: low, medium, high, xhigh, max');
});

test('a continued task with an effort the driver does not offer is refused with 422', function (): void {
    [$controller, , $agentId] = effortHttpController(null, AnthropicCompatibleDriver::class);

    $controller->store(effortJsonRequest([
        'agent_id' => $agentId,
        'prompt' => 'hello',
    ]));

    $task = Task::query()->orderByDesc('id')->first();
    Task::where('id', $task?->id)->update(['status' => 'COMPLETED']);

    $request = Request::create(
        '/api/v1/tasks/' . $task?->id . '/continue',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['prompt' => 'again', 'effort' => 'none']),
    );
    $request->attributes->set('taskId', (int) $task?->id);

    $response = $controller->continue($request);

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED');
});

/**
 * @param array<string, mixed> $body
 */
function effortJsonRequest(array $body): Request
{
    return Request::create(
        '/api/v1/tasks',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($body),
    );
}

/**
 * A controller wired to the real service and orchestrator, so the row the
 * request writes is the row the test reads.
 *
 * @param array<string, mixed> $extraSettings
 * @return array{0: TaskController, 1: int, 2: int} the controller, the user id and the agent id
 */
function effortHttpController(
    ?string $configuredEffort,
    string $driverClass = OpenAICompatibleDriver::class,
    array $extraSettings = [],
): array {
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);

    $settings = array_merge([
        'api_key' => 'test',
        'model' => 'gpt-4o-mini',
        'base_url' => 'https://example.invalid/v1',
    ], $extraSettings);

    if ($configuredEffort !== null) {
        $settings['effort'] = $configuredEffort;
    }

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Effort Http Config',
        'driver_class' => $driverClass,
        'settings' => json_encode($settings),
        'is_global' => true,
        'is_default' => true,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Effort Http Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmConfigService = new LLMConfigService($security, [OpenAICompatibleDriver::class, $driverClass]);
    $driverFactory = new DriverFactory(new NullLogger(), $llmConfigService, 60);

    $orchestrator = new Orchestrator(
        $driverFactory,
        new OrchestratorConfig(llmConfigService: $llmConfigService),
    );

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publishForPrincipal')->andReturn(true);

    $taskService = new TaskService(
        $orchestrator,
        $mercure,
        Mockery::mock(TaskCompactionServiceInterface::class),
        null,
        new PrincipalResolver(),
    );

    $mediaCapability = new TaskMediaCapabilityService();

    $effortService = makeEffortService([OpenAICompatibleDriver::class, $driverClass]);

    return [
        new TaskController(
            $authService,
            $taskService,
            $mediaCapability,
            new ContinueTaskDispatcher($taskService, $mediaCapability, $effortService),
            new DecisionsRequestValidator($taskService),
            new AnswerQuestionRequestValidator(new PrincipalResolver()),
            makeAgentService(),
            $effortService,
        ),
        $userId,
        (int) $agent->id,
    ];
}
