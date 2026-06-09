<?php

declare(strict_types=1);

use Spora\Services\ToolConfigSchemaInspector;
use Tests\Fixtures\TestTool;

// Pure schema inspection: no DB, no security, no logger needed.

test('getPasswordKeys returns only the keys declared with type password', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $keys = $inspector->getPasswordKeys(TestTool::class);

    // TestTool declares api_key as password, max_results and custom_field as text.
    expect($keys)->toBe(['api_key']);
});

test('getSchemaDefaults returns only the keys that have a non-null default', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $defaults = $inspector->getSchemaDefaults(TestTool::class);

    // TestTool declares max_results default = '10'. api_key and custom_field have no default.
    // multi-select keys always seed with [].
    expect($defaults)->toBe([
        'max_results'           => '10',
        'allowed_target_agents' => [],
    ]);
});

test('getSchemaDefaults returns empty array for an unknown tool class', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    expect($inspector->getSchemaDefaults('NonExistent\\Tool'))->toBe([]);
});

test('getMissingRequiredSettings returns empty for TestTool (no required fields)', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    expect($inspector->getMissingRequiredSettings(TestTool::class, [
        'api_key'     => null,
        'max_results' => '',
    ]))->toBe([]);
});

test('getMissingRequiredSettings returns empty array for an unknown tool class', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    expect($inspector->getMissingRequiredSettings('NonExistent\\Tool', ['api_key' => null]))
        ->toBe([]);
});

test('maskForApi replaces non-empty password value with ***', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $masked = $inspector->maskForApi([
        'api_key'     => 'super-secret',
        'max_results' => '10',
    ], TestTool::class);

    expect($masked['api_key'])->toBe('***');
    expect($masked['max_results'])->toBe('10');
});

test('maskForApi leaves null and empty password fields as-is', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    expect($inspector->maskForApi(['api_key' => null, 'max_results' => '5'], TestTool::class)['api_key'])
        ->toBeNull();
    expect($inspector->maskForApi(['api_key' => '', 'max_results' => '5'], TestTool::class)['api_key'])
        ->toBe('');
});

test('getLlmToolSettings only annotates exposeToLlm keys with their label and value', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    // TestTool has no exposeToLlm fields other than allowed_target_agents.
    $result = $inspector->getLlmToolSettings(TestTool::class, [
        'api_key'               => 'secret',
        'max_results'           => '25',
        'allowed_target_agents' => [],
    ]);

    expect($result)->toHaveKey('allowed_target_agents');
    expect($result)->not->toHaveKey('api_key');
    expect($result)->not->toHaveKey('max_results');
});

test('multi-select: getSchemaDefaults returns [] when no default is declared', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $defaults = $inspector->getSchemaDefaults(TestTool::class);

    expect($defaults)->toHaveKey('allowed_target_agents')
        ->and($defaults['allowed_target_agents'])->toBe([]);
});

test('multi-select: getMultiSelectKeys returns the keys declared with type multi-select', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $keys = $inspector->getMultiSelectKeys(TestTool::class);

    expect($keys)->toContain('allowed_target_agents')
        ->and($keys)->not->toContain('api_key')
        ->and($keys)->not->toContain('max_results');
});

test('multi-select: maskForApi returns array values as-is (no password masking)', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $masked = $inspector->maskForApi([
        'api_key'               => 'secret',
        'allowed_target_agents' => [1, 2, 3],
    ], TestTool::class);

    expect($masked['api_key'])->toBe('***');
    expect($masked['allowed_target_agents'])->toBe([1, 2, 3]);
});

test('multi-select: getLlmToolSettings resolves non-empty IDs to "Name (#id)" strings via Agent lookup', function (): void {
    $authService = bootAuthLayer();
    $userId = $authService->register('inspector-multiselect@example.com', 'Password1!', 'Inspector');

    $inspector = new ToolConfigSchemaInspector();

    $agentA = Spora\Models\Agent::create([
        'user_id'        => $userId,
        'name'           => 'Legal Agent',
        'llm_provider'   => 'mock',
        'llm_model'      => 'mock',
        'max_steps'      => 5,
        'is_active'      => true,
    ]);
    $agentB = Spora\Models\Agent::create([
        'user_id'        => $userId,
        'name'           => 'Sales Agent',
        'llm_provider'   => 'mock',
        'llm_model'      => 'mock',
        'max_steps'      => 5,
        'is_active'      => true,
    ]);

    $result = $inspector->getLlmToolSettings(TestTool::class, [
        'allowed_target_agents' => [$agentA->id, $agentB->id],
    ], $userId);

    expect($result)->toHaveKey('allowed_target_agents');
    expect($result['allowed_target_agents']['label'])->toBe('Allowed target agents');
    expect($result['allowed_target_agents']['value'])->toBe([
        "Legal Agent (#{$agentA->id})",
        "Sales Agent (#{$agentB->id})",
    ]);
});

test('multi-select: getLlmToolSettings returns [] for an empty multi-select value', function (): void {
    $authService = bootAuthLayer();
    $authService->register('inspector-empty@example.com', 'Password1!', 'Empty');

    $inspector = new ToolConfigSchemaInspector();

    $result = $inspector->getLlmToolSettings(TestTool::class, [
        'allowed_target_agents' => [],
    ]);

    expect($result['allowed_target_agents']['value'])->toBe([]);
});

test('multi-select: getLlmToolSettings falls back to "#id" when agent cannot be resolved', function (): void {
    $authService = bootAuthLayer();
    $authService->register('inspector-fallback@example.com', 'Password1!', 'Fallback');

    $inspector = new ToolConfigSchemaInspector();

    $result = $inspector->getLlmToolSettings(TestTool::class, [
        'allowed_target_agents' => [9999],
    ]);

    expect($result['allowed_target_agents']['value'])->toBe(['#9999']);
});
