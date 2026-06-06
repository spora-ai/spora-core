<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Models\AgentToolOverride;

const AGENT_TOOL_OVERRIDE_TEST_PASSWORD = 'Password1!';
defined('STUB_OUTPUT_TOOL_CLASS') || define('STUB_OUTPUT_TOOL_CLASS', 'Spora\\Tools\\StubOutputTool');

it('uses the agent_tool_overrides table', function (): void {
    $override = new AgentToolOverride();

    expect($override->getTable())->toBe('agent_tool_overrides');
});

it('allows mass assignment of agent_id, tool_class, settings', function (): void {
    $userId = bootAuthLayer()->register('override@example.com', AGENT_TOOL_OVERRIDE_TEST_PASSWORD, 'Override');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Override Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);

    $override = AgentToolOverride::create([
        'agent_id'   => $agent->id,
        'tool_class' => STUB_OUTPUT_TOOL_CLASS,
        'settings'   => 'encrypted-blob',
    ]);

    expect($override->getAttribute('agent_id'))->toBe($agent->id)
        ->and($override->getAttribute('tool_class'))->toBe(STUB_OUTPUT_TOOL_CLASS)
        ->and($override->getAttributes())->toHaveKey('settings');
});

it('throws LogicException when settings attribute is accessed directly', function (): void {
    $override = new AgentToolOverride();

    expect(fn() => $override->settings)->toThrow(LogicException::class);
});

it('belongs to an agent', function (): void {
    $userId = bootAuthLayer()->register('override2@example.com', AGENT_TOOL_OVERRIDE_TEST_PASSWORD, 'Override 2');
    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Override Agent 2',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 10,
        'is_active'    => true,
    ]);
    $override = AgentToolOverride::create([
        'agent_id'   => $agent->id,
        'tool_class' => STUB_OUTPUT_TOOL_CLASS,
        'settings'   => 'encrypted-blob',
    ]);

    expect($override->agent)->toBeInstanceOf(Agent::class)
        ->and((int) $override->agent->getKey())->toBe($agent->id);
});
