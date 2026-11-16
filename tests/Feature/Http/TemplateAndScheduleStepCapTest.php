<?php

declare(strict_types=1);

use Spora\Agents\OrchestratorInterface;
use Spora\Core\Config;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\Exceptions\AgentPatchValidationException;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\ScheduledRun;
use Spora\Services\AgentStepCap;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PromptTemplateService;
use Spora\Services\ScheduledRunService;

/**
 * The step-cap ceiling reaches the surfaces that store a cap.
 *
 * The columns that hold a cap are `INT UNSIGNED`, so the storage no longer
 * bounds them — a cap above the instance's ceiling would be stored and
 * carried into every task the agent starts, which is the runaway the
 * ceiling exists to stop. The refusal belongs where the row is written,
 * because the scanner and the request validator have no instance to judge
 * a stored cap by.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @return array{0: int, 1: int, 2: AuthMiddleware} the owner, the agent and the middleware
 */
function stepCapHttpOwner(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($authService, "step-cap-{$seq}@example.com", 'Password1!', 'Step Cap');

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Step Cap Config',
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
        'name'                 => 'Step Cap Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    return [$userId, (int) $agent->id, new AuthMiddleware($authService)];
}

/**
 * The ceiling this instance enforces: 50, well under the stored column's range.
 */
function stepCap(): AgentStepCap
{
    return new AgentStepCap(new Config(['SPORA_MAX_STEPS' => 50]));
}

/**
 * @return array{0: Spora\Http\PromptTemplateController, 1: Spora\Http\ScheduledRunController}
 */
function stepCapHttpControllers(): array
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
        new Spora\Http\PromptTemplateController(
            $authService,
            new PromptTemplateService(makeEffortService(), stepCap: stepCap()),
        ),
        new Spora\Http\ScheduledRunController(
            $authService,
            new ScheduledRunService(
                $orchestrator,
                $mercure,
                makeEffortService(),
                stepCap: stepCap(),
            ),
        ),
    ];
}

it('creating a template with a cap above the ceiling is a 422', function (): void {
    [, $agentId, $authMiddleware] = stepCapHttpOwner();
    [$templateController] = stepCapHttpControllers();

    $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/templates", [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'max_steps'       => 80,
    ]);
    $request->attributes->set('id', $agentId);
    $response = callController($templateController, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR')
        ->and($body['error']['message'])->toContain('between 1 and 50');
});

it('updating a template with a cap above the ceiling is a 422', function (): void {
    [, $agentId, $authMiddleware] = stepCapHttpOwner();
    [$templateController] = stepCapHttpControllers();

    $template = AgentPromptTemplate::create([
        'agent_id'        => $agentId,
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'is_active'       => true,
    ]);

    $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/templates/{$template->id}", [
        'max_steps' => 80,
    ]);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('templateId', $template->id);
    $response = callController($templateController, 'update', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR')
        ->and($body['error']['message'])->toContain('between 1 and 50');
});

it('creating a schedule with a cap above the ceiling is a 422', function (): void {
    [, $agentId, $authMiddleware] = stepCapHttpOwner();
    [, $runController] = stepCapHttpControllers();

    $request = jsonRequest('POST', "/api/v1/agents/{$agentId}/scheduled-runs", [
        'raw_prompt'        => 'Summarize the inbox',
        'cron_expression'   => '0 7 * * *',
        'timezone'          => 'UTC',
        'max_steps_override' => 80,
    ]);
    $request->attributes->set('id', $agentId);
    $response = callController($runController, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR')
        ->and($body['error']['message'])->toContain('between 1 and 50');
});

it('updating a schedule with a cap above the ceiling is a 422', function (): void {
    [, $agentId, $authMiddleware] = stepCapHttpOwner();
    [, $runController] = stepCapHttpControllers();

    $run = ScheduledRun::create([
        'agent_id'       => $agentId,
        'raw_prompt'     => 'Summarize the inbox',
        'cron_expression' => '0 7 * * *',
        'timezone'       => 'UTC',
        'is_active'      => true,
        'user_id'        => 1,
    ]);

    $request = jsonRequest('PUT', "/api/v1/agents/{$agentId}/scheduled-runs/{$run->id}", [
        'max_steps_override' => 80,
    ]);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('runId', $run->id);
    $response = callController($runController, 'update', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(422);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR')
        ->and($body['error']['message'])->toContain('between 1 and 50');
});

it('a template inside the ceiling is stored', function (): void {
    [$userId, $agentId] = stepCapHttpOwner();

    $service = new PromptTemplateService(makeEffortService(), stepCap: stepCap());

    $created = $service->createTemplate($agentId, $userId, [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'max_steps'       => 40,
    ]);

    expect($created['template']['max_steps'])->toBe(40);
});

it('a schedule inside the ceiling is stored', function (): void {
    [$userId, $agentId] = stepCapHttpOwner();

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    $service = new ScheduledRunService(
        Mockery::mock(OrchestratorInterface::class),
        $mercure,
        makeEffortService(),
        stepCap: stepCap(),
    );

    $created = $service->createRun($agentId, $userId, [
        'raw_prompt'         => 'Summarize the inbox',
        'cron_expression'    => '0 7 * * *',
        'timezone'           => 'UTC',
        'max_steps_override' => 40,
    ]);

    expect($created['scheduled_run']['max_steps_override'])->toBe(40);
});

it('a malformed cap is refused rather than truncated', function (): void {
    [$userId, $agentId] = stepCapHttpOwner();

    $service = new PromptTemplateService(makeEffortService(), stepCap: stepCap());

    // The cast that follows would turn "80garbage" into 80, and a cap that
    // was never a cap would be stored as one.
    expect(fn() => $service->createTemplate($agentId, $userId, [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'max_steps'       => '80garbage',
    ]))->toThrow(AgentPatchValidationException::class, 'between 1 and 50');
});

it('a refused cap leaves the stored one alone', function (): void {
    [$userId, $agentId] = stepCapHttpOwner();

    $service = new PromptTemplateService(makeEffortService(), stepCap: stepCap());

    $created = $service->createTemplate($agentId, $userId, [
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize',
        'max_steps'       => 40,
    ]);

    expect(fn() => $service->updateTemplate((int) $created['template']['id'], $agentId, $userId, [
        'max_steps' => 80,
    ]))->toThrow(AgentPatchValidationException::class);

    $read = $service->getTemplate((int) $created['template']['id'], $agentId, $userId);

    expect($read['template']['max_steps'])->toBe(40);
});
