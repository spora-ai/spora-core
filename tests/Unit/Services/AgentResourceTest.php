<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Services\AgentResource;

it('maps every wire-format field for an agent', function (): void {
    $userId = bootAuthLayer()->register('agent-resource@example.com', 'Password1!', 'AR');

    $agent = Agent::create([
        'user_id'              => $userId,
        'name'                 => 'Mapped Agent',
        'description'          => 'desc',
        'system_prompt'        => 'sp',
        'llm_driver_config_id' => null,
        'max_steps'            => 12,
        'is_active'            => true,
        'allow_followup'       => true,
        'retry_after_minutes'  => 3,
        'max_retries'          => 4,
        'is_pinned'            => true,
        'is_archived'          => false,
    ]);

    $array = AgentResource::toArray($agent);

    expect($array)
        ->toHaveKeys([
            'id', 'name', 'description', 'system_prompt',
            'llm_driver_config_id', 'max_steps',
            'is_active', 'allow_followup',
            'retry_after_minutes', 'max_retries',
            'is_pinned', 'is_archived',
            'created_at', 'tools',
        ])
        ->and($array['id'])->toBe((int) $agent->id)
        ->and($array['name'])->toBe('Mapped Agent')
        ->and($array['description'])->toBe('desc')
        ->and($array['system_prompt'])->toBe('sp')
        ->and($array['llm_driver_config_id'])->toBeNull()
        ->and($array['max_steps'])->toBe(12)
        ->and($array['is_active'])->toBeTrue()
        ->and($array['allow_followup'])->toBeTrue()
        ->and($array['retry_after_minutes'])->toBe(3)
        ->and($array['max_retries'])->toBe(4)
        ->and($array['is_pinned'])->toBeTrue()
        ->and($array['is_archived'])->toBeFalse()
        ->and($array['created_at'])->toBeString()
        ->and($array['tools'])->toBe([]);
});

it('defaults is_pinned and is_archived to false when the model columns are null', function (): void {
    // New Agent() without save() leaves the boolean fields null. The mapper
    // must coalesce both to false so clients never see null on a flag column.
    $agent = new Agent();
    $agent->name = 'Unset';
    $agent->max_steps = 10;
    $agent->allow_followup = false;

    $array = AgentResource::toArray($agent);

    expect($array['is_pinned'])->toBeFalse()
        ->and($array['is_archived'])->toBeFalse()
        ->and($array['created_at'])->toBeNull();
});

it('formats created_at as ATOM and emits an empty tools list when the relationship is empty', function (): void {
    $userId = bootAuthLayer()->register('agent-resource-atom@example.com', 'Password1!', 'AR');

    $agent = Agent::create([
        'user_id'   => $userId,
        'name'      => 'Atom Agent',
        'max_steps' => 10,
    ]);

    $array = AgentResource::toArray($agent);

    expect($array['created_at'])
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[\+\-]\d{2}:\d{2}$/')
        ->and($array['tools'])->toBe([]);
});
