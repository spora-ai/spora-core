<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\TaskStartOptions;
use Spora\Console\Worker\ScheduledRunProcessor;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;
use Spora\Models\Task;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\NotificationService;
use Spora\Services\ScheduledRunEffortResolver;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The effort a scheduled run dispatches is one the configuration still
 * offers.
 *
 * The save path refuses a level the agent's driver does not offer, but a
 * configuration can narrow its level list afterwards: the run then holds a
 * level the driver drops on the way to the provider, and the run would
 * happen at the provider's default without a word. The dispatch path
 * replaces such a level with the agent's effective default and records the
 * substitution.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @return array{0: ScheduledRunProcessor, 1: object, 2: TestHandler}
 */
function effortFallbackProcessor(): array
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
                'trigger_user_id' => null,
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

    $handler = new TestHandler();
    $logger = new Logger('test');
    $logger->pushHandler($handler);

    $processor = new ScheduledRunProcessor(
        $orchestrator,
        $logger,
        $mercure,
        $notification,
        new ScheduledRunEffortResolver(makeEffortService(), new LLMConfigPreferences()),
    );

    return [$processor, $captured, $handler];
}

/**
 * The agent and the configuration it resolves to: the configuration
 * offers `low` and `medium`, and the agent's own default is `medium`.
 *
 * @return array{0: int, 1: int} the user and the agent
 */
function effortFallbackOwner(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($authService, "effort-fallback-{$seq}@example.com", 'Password1!', 'Effort Fallback');

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Effort Fallback Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'       => 'test',
            'model'         => 'gpt-5.5',
            'base_url'      => 'https://example.invalid',
            'effort_levels' => 'low, medium',
        ]),
        'is_global'  => true,
        'is_default' => true,
    ]);

    $agent = Agent::create([
        'principal_id'         => createUserPrincipalPublic($userId),
        'name'                 => 'Effort Fallback Agent',
        'llm_driver_config_id' => $config->id,
        'default_effort'       => 'medium',
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    return [$userId, (int) $agent->id];
}

/**
 * @param array<string, mixed> $overrides
 */
function effortFallbackRun(int $agentId, int $userId, array $overrides = []): ScheduledRun
{
    $run = ScheduledRun::create(array_merge([
        'agent_id'        => $agentId,
        'user_id'         => $userId,
        'raw_prompt'      => 'Summarize the inbox',
        'cron_expression' => '0 7 * * *',
        'timezone'        => 'UTC',
        'effort_override' => 'high',
        'is_active'       => true,
        'next_run_at'     => '2025-01-01 10:00:00',
    ], $overrides));

    Capsule::table('scheduled_runs_next')->insert([
        'scheduled_run_id' => $run->id,
        'due_at'           => '2025-01-01 10:00:00',
        'status'           => ScheduledRunNext::STATUS_PENDING,
        'created_at'       => '2025-01-01 09:00:00',
        'updated_at'       => '2025-01-01 09:00:00',
    ]);

    return $run;
}

/**
 * The effort each start carried, in the order the starts happened.
 *
 * @param list<?TaskStartOptions> $options
 * @return list<?string>
 */
function effortFallbackEfforts(array $options): array
{
    return array_map(static fn(?TaskStartOptions $options): ?string => $options?->effort, $options);
}

/**
 * @return list<LogRecord>
 */
function effortFallbackWarnings(TestHandler $handler): array
{
    return array_values(array_filter(
        $handler->getRecords(),
        static fn(LogRecord $record): bool => $record->level === Level::Warning,
    ));
}

it('the worker tick fires a run whose saved level is no longer offered at the agent default', function (): void {
    [$processor, $captured, $handler] = effortFallbackProcessor();
    [$userId, $agentId] = effortFallbackOwner();

    effortFallbackRun($agentId, $userId);

    $processor->process(new BufferedOutput());

    expect(effortFallbackEfforts($captured->options))->toBe(['medium']);

    $warnings = effortFallbackWarnings($handler);
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->context['requested_effort'] ?? null)->toBe('high')
        ->and($warnings[0]->context['effective_effort'] ?? null)->toBe('medium');
});

it('the client-mode tick fires a run whose saved level is no longer offered at the agent default', function (): void {
    [$processor, $captured, $handler] = effortFallbackProcessor();
    [$userId, $agentId] = effortFallbackOwner();

    effortFallbackRun($agentId, $userId);

    $processor->processSynchronously(new BufferedOutput(), $userId, 600);

    expect(effortFallbackEfforts($captured->options))->toBe(['medium']);

    $warnings = effortFallbackWarnings($handler);
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->context['requested_effort'] ?? null)->toBe('high')
        ->and($warnings[0]->context['effective_effort'] ?? null)->toBe('medium');
});

it('the worker tick fires a run at the level it was saved with while the configuration offers it', function (): void {
    [$processor, $captured, $handler] = effortFallbackProcessor();
    [$userId, $agentId] = effortFallbackOwner();

    effortFallbackRun($agentId, $userId, ['effort_override' => 'low']);

    $processor->process(new BufferedOutput());

    expect(effortFallbackEfforts($captured->options))->toBe(['low'])
        ->and(effortFallbackWarnings($handler))->toBe([]);
});
