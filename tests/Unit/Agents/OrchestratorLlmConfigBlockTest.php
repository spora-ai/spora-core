<?php

declare(strict_types=1);

use Spora\Agents\Orchestrator;
use Spora\Services\ToolConfigSchemaInspector;
use Spora\Tools\HandoverTool;

/**
 * The orchestrator injects the LLM-visible tool settings into the tool
 * description via a private `buildLlmConfigBlock` helper. These tests pin
 * the rendering: multi-select values must come out as resolved "Name (#id)"
 * strings, not "Array" (the result of a bare (string) cast on a list).
 */
function invokeLlmConfigBlock(array $llmSettings): string
{
    $orchestrator = (new ReflectionClass(Orchestrator::class))->newInstanceWithoutConstructor();
    $method = (new ReflectionClass(Orchestrator::class))->getMethod('buildLlmConfigBlock');
    return $method->invoke($orchestrator, $llmSettings);
}

it('renders a multi-select LLM value as resolved "Name (#id)" strings', function (): void {
    $block = invokeLlmConfigBlock([
        'allowed_target_agents' => [
            'label' => 'Allowed target agents',
            'value' => ['Legal Agent (#2)', 'Support Agent (#5)'],
        ],
    ]);

    expect($block)->toContain('Allowed target agents: Legal Agent (#2), Support Agent (#5)');
    expect($block)->not->toContain('Array');
});

it('renders a single-item multi-select without trailing punctuation', function (): void {
    $block = invokeLlmConfigBlock([
        'allowed_target_agents' => [
            'label' => 'Allowed target agents',
            'value' => ['Legal Agent (#2)'],
        ],
    ]);

    expect($block)->toContain('Allowed target agents: Legal Agent (#2)');
    expect($block)->not->toContain('Array');
});

it('shows "(not configured)" for an empty multi-select value', function (): void {
    $block = invokeLlmConfigBlock([
        'allowed_target_agents' => [
            'label' => 'Allowed target agents',
            'value' => [],
        ],
    ]);

    expect($block)->toContain('Allowed target agents: (not configured)');
});

it('shows "(not configured)" for a null value', function (): void {
    $block = invokeLlmConfigBlock([
        'some_setting' => [
            'label' => 'Some setting',
            'value' => null,
        ],
    ]);

    expect($block)->toContain('Some setting: (not configured)');
});

it('passes through scalar string values unchanged', function (): void {
    $block = invokeLlmConfigBlock([
        'smtp_host' => [
            'label' => 'SMTP host',
            'value' => 'smtp.example.com',
        ],
    ]);

    expect($block)->toContain('SMTP host: smtp.example.com');
});

it('returns an empty string for empty LLM settings', function (): void {
    $block = invokeLlmConfigBlock([]);
    expect($block)->toBe('');
});

it('renders a real HandoverTool LLM projection end-to-end', function (): void {
    $auth = bootAuthLayer();
    $userId = $auth->register('orch-llm@example.com', 'Password1!', 'OrchLlm');

    $agent = Spora\Models\Agent::create([
        'user_id'      => $userId,
        'name'         => 'Legal Agent',
        'llm_provider' => 'mock',
        'llm_model'    => 'mock',
        'max_steps'    => 5,
        'is_active'    => true,
    ]);

    $inspector = new ToolConfigSchemaInspector();
    $llm = $inspector->getLlmToolSettings(
        HandoverTool::class,
        ['allowed_target_agents' => [$agent->id]],
        $userId,
    );
    $block = invokeLlmConfigBlock($llm);

    expect($block)->toContain("Allowed target agents: Legal Agent (#{$agent->id})");
    expect($block)->not->toContain('Array');
});
