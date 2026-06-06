<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\AgentTool;

const AGENT_TOOL_TEST_PASSWORD = 'Password1!';
defined('STUB_OUTPUT_TOOL_CLASS') || define('STUB_OUTPUT_TOOL_CLASS', 'Spora\\Tools\\StubOutputTool');

it('uses the agent_tools table', function (): void {
    $tool = new AgentTool();

    expect($tool->getTable())->toBe('agent_tools');
});

it('allows mass assignment of agent_id, tool_class, tool_name', function (): void {
    $userId = bootAuthLayer()->register('modeltest@example.com', AGENT_TOOL_TEST_PASSWORD, 'Model Test');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Test Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $tool = AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => STUB_OUTPUT_TOOL_CLASS,
        'tool_name'  => 'stub_output',
    ]);

    expect($tool->agent_id)->toBe($agent->id)
        ->and($tool->tool_class)->toBe(STUB_OUTPUT_TOOL_CLASS)
        ->and($tool->tool_name)->toBe('stub_output');
});

it('belongs to an agent', function (): void {
    $userId = bootAuthLayer()->register('relation@example.com', AGENT_TOOL_TEST_PASSWORD, 'Relation');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Relation Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $tool = AgentTool::create([
        'agent_id'   => $agent->id,
        'tool_class' => STUB_OUTPUT_TOOL_CLASS,
        'tool_name'  => 'stub_output',
    ]);

    expect($tool->agent)->toBeInstanceOf(Agent::class)
        ->and((int) $tool->agent->getKey())->toBe($agent->id);
});
