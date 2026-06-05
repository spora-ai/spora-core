<?php

declare(strict_types=1);

use Spora\Models\Agent;

const SCHEDULED_RUN_TEST_PASSWORD = 'Password1!';
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;

it('uses the scheduled_runs table', function (): void {
    $run = new ScheduledRun();

    expect($run->getTable())->toBe('scheduled_runs');
});

it('casts is_active to bool and date columns to Carbon', function (): void {
    $userId = bootAuthLayer()->register('scheduled@example.com', SCHEDULED_RUN_TEST_PASSWORD, 'Scheduled');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Scheduled Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $run = ScheduledRun::create([
        'agent_id'        => $agent->id,
        'user_id'         => $userId,
        'timezone'        => 'UTC',
        'is_active'       => true,
        'last_run_at'     => '2099-01-01 00:00:00',
        'next_run_at'     => '2099-01-02 00:00:00',
        'max_steps_override' => 5,
    ]);

    expect($run->is_active)->toBeBool()
        ->and($run->last_run_at)->toBeInstanceOf(Carbon\Carbon::class)
        ->and($run->next_run_at)->toBeInstanceOf(Carbon\Carbon::class)
        ->and($run->max_steps_override)->toBeInt();
});

it('belongs to an agent and a template', function (): void {
    $userId = bootAuthLayer()->register('scheduled-rel@example.com', SCHEDULED_RUN_TEST_PASSWORD, 'ScheduledRel');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Rel Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $run = ScheduledRun::create([
        'agent_id'  => $agent->id,
        'user_id'   => $userId,
        'timezone'  => 'UTC',
        'is_active' => true,
    ]);

    expect($run->agent)->toBeInstanceOf(Agent::class)
        ->and((int) $run->agent->getKey())->toBe($agent->id)
        ->and($run->template)->toBeNull();
});

it('has many next run entries ordered by due_at', function (): void {
    $userId = bootAuthLayer()->register('scheduled-has@example.com', SCHEDULED_RUN_TEST_PASSWORD, 'ScheduledHas');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Has Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $run = ScheduledRun::create([
        'agent_id'  => $agent->id,
        'user_id'   => $userId,
        'timezone'  => 'UTC',
        'is_active' => true,
    ]);

    ScheduledRunNext::create([
        'scheduled_run_id' => $run->id,
        'due_at'           => '2099-01-02 00:00:00',
        'status'           => ScheduledRunNext::STATUS_PENDING,
    ]);
    ScheduledRunNext::create([
        'scheduled_run_id' => $run->id,
        'due_at'           => '2099-01-01 00:00:00',
        'status'           => ScheduledRunNext::STATUS_PENDING,
    ]);

    $next = $run->nextRuns;
    expect($next)->toHaveCount(2)
        ->and($next->first()->getAttribute('due_at')->format('Y-m-d'))->toBe('2099-01-01')
        ->and($next->last()->getAttribute('due_at')->format('Y-m-d'))->toBe('2099-01-02');
});
