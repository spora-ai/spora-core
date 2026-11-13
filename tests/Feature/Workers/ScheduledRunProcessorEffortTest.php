<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\TaskStartOptions;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Models\Agent;
use Spora\Models\AgentPromptTemplate;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The reasoning effort a scheduled run fires at.
 *
 * The precedence is the run's own override, else the template's effort,
 * else nothing — and nothing leaves the choice to the agent's default, the
 * same way a chat message that names no effort does. Both entry points have
 * to resolve it: the server-side worker tick and the browser-driven
 * client-mode tick.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @return array{0: ScheduledRunProcessor, 1: object{options: list<?TaskStartOptions>}} the processor and the options each start saw
 */
function makeEffortProcessorFixture(): array
{
    $captured = new class {
        /** @var list<?TaskStartOptions> */
        public array $options = [];
    };

    $orchestrator = Mockery::mock(OrchestratorInterface::class);
    $orchestrator->allows('start')->andReturnUsing(
        function (
            int $agentId,
            string $prompt,
            int $maxSteps = 10,
            ?int $parentTaskId = null,
            ?TaskStartOptions $options = null,
        ) use ($captured): Task {
            $captured->options[] = $options;

            return Task::create([
                'agent_id'        => $agentId,
                'principal_id'    => (int) Agent::find($agentId)->principal_id,
                'trigger_user_id' => 1,
                'status'          => 'QUEUED',
                'user_prompt'     => $prompt,
                'max_steps'       => $maxSteps,
                'step_count'      => 0,
            ]);
        },
    );
    $orchestrator->allows('tick')->andReturnNull();

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    $notification = Mockery::mock(NotificationService::class);
    $notification->allows('notifyScheduledRunCompleted')->andReturnNull();
    $notification->allows('sendEmailForScheduledRun')->andReturnNull();

    $processor = new ScheduledRunProcessor(
        $orchestrator,
        new NullLogger(),
        $mercure,
        $notification,
    );

    return [$processor, $captured];
}

/**
 * @return array{0: int, 1: int} the user and the agent
 */
function makeEffortProcessorOwner(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($authService, "effort-processor-{$seq}@example.com", 'Password1!', 'Effort Processor');

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name'         => 'Effort Processor Agent',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    return [$userId, (int) $agent->id];
}

/**
 * Seed a run that is due, optionally bound to a template.
 *
 * @param array<string, mixed> $overrides
 */
function seedDueEffortRun(
    int $agentId,
    int $userId,
    ?AgentPromptTemplate $template,
    string $dueAt,
    array $overrides = [],
): ScheduledRun {
    $run = ScheduledRun::create(array_merge([
        'agent_id'        => $agentId,
        'user_id'         => $userId,
        'template_id'     => $template?->id,
        'raw_prompt'      => 'Summarize the inbox',
        'cron_expression' => '0 7 * * *',
        'timezone'        => 'UTC',
        'is_active'       => true,
        'next_run_at'     => $dueAt,
    ], $overrides));

    Capsule::table('scheduled_runs_next')->insert([
        'scheduled_run_id' => $run->id,
        'due_at'           => $dueAt,
        'status'           => ScheduledRunNext::STATUS_PENDING,
        'created_at'       => '2025-01-01 09:00:00',
        'updated_at'       => '2025-01-01 09:00:00',
    ]);

    return $run;
}

/**
 * @param array<string, mixed> $overrides
 */
function seedEffortTemplate(int $agentId, array $overrides = []): AgentPromptTemplate
{
    return AgentPromptTemplate::create(array_merge([
        'agent_id'        => $agentId,
        'name'            => 'Effort template',
        'prompt_template' => 'Summarize {{inbox}}',
        'is_active'       => true,
    ], $overrides));
}

/**
 * Three runs that cover the whole precedence: the one that overrides its
 * template, the one that inherits its template's effort, and the one that
 * names nothing at all.
 */
function seedEffortPrecedenceRuns(int $agentId, int $userId): void
{
    $template = seedEffortTemplate($agentId, ['effort' => 'medium']);

    seedDueEffortRun($agentId, $userId, $template, '2025-01-01 10:00:00', ['effort_override' => 'high']);
    seedDueEffortRun($agentId, $userId, $template, '2025-01-01 10:01:00');
    seedDueEffortRun($agentId, $userId, null, '2025-01-01 10:02:00');
}

it('the worker tick resolves the effort as the run\'s override, else the template\'s, else nothing', function (): void {
    [$processor, $captured] = makeEffortProcessorFixture();
    [$userId, $agentId] = makeEffortProcessorOwner();

    seedEffortPrecedenceRuns($agentId, $userId);

    // The claim takes the earliest due entry first, so three ticks walk the
    // three runs in the order they were seeded.
    $processor->process(new BufferedOutput());
    $processor->process(new BufferedOutput());
    $processor->process(new BufferedOutput());

    expect(effortsOf($captured->options))->toBe(['high', 'medium', null]);
});

it('the client-mode tick resolves the effort as the run\'s override, else the template\'s, else nothing', function (): void {
    [$processor, $captured] = makeEffortProcessorFixture();
    [$userId, $agentId] = makeEffortProcessorOwner();

    seedEffortPrecedenceRuns($agentId, $userId);

    $processor->processSynchronously(new BufferedOutput(), $userId, 600);
    $processor->processSynchronously(new BufferedOutput(), $userId, 600);
    $processor->processSynchronously(new BufferedOutput(), $userId, 600);

    expect(effortsOf($captured->options))->toBe(['high', 'medium', null]);
});

/**
 * The effort each start carried, in the order the starts happened.
 *
 * @param list<?TaskStartOptions> $options
 * @return list<?string>
 */
function effortsOf(array $options): array
{
    return array_map(static fn(?TaskStartOptions $options): ?string => $options?->effort, $options);
}
