<?php

declare(strict_types=1);

use Spora\Agents\OrchestratorInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\Principal;
use Spora\Models\ScheduledRun;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\PromptTemplateService;
use Spora\Services\ScheduledRunService;
use Spora\Tools\ScheduleTool;

/**
 * The reasoning effort the LLM-facing schedule tool carries.
 *
 * The tool is the agent's own window onto the same rows the operator edits,
 * so it grows the same two fields: `effort` on a template and
 * `effort_override` on a schedule. A level the agent's driver does not
 * offer is refused with a message that names the ones it does — the agent
 * cannot guess a vocabulary the operator configured.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @return array{0: ScheduleTool} the tool
 */
function makeScheduleToolEffortFixture(): array
{
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

    $principalResolver = new PrincipalResolver();
    $principalService = new PrincipalService($principalResolver);

    $tool = new ScheduleTool(
        new ScheduledRunService($orchestrator, $mercure, makeEffortService()),
        new PromptTemplateService(makeEffortService()),
        new ScheduleTool\ScheduleToolCollaborators(
            principalResolver: $principalResolver,
            principalService: $principalService,
        ),
        $principalResolver,
        $principalService,
    );

    return [$tool];
}

/**
 * @return array{0: int, 1: int, 2: PrincipalContext} the owner, the agent and the context the tool runs under
 */
function makeScheduleToolEffortOwner(): array
{
    $auth = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = $auth->register(
        'schedule-effort-' . $seq . '@example.com',
        'Password1!',
        'Schedule Effort Owner',
    );

    $principalId = createUserPrincipalPublic($userId);

    $config = Spora\Models\LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Schedule Effort Config',
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
        'principal_id'         => $principalId,
        'name'                 => 'ScheduleToolEffortAgent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'allow_followup'       => true,
        'is_active'            => true,
    ]);

    $context = new PrincipalContext(
        principalId: $principalId,
        type: Principal::TYPE_USER,
        ownerUserId: $userId,
        runnerUserId: $userId,
    );

    return [$userId, (int) $agent->id, $context];
}

it('create_prompt_template stores the effort the agent asked for', function (): void {
    [, $agentId, $context] = makeScheduleToolEffortOwner();
    [$tool] = makeScheduleToolEffortFixture();

    $result = $tool->execute([
        'action' => 'create_prompt_template',
        'template_payload' => [
            'name'            => 'Deep summary',
            'prompt_template' => 'Summarize {{inbox}}',
            'effort'          => 'high',
        ],
    ], $agentId, context: $context);

    expect($result->success)->toBeTrue()
        ->and($result->data['template']['effort'])->toBe('high');
});

it('update_prompt_template replaces the effort', function (): void {
    [, $agentId, $context] = makeScheduleToolEffortOwner();
    [$tool] = makeScheduleToolEffortFixture();

    $template = AgentPromptTemplate::create([
        'agent_id'        => $agentId,
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize {{inbox}}',
        'effort'          => 'high',
        'is_active'       => true,
    ]);

    $result = $tool->execute([
        'action'        => 'update_prompt_template',
        'template_id'   => $template->id,
        'template_patch' => ['effort' => 'low'],
    ], $agentId, context: $context);

    expect($result->success)->toBeTrue()
        ->and($result->data['template']['effort'])->toBe('low');
});

it('create_prompt_template refuses a level the driver does not offer, and names the ones it does', function (): void {
    [, $agentId, $context] = makeScheduleToolEffortOwner();
    [$tool] = makeScheduleToolEffortFixture();

    $result = $tool->execute([
        'action' => 'create_prompt_template',
        'template_payload' => [
            'name'            => 'Impossible summary',
            'prompt_template' => 'Summarize {{inbox}}',
            'effort'          => 'ultra',
        ],
    ], $agentId, context: $context);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Supported levels:');
});

