<?php

declare(strict_types=1);

use Spora\Agents\OrchestratorInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\Exceptions\EffortValidationException;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\ScheduledRun;
use Spora\Services\MercurePublisherInterface;
use Spora\Services\ScheduledRunService;

/**
 * The reasoning effort a scheduled run carries.
 *
 * A run's `effort_override` is the level the run fires at, whatever the
 * template it points at says. The level has to survive the write and the
 * read, and a level the agent's driver does not offer must be refused at the
 * write.
 */
afterEach(fn() => Spora\Core\Database::resetBootState());

/**
 * @return array{0: ScheduledRunService, 1: int, 2: int} the service, the owner and the agent
 */
function scheduledRunEffortFixture(): array
{
    $authService = bootAuthLayer();
    static $seq = 0;
    $seq++;
    $userId = bootAuth($authService, "run-effort-{$seq}@example.com", 'Password1!', 'Run Effort');

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Run Effort Config',
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
        'name'                 => 'Run Effort Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps'            => 10,
        'is_active'            => true,
    ]);

    /** @var Mockery\MockInterface&MercurePublisherInterface $mercure */
    $mercure = Mockery::mock(MercurePublisherInterface::class)->shouldIgnoreMissing();
    $mercure->allows('publish')->andReturn(true);

    $service = new ScheduledRunService(
        Mockery::mock(OrchestratorInterface::class),
        $mercure,
        makeEffortService(),
    );

    return [$service, $userId, (int) $agent->id];
}

/**
 * @return array<string, mixed>
 */
function scheduledRunEffortPayload(array $overrides = []): array
{
    return array_merge([
        'raw_prompt'      => 'Summarize the inbox',
        'cron_expression' => '0 7 * * *',
        'timezone'        => 'UTC',
    ], $overrides);
}

it('stores the effort override a run was created with and reads it back', function (): void {
    [$service, $userId, $agentId] = scheduledRunEffortFixture();

    $created = $service->createRun($agentId, $userId, scheduledRunEffortPayload([
        'effort_override' => 'high',
    ]));

    expect($created['scheduled_run']['effort_override'])->toBe('high');

    $read = $service->getRun((int) $created['scheduled_run']['id'], $agentId, $userId);

    expect($read['scheduled_run']['effort_override'])->toBe('high');
});

it('a run without an override inherits, and one with a level carries it', function (): void {
    [$service, $userId, $agentId] = scheduledRunEffortFixture();

    $inheriting = $service->createRun($agentId, $userId, scheduledRunEffortPayload());
    $deep = $service->createRun($agentId, $userId, scheduledRunEffortPayload([
        'effort_override' => 'high',
    ]));

    expect($inheriting['scheduled_run']['effort_override'])->toBeNull()
        ->and(ScheduledRun::find((int) $inheriting['scheduled_run']['id'])->effort_override)->toBeNull()
        ->and($deep['scheduled_run']['effort_override'])->toBe('high');
});

it('an update replaces the effort override', function (): void {
    [$service, $userId, $agentId] = scheduledRunEffortFixture();

    $created = $service->createRun($agentId, $userId, scheduledRunEffortPayload([
        'effort_override' => 'high',
    ]));

    $updated = $service->updateRun((int) $created['scheduled_run']['id'], $agentId, $userId, [
        'effort_override' => 'low',
    ]);

    expect($updated['scheduled_run']['effort_override'])->toBe('low');
});

it('an update with null clears the override, so the run inherits again', function (): void {
    [$service, $userId, $agentId] = scheduledRunEffortFixture();

    $created = $service->createRun($agentId, $userId, scheduledRunEffortPayload([
        'effort_override' => 'high',
    ]));

    expect($created['scheduled_run']['effort_override'])->toBe('high');

    $updated = $service->updateRun((int) $created['scheduled_run']['id'], $agentId, $userId, [
        'effort_override' => null,
    ]);

    expect($updated['scheduled_run']['effort_override'])->toBeNull()
        ->and(ScheduledRun::find((int) $created['scheduled_run']['id'])->effort_override)->toBeNull();
});

it('refuses an override the agent\'s driver does not offer, and names the ones it does', function (): void {
    [$service, $userId, $agentId] = scheduledRunEffortFixture();

    try {
        $service->createRun($agentId, $userId, scheduledRunEffortPayload([
            'effort_override' => 'ultra',
        ]));
    } catch (EffortValidationException $e) {
        expect($e->errorCode())->toBe('EFFORT_LEVEL_UNSUPPORTED')
            ->and($e->getMessage())->toContain('Supported levels:');

        return;
    }

    $this->fail('Expected an EffortValidationException.');
});

it('refuses an unsupported override on an update too', function (): void {
    [$service, $userId, $agentId] = scheduledRunEffortFixture();

    $created = $service->createRun($agentId, $userId, scheduledRunEffortPayload([
        'effort_override' => 'high',
    ]));

    expect(fn() => $service->updateRun((int) $created['scheduled_run']['id'], $agentId, $userId, [
        'effort_override' => 'ultra',
    ]))->toThrow(EffortValidationException::class, 'Supported levels:');
});
