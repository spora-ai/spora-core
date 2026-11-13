<?php

declare(strict_types=1);

use Spora\Agents\OrchestratorInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\PromptTemplateController;
use Spora\Http\ScheduledRunController;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\ScheduledRun;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PromptTemplateService;
use Spora\Services\ScheduledRunService;

/**
 * The HTTP surfaces that carry the reasoning effort of a template and of a
 * schedule.
 *
 * A level the agent's driver does not offer is a malformed body, not a
 * failed write: the surface answers a 422 that names the levels the driver
 * does offer, on the create and on the update.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @return array{0: int, 1: int, 2: AuthMiddleware} the owner, the agent and the auth gate
 */
function makeEffortHttpOwner(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = $authService->register("effort-http-{$seq}@example.com", 'Password1!', 'Effort Http');
    simulateLoggedInSession($userId, "effort-http-{$seq}@example.com");

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Effort Http Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'  => 'test',
            'model'    => 'gpt-5.5',
            'base_url' => 'https://example.invalid',
        ]),
        'is_global'    => true,
        'is_default'   => true,
    ]);

    $agent = Agent::create([
        'principal_id'         => createUserPrincipalPublic($userId),
        'name'                 => 'Effort Http Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    return [$userId, (int) $agent->id, new AuthMiddleware($authService)];
}

/**
 * @return array{0: PromptTemplateController, 1: ScheduledRunController}
 */
function makeEffortHttpControllers(): array
{
    $authService = bootAuthLayer();

    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->allows('start')->andReturnUsing(function (int $agentId, string $prompt, int $maxSteps) {
        return Spora\Models\Task::create([
            'agent_id'        => $agentId,
            'principal_id'    => (int) Agent::find($agentId)->principal_id,
            'trigger_user_id' => null,
            'status'          => 'RUNNING',
            'user_prompt'     => $prompt,
            'max_steps'       => $maxSteps,
            'step_count'      => 0,
        ]);
    });

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    return [
        new PromptTemplateController($authService, new PromptTemplateService(makeEffortService())),
        new ScheduledRunController(
            $authService,
            new ScheduledRunService($orchestrator, $mercure, makeEffortService()),
        ),
    ];
}

it('creating a template with an unsupported effort is a 422 that names the levels', function (): void {
    [, $agentId, $authMiddleware] = makeEffortHttpOwner();
    [$templateController] = makeEffortHttpControllers();

    $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/templates", [
        'name'            => 'Impossible summary',
        'prompt_template' => 'Summarize',
        'effort'          => 'ultra',
    ]);
    $request->attributes->set('id', $agentId);
    $response = callController($templateController, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED')
        ->and($body['error']['message'])->toContain('Supported levels:');
});

it('updating a template with an unsupported effort is a 422', function (): void {
    [, $agentId, $authMiddleware] = makeEffortHttpOwner();
    [$templateController] = makeEffortHttpControllers();

    $template = AgentPromptTemplate::create([
        'agent_id'        => $agentId,
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'is_active'       => true,
    ]);

    $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/templates/{$template->id}", [
        'effort' => 'ultra',
    ]);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('templateId', $template->id);
    $response = callController($templateController, 'update', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED')
        ->and($body['error']['message'])->toContain('Supported levels:');
});

it('creating a schedule with an unsupported effort override is a 422 that names the levels', function (): void {
    [, $agentId, $authMiddleware] = makeEffortHttpOwner();
    [, $runController] = makeEffortHttpControllers();

    $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
        'raw_prompt'      => 'Summarize the inbox',
        'cron_expression' => '0 7 * * *',
        'timezone'        => 'UTC',
        'effort_override' => 'ultra',
    ]);
    $request->attributes->set('id', $agentId);
    $response = callController($runController, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED')
        ->and($body['error']['message'])->toContain('Supported levels:');
});

it('updating a schedule with an unsupported effort override is a 422', function (): void {
    [$userId, $agentId, $authMiddleware] = makeEffortHttpOwner();
    [, $runController] = makeEffortHttpControllers();

    $run = ScheduledRun::create([
        'agent_id'        => $agentId,
        'user_id'         => $userId,
        'raw_prompt'      => 'Summarize the inbox',
        'cron_expression' => '0 7 * * *',
        'timezone'        => 'UTC',
        'is_active'       => true,
    ]);

    $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}", [
        'effort_override' => 'ultra',
    ]);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('runId', $run->id);
    $response = callController($runController, 'update', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED')
        ->and($body['error']['message'])->toContain('Supported levels:');
});

it('a template round-trips its effort through the HTTP surface', function (): void {
    [, $agentId, $authMiddleware] = makeEffortHttpOwner();
    [$templateController] = makeEffortHttpControllers();

    $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/templates", [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'effort'          => 'high',
    ]);
    $request->attributes->set('id', $agentId);
    $response = callController($templateController, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(201);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['template']['effort'])->toBe('high');
});

it('a schedule round-trips its effort override through the HTTP surface', function (): void {
    [, $agentId, $authMiddleware] = makeEffortHttpOwner();
    [, $runController] = makeEffortHttpControllers();

    $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
        'raw_prompt'      => 'Summarize the inbox',
        'cron_expression' => '0 7 * * *',
        'timezone'        => 'UTC',
        'effort_override' => 'high',
    ]);
    $request->attributes->set('id', $agentId);
    $response = callController($runController, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(201);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['scheduled_run']['effort_override'])->toBe('high');
});
