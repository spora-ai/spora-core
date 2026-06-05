<?php

declare(strict_types=1);

use Spora\Models\Agent;

const SCHEDULED_RUN_NEXT_TEST_PASSWORD = 'Password1!';
use Spora\Models\ScheduledRun;
use Spora\Models\ScheduledRunNext;

it('uses the scheduled_runs_next table', function (): void {
    $entry = new ScheduledRunNext();

    expect($entry->getTable())->toBe('scheduled_runs_next');
});

it('exposes the four status constants', function (): void {
    expect(ScheduledRunNext::STATUS_PENDING)->toBe('PENDING')
        ->and(ScheduledRunNext::STATUS_CLAIMED)->toBe('CLAIMED')
        ->and(ScheduledRunNext::STATUS_DONE)->toBe('DONE')
        ->and(ScheduledRunNext::STATUS_SKIPPED)->toBe('SKIPPED');
});

it('allows mass assignment of status and due_at', function (): void {
    $userId = bootAuthLayer()->register('schednext@example.com', SCHEDULED_RUN_NEXT_TEST_PASSWORD, 'SchedNext');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Sched Agent',
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

    $entry = ScheduledRunNext::create([
        'scheduled_run_id' => $run->id,
        'due_at'           => '2099-01-01 00:00:00',
        'status'           => ScheduledRunNext::STATUS_PENDING,
    ]);

    expect($entry->status)->toBe('PENDING')
        ->and($entry->due_at)->toBeInstanceOf(Carbon\Carbon::class);
});

it('casts date columns to Carbon', function (): void {
    $userId = bootAuthLayer()->register('schedcast@example.com', SCHEDULED_RUN_NEXT_TEST_PASSWORD, 'SchedCast');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Cast Agent',
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

    $entry = ScheduledRunNext::create([
        'scheduled_run_id' => $run->id,
        'due_at'           => '2099-01-01 00:00:00',
        'status'           => ScheduledRunNext::STATUS_CLAIMED,
        'claimed_at'       => '2099-01-01 00:00:05',
        'completed_at'     => '2099-01-01 00:00:10',
    ]);

    expect($entry->due_at)->toBeInstanceOf(Carbon\Carbon::class)
        ->and($entry->claimed_at)->toBeInstanceOf(Carbon\Carbon::class)
        ->and($entry->completed_at)->toBeInstanceOf(Carbon\Carbon::class);
});

it('belongs to a scheduled run', function (): void {
    $userId = bootAuthLayer()->register('schedrel@example.com', SCHEDULED_RUN_NEXT_TEST_PASSWORD, 'SchedRel');
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
    $entry = ScheduledRunNext::create([
        'scheduled_run_id' => $run->id,
        'due_at'           => '2099-01-01 00:00:00',
        'status'           => ScheduledRunNext::STATUS_PENDING,
    ]);

    expect($entry->scheduledRun)->toBeInstanceOf(ScheduledRun::class)
        ->and((int) $entry->scheduledRun->getKey())->toBe($run->id);
});