it('update_prompt_template refuses a level the driver does not offer', function (): void {
    [, $agentId, $context] = makeScheduleToolEffortOwner();
    [$tool] = makeScheduleToolEffortFixture();

    $template = AgentPromptTemplate::create([
        'agent_id'        => $agentId,
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize {{inbox}}',
        'is_active'       => true,
    ]);

    $result = $tool->execute([
        'action'         => 'update_prompt_template',
        'template_id'    => $template->id,
        'template_patch' => ['effort' => 'ultra'],
    ], $agentId, context: $context);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Supported levels:');
});

it('create_schedule stores the effort override the agent asked for', function (): void {
    [, $agentId, $context] = makeScheduleToolEffortOwner();
    [$tool] = makeScheduleToolEffortFixture();

    $result = $tool->execute([
        'action' => 'create_schedule',
        'schedule_payload' => [
            'cron_expression' => '0 7 * * *',
            'raw_prompt'      => 'Daily standup prompt',
            'timezone'        => 'UTC',
            'effort_override' => 'high',
        ],
    ], $agentId, context: $context);

    expect($result->success)->toBeTrue()
        ->and($result->data['scheduled_run']['effort_override'])->toBe('high');
});

it('update_schedule replaces the effort override', function (): void {
    [$userId, $agentId, $context] = makeScheduleToolEffortOwner();
    [$tool] = makeScheduleToolEffortFixture();

    $run = ScheduledRun::create([
        'agent_id'        => $agentId,
        'user_id'         => $userId,
        'raw_prompt'      => 'Daily standup prompt',
        'cron_expression' => '0 7 * * *',
        'timezone'        => 'UTC',
        'effort_override' => 'high',
        'is_active'       => true,
    ]);

    $result = $tool->execute([
        'action'        => 'update_schedule',
        'schedule_id'   => $run->id,
        'schedule_patch' => ['effort_override' => 'low'],
    ], $agentId, context: $context);

    expect($result->success)->toBeTrue()
        ->and($result->data['scheduled_run']['effort_override'])->toBe('low');
});

it('create_schedule refuses an override the driver does not offer, and names the ones it does', function (): void {
    [, $agentId, $context] = makeScheduleToolEffortOwner();
    [$tool] = makeScheduleToolEffortFixture();

    $result = $tool->execute([
        'action' => 'create_schedule',
        'schedule_payload' => [
            'cron_expression' => '0 7 * * *',
            'raw_prompt'      => 'Daily standup prompt',
            'timezone'        => 'UTC',
            'effort_override' => 'ultra',
        ],
    ], $agentId, context: $context);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Supported levels:');
});

it('update_schedule refuses an override the driver does not offer', function (): void {
    [$userId, $agentId, $context] = makeScheduleToolEffortOwner();
    [$tool] = makeScheduleToolEffortFixture();

    $run = ScheduledRun::create([
        'agent_id'        => $agentId,
        'user_id'         => $userId,
        'raw_prompt'      => 'Daily standup prompt',
        'cron_expression' => '0 7 * * *',
        'timezone'        => 'UTC',
        'is_active'       => true,
    ]);

    $result = $tool->execute([
        'action'         => 'update_schedule',
        'schedule_id'    => $run->id,
        'schedule_patch' => ['effort_override' => 'ultra'],
    ], $agentId, context: $context);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Supported levels:');
});

it('the slim template list carries the effort beside the max steps', function (): void {
    [, $agentId, $context] = makeScheduleToolEffortOwner();
    [$tool] = makeScheduleToolEffortFixture();

    AgentPromptTemplate::create([
        'agent_id'        => $agentId,
        'name'            => 'Deep summary',
        'prompt_template' => 'Summarize {{inbox}}',
        'max_steps'       => 25,
        'effort'          => 'high',
        'is_active'       => true,
    ]);

    $result = $tool->execute([
        'action' => 'list_prompt_templates',
    ], $agentId, context: $context);

    expect($result->success)->toBeTrue()
        ->and($result->data['prompt_templates'])->toHaveCount(1)
        ->and($result->data['prompt_templates'][0]['effort'])->toBe('high')
        ->and($result->data['prompt_templates'][0]['max_steps'])->toBe(25);
});
