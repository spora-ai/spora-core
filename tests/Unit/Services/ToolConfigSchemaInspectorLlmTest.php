<?php

declare(strict_types=1);

use Spora\Models\Agent;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Tools\HandoverTool;

const HANDOVER_LLM_TEST_PW = 'Password1!';

it('renders multi-select values as resolved "Name (#id)" strings for the LLM', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('llm-render@example.com', HANDOVER_LLM_TEST_PW, 'LlmRender');

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
        $userId,
    );

    expect($result)->toHaveKey('allowed_target_agents');
    expect($result['allowed_target_agents']['value'])->toBe(["Legal Agent (#{$agent->id})"]);
    expect($result['allowed_target_agents']['label'])->toBe('Allowed target agents');
});

it('falls back to "#id" when the agent name cannot be resolved', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('llm-unresolved@example.com', HANDOVER_LLM_TEST_PW, 'LlmUnresolved');

    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->getLlmToolSettings(
        HandoverTool::class,
        ['allowed_target_agents' => [9999]],
        $userId,
    );

    expect($result['allowed_target_agents']['value'])->toBe(['#9999']);
});

it('falls back to "#id" when no userId is supplied (cannot prove ownership)', function (): void {
    $inspector = new ToolConfigSchemaInspector();
    // No $userId — without it we can't scope the lookup, so we refuse to
    // resolve names to avoid a cross-tenant leak.
    $result = $inspector->getLlmToolSettings(
        HandoverTool::class,
        ['allowed_target_agents' => [1, 2]],
    );

    expect($result['allowed_target_agents']['value'])->toBe(['#1', '#2']);
});

it('does NOT resolve agent names that belong to a different user (cross-tenant guard)', function (): void {
    $auth = bootAuthLayer();
    $ownerId   = $auth->register('owner@example.com', HANDOVER_LLM_TEST_PW, 'Owner');
    $strangerId = $auth->register('stranger@example.com', HANDOVER_LLM_TEST_PW, 'Stranger');

    $strangerAgent = Agent::create([
        'user_id'      => $strangerId,
        'name'         => 'Stranger Secret Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $inspector = new ToolConfigSchemaInspector();
    // The owner is asking for the LLM projection, but the multi-select contains
    // an id belonging to a different user — that name must NOT leak through.
    $result = $inspector->getLlmToolSettings(
        HandoverTool::class,
        ['allowed_target_agents' => [$strangerAgent->id]],
        $ownerId,
    );

    expect($result['allowed_target_agents']['value'])->toBe(["#{$strangerAgent->id}"]);
});

it('handles an empty multi-select value', function (): void {
    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->getLlmToolSettings(
        HandoverTool::class,
        ['allowed_target_agents' => []],
    );

    expect($result['allowed_target_agents']['value'])->toBe([]);
});
