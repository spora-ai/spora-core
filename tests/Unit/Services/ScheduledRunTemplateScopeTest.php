<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Services\Exceptions\PromptTemplateMissingException;
use Spora\Services\ScheduledRunService;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

function scheduleScopeAgent(int $userId, string $name): int
{
    $agentId = (int) Capsule::table('agents')->insertGetId([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => $name,
        'max_steps'    => 10,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    return $agentId;
}

function scheduleScopeTemplate(int $agentId, string $prompt): int
{
    return (int) Capsule::table('agent_prompt_templates')->insertGetId([
        'agent_id'        => $agentId,
        'name'            => 'Template ' . $agentId,
        'description'     => null,
        'prompt_template' => $prompt,
        'variables'       => null,
        'max_steps'       => null,
        'is_active'       => 1,
        'created_at'      => date('Y-m-d H:i:s'),
        'updated_at'      => date('Y-m-d H:i:s'),
    ]);
}

function scheduleScopeService(): ScheduledRunService
{
    /** @var Mockery\MockInterface&Spora\Services\MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(Spora\Services\MercurePublisherInterface::class)->shouldIgnoreMissing();

    return new ScheduledRunService(
        Mockery::mock(Spora\Agents\OrchestratorInterface::class),
        $mercure,
        makeEffortService(),
    );
}

it('refuses a schedule that references another agent\'s template', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('schedule-scope@example.com', TEST_PASSWORD, 'Scope');

    $ownAgentId    = scheduleScopeAgent($userId, 'Own Agent');
    $foreignAgentId = scheduleScopeAgent($userId, 'Foreign Agent');

    $foreignTemplateId = scheduleScopeTemplate($foreignAgentId, 'Foreign private prompt');

    $service = scheduleScopeService();

    expect(static fn() => $service->createRun($ownAgentId, $userId, [
        'template_id'     => $foreignTemplateId,
        'cron_expression' => '0 * * * *',
    ]))->toThrow(PromptTemplateMissingException::class);
});

it('accepts a schedule that references its own agent\'s template', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('schedule-own@example.com', TEST_PASSWORD, 'Own');

    $agentId = scheduleScopeAgent($userId, 'Own Agent');
    $templateId = scheduleScopeTemplate($agentId, 'My own prompt');

    $service = scheduleScopeService();

    $result = $service->createRun($agentId, $userId, [
        'template_id'     => $templateId,
        'cron_expression' => '0 * * * *',
    ]);

    expect($result['scheduled_run']['template_id'])->toBe($templateId);
});

it('refuses a schedule update that points at another agent\'s template', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('schedule-update@example.com', TEST_PASSWORD, 'Update');

    $ownAgentId     = scheduleScopeAgent($userId, 'Own Agent');
    $foreignAgentId = scheduleScopeAgent($userId, 'Foreign Agent');

    $foreignTemplateId = scheduleScopeTemplate($foreignAgentId, 'Foreign private prompt');

    $service = scheduleScopeService();
    $created = $service->createRun($ownAgentId, $userId, [
        'raw_prompt'      => 'plain',
        'cron_expression' => '0 * * * *',
    ]);
    $runId = $created['scheduled_run']['id'];

    expect(static fn() => $service->updateRun($runId, $ownAgentId, $userId, [
        'template_id' => $foreignTemplateId,
    ]))->toThrow(PromptTemplateMissingException::class);
});

it('does not name a foreign template when serialising a schedule', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('schedule-serialise@example.com', TEST_PASSWORD, 'Serialise');

    $ownAgentId     = scheduleScopeAgent($userId, 'Own Agent');
    $foreignAgentId = scheduleScopeAgent($userId, 'Foreign Agent');

    $foreignTemplateId = scheduleScopeTemplate($foreignAgentId, 'Foreign private prompt');

    // A row that predates the scope check still points at the foreign
    // template; serialising it must not disclose the template's name.
    $runId = (int) Capsule::table('scheduled_runs')->insertGetId([
        'agent_id'        => $ownAgentId,
        'template_id'     => $foreignTemplateId,
        'raw_prompt'      => 'plain',
        'cron_expression' => '0 * * * *',
        'run_at'          => null,
        'timezone'        => 'UTC',
        'max_steps_override' => null,
        'is_active'       => 1,
        'last_run_at'     => null,
        'next_run_at'     => null,
        'user_id'         => $userId,
        'created_at'      => date('Y-m-d H:i:s'),
        'updated_at'      => date('Y-m-d H:i:s'),
    ]);

    $service = scheduleScopeService();
    $result = $service->getRunsForAgent($ownAgentId, $userId);

    expect($result)->not->toBeNull();
    $serialised = $result[0];
    expect($serialised['template_name'])->toBeNull();
});

it('leaves the queue entries alone when the template check fails', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('schedule-entries@example.com', TEST_PASSWORD, 'Entries');

    $ownAgentId     = scheduleScopeAgent($userId, 'Own Agent');
    $foreignAgentId = scheduleScopeAgent($userId, 'Foreign Agent');

    $foreignTemplateId = scheduleScopeTemplate($foreignAgentId, 'Foreign private prompt');

    $service = scheduleScopeService();
    $created = $service->createRun($ownAgentId, $userId, [
        'raw_prompt'      => 'plain',
        'cron_expression' => '0 * * * *',
    ]);
    $runId = $created['scheduled_run']['id'];

    $entryId = (int) Capsule::table('scheduled_runs_next')->insertGetId([
        'scheduled_run_id' => $runId,
        'due_at'           => '2026-01-01 00:00:00',
        'status'           => 'PENDING',
        'created_at'       => '2026-01-01 00:00:00',
        'updated_at'       => '2026-01-01 00:00:00',
    ]);

    // The update changes the schedule AND names a foreign template. The
    // reschedule rewrites the queue entries, so the template check has to
    // come first — otherwise the entries are rewritten and the schedule
    // is not, and the two disagree.
    expect(static fn() => $service->updateRun($runId, $ownAgentId, $userId, [
        'template_id'     => $foreignTemplateId,
        'cron_expression' => '30 * * * *',
    ]))->toThrow(PromptTemplateMissingException::class);

    $entry = Capsule::table('scheduled_runs_next')->where('id', $entryId)->first();
    expect($entry->status)->toBe('PENDING');
});

it('records the authenticated caller as the trigger user of a manually triggered run', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('schedule-trigger@example.com', TEST_PASSWORD, 'Trigger');

    $agentId = scheduleScopeAgent($userId, 'Own Agent');

    $service = scheduleScopeService();
    $created = $service->createRun($agentId, $userId, [
        'raw_prompt'      => 'plain',
        'cron_expression' => '0 * * * *',
    ]);
    $runId = $created['scheduled_run']['id'];

    // The trigger is an authenticated action: the task it creates records
    // the caller, so the tools the run executes resolve their authority
    // from that caller rather than from whoever ran on the agent last.
    $started = [];
    $orchestrator = Mockery::mock(Spora\Agents\OrchestratorInterface::class);
    $orchestrator->allows('start')->andReturnUsing(function (
        int $agentId,
        string $prompt,
        int $maxSteps = 10,
        ?int $parentTaskId = null,
        ?Spora\Agents\TaskStartOptions $options = null,
    ) use (&$started): Spora\Models\Task {
        $userId = $options?->userId;
        $started['user_id'] = $userId;

        return Spora\Models\Task::create([
            'agent_id'        => $agentId,
            'principal_id'    => createUserPrincipalPublic($userId ?? 1),
            'trigger_user_id' => $userId,
            'status'          => 'QUEUED',
            'user_prompt'     => $prompt,
            'max_steps'       => $maxSteps,
            'step_count'      => 0,
        ]);
    });

    /** @var Mockery\MockInterface&Spora\Services\MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(Spora\Services\MercurePublisherInterface::class)->shouldIgnoreMissing();
    $triggeringService = new ScheduledRunService($orchestrator, $mercure, makeEffortService());
    $triggeringService->triggerRun($runId, $agentId, $userId);

    expect($started['user_id'])->toBe($userId);
});
