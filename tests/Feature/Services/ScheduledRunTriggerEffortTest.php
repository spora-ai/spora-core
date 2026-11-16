<?php

declare(strict_types=1);

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Spora\Agents\OrchestratorInterface;
use Spora\Agents\TaskStartOptions;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\ScheduledRun;
use Spora\Models\Task;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\ScheduledRunEffortResolver;
use Spora\Services\ScheduledRunService;

/**
 * The effort a manually triggered run dispatches is one the configuration
 * still offers.
 *
 * The save path refuses a level the agent's driver does not offer, but a
 * configuration can narrow its level list afterwards: the run then holds a
 * level the driver drops on the way to the provider, and the run would
 * happen at the provider's default without a word. The trigger replaces
 * such a level with the agent's effective default and records the
 * substitution.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @return array{0: ScheduledRunService, 1: int, 2: int, 3: object, 4: TestHandler}
 */
function triggerEffortService(): array
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
                'trigger_user_id' => $options?->userId,
                'status'          => 'QUEUED',
                'user_prompt'     => $prompt,
                'max_steps'       => $maxSteps,
                'step_count'      => 0,
            ]);
        },
    );

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    $handler = new TestHandler();
    $logger = new Logger('test');
    $logger->pushHandler($handler);

    $service = new ScheduledRunService(
        $orchestrator,
        $mercure,
        makeEffortService(),
        null,
        null,
        new ScheduledRunEffortResolver(makeEffortService(), new LLMConfigPreferences()),
        $logger,
    );

    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($authService, "trigger-effort-{$seq}@example.com", 'Password1!', 'Trigger Effort');

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Trigger Effort Config',
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
        'name'                 => 'Trigger Effort Agent',
        'llm_driver_config_id' => $config->id,
        'default_effort'       => 'medium',
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    return [$service, $userId, (int) $agent->id, $captured, $handler];
}

/**
 * A run saved while the configuration still offered `high`.
 *
 * @param array<string, mixed> $overrides
 */
function triggerEffortRun(int $agentId, int $userId, array $overrides = []): ScheduledRun
{
    return ScheduledRun::create(array_merge([
        'agent_id'        => $agentId,
        'user_id'         => $userId,
        'raw_prompt'      => 'Summarize the inbox',
        'cron_expression' => '0 7 * * *',
        'timezone'        => 'UTC',
        'effort_override' => 'high',
        'is_active'       => true,
        'next_run_at'     => '2025-01-01 10:00:00',
    ], $overrides));
}

/**
 * @return list<LogRecord>
 */
function triggerEffortWarnings(TestHandler $handler): array
{
    return array_values(array_filter(
        $handler->getRecords(),
        static fn(LogRecord $record): bool => $record->level === Level::Warning,
    ));
}

it('the manual trigger fires a run whose saved level is no longer offered at the agent default', function (): void {
    [$service, $userId, $agentId, $captured, $handler] = triggerEffortService();

    $run = triggerEffortRun($agentId, $userId);

    $service->triggerRun((int) $run->id, $agentId, $userId);

    expect($captured->options)->toHaveCount(1)
        ->and($captured->options[0]?->effort)->toBe('medium');

    $warnings = triggerEffortWarnings($handler);
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->context['requested_effort'] ?? null)->toBe('high')
        ->and($warnings[0]->context['effective_effort'] ?? null)->toBe('medium');
});

it('the manual trigger fires a run at the level it was saved with while the configuration offers it', function (): void {
    [$service, $userId, $agentId, $captured, $handler] = triggerEffortService();

    $run = triggerEffortRun($agentId, $userId, ['effort_override' => 'low']);

    $service->triggerRun((int) $run->id, $agentId, $userId);

    expect($captured->options)->toHaveCount(1)
        ->and($captured->options[0]?->effort)->toBe('low')
        ->and(triggerEffortWarnings($handler))->toBe([]);
});
