<?php

declare(strict_types=1);

use Spora\Models\Agent;

const AGENT_TOOL_OPERATION_OVERRIDE_TEST_PASSWORD = 'Password1!';
defined('STUB_OUTPUT_TOOL_CLASS') || define('STUB_OUTPUT_TOOL_CLASS', 'Spora\\Tools\\StubOutputTool');
use Spora\Models\AgentToolOperationOverride;

it('uses the agent_tool_operation_overrides table', function (): void {
    $override = new AgentToolOperationOverride();

    expect($override->getTable())->toBe('agent_tool_operation_overrides');
});

it('allows mass assignment of operation override fields', function (): void {
    $userId = bootAuthLayer()->register('opoverride@example.com', AGENT_TOOL_OPERATION_OVERRIDE_TEST_PASSWORD, 'OpOverride');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Op Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $override = AgentToolOperationOverride::create([
        'agent_id'                 => $agent->id,
        'tool_class'               => STUB_OUTPUT_TOOL_CLASS,
        'operation'                => 'echo',
        'enabled'                  => 0,
        'default_requires_approval' => 1,
    ]);

    expect($override->operation)->toBe('echo')
        ->and($override->enabled)->toBe(0)
        ->and($override->default_requires_approval)->toBe(1);
});

it('casts enabled and default_requires_approval to int', function (): void {
    $userId = bootAuthLayer()->register('cast@example.com', AGENT_TOOL_OPERATION_OVERRIDE_TEST_PASSWORD, 'Cast');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Cast Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $override = AgentToolOperationOverride::create([
        'agent_id'                 => $agent->id,
        'tool_class'               => STUB_OUTPUT_TOOL_CLASS,
        'operation'                => 'echo',
        'enabled'                  => 1,
        'default_requires_approval' => 0,
    ]);

    expect($override->enabled)->toBeInt()
        ->and($override->default_requires_approval)->toBeInt();
});

it('belongs to an agent', function (): void {
    $userId = bootAuthLayer()->register('oprel@example.com', AGENT_TOOL_OPERATION_OVERRIDE_TEST_PASSWORD, 'OpRel');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'OpRel Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $override = AgentToolOperationOverride::create([
        'agent_id'                 => $agent->id,
        'tool_class'               => STUB_OUTPUT_TOOL_CLASS,
        'operation'                => 'echo',
        'enabled'                  => 1,
        'default_requires_approval' => 0,
    ]);

    expect($override->agent)->toBeInstanceOf(Agent::class)
        ->and((int) $override->agent->getKey())->toBe($agent->id);
});
