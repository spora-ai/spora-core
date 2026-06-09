<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Tools\HandoverTool;

it('renders multi-select values as resolved "Name (#id)" strings for the LLM', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('llm-render@example.com', 'Password1!', 'LlmRender');

    $agent = Agent::create([
        'user_id'      => $userId,
        'name'         => 'Legal Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->getLlmToolSettings(
        HandoverTool::class,
        ['allowed_target_agents' => [$agent->id]],
    );

    expect($result)->toHaveKey('allowed_target_agents');
    expect($result['allowed_target_agents']['value'])->toBe(["Legal Agent (#{$agent->id})"]);
    expect($result['allowed_target_agents']['label'])->toBe('Allowed target agents');
});

it('falls back to "#id" when the agent name cannot be resolved', function (): void {
    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->getLlmToolSettings(
        HandoverTool::class,
        ['allowed_target_agents' => [9999]],
    );

    expect($result['allowed_target_agents']['value'])->toBe(['#9999']);
});

it('handles an empty multi-select value', function (): void {
    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->getLlmToolSettings(
        HandoverTool::class,
        ['allowed_target_agents' => []],
    );

    expect($result['allowed_target_agents']['value'])->toBe([]);
});
