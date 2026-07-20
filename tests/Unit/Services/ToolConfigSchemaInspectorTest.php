<?php

declare(strict_types=1);

use Spora\Services\ToolConfigSchemaInspector;
use Tests\Fixtures\InheritedSettingChildTool;
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

test('inheritance: getPasswordKeys sees parent password settings', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $keys = $inspector->getPasswordKeys(InheritedSettingChildTool::class);

    expect($keys)->toContain('parent_secret');
});

test('inheritance: getSchemaDefaults sees parent defaults', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $defaults = $inspector->getSchemaDefaults(InheritedSettingChildTool::class);

    expect($defaults)->toHaveKey('parent_with_default')
        ->and($defaults['parent_with_default'])->toBe('inherited')
        ->and($defaults)->toHaveKey('parent_picks')
        ->and($defaults['parent_picks'])->toBe([]);
});

test('inheritance: getMissingRequiredSettings flags empty parent required fields', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $missing = $inspector->getMissingRequiredSettings(InheritedSettingChildTool::class, [
        'child_only' => 'value',
    ]);

    expect($missing)->toContain('parent_required');
});

test('inheritance: getMissingRequiredSettings passes when parent required field is set', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $missing = $inspector->getMissingRequiredSettings(InheritedSettingChildTool::class, [
        'parent_required' => 'configured',
    ]);

    expect($missing)->not->toContain('parent_required');
});

test('inheritance: maskForApi masks parent password fields', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $masked = $inspector->maskForApi([
        'parent_secret' => 'super-secret',
        'child_only'    => 'value',
    ], InheritedSettingChildTool::class);

    expect($masked['parent_secret'])->toBe('***')
        ->and($masked['child_only'])->toBe('value');
});

test('inheritance: getMultiSelectKeys sees parent multi-select settings', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $keys = $inspector->getMultiSelectKeys(InheritedSettingChildTool::class);

    expect($keys)->toContain('parent_picks')
        ->and($keys)->not->toContain('child_only');
});

test('inheritance: normalizeMultiSelectValues coerces parent multi-select JSON to int[]', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $normalized = $inspector->normalizeMultiSelectValues(
        InheritedSettingChildTool::class,
        ['parent_picks' => '[1,2,3]'],
    );

    expect($normalized['parent_picks'])->toBe([1, 2, 3]);
});

test('inheritance: getLlmToolSettings includes parent exposeToLlm settings', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $result = $inspector->getLlmToolSettings(InheritedSettingChildTool::class, [
        'parent_visible' => 'yes',
        'parent_picks'   => [],
    ]);

    expect($result)->toHaveKey('parent_visible')
        ->and($result['parent_visible']['label'])->toBe('Parent Visible')
        ->and($result['parent_visible']['value'])->toBe('yes')
        ->and($result)->toHaveKey('parent_picks');
});

test('inheritance: subclass redeclaration of a parent key wins (no duplicates, child value)', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $defaults = $inspector->getSchemaDefaults(InheritedSettingChildTool::class);
    expect($defaults)->toHaveKey('shared_key')
        ->and($defaults['shared_key'])->toBe('child-default');

    $missing = $inspector->getMissingRequiredSettings(InheritedSettingChildTool::class, []);
    expect(array_count_values($missing)['shared_key'] ?? 0)->toBe(0);
});

test('inheritance: getSchemaDefaults uses child value when child redeclares a parent key', function (): void {
    $inspector = new ToolConfigSchemaInspector();

    $defaults = $inspector->getSchemaDefaults(InheritedSettingChildTool::class);

    expect($defaults['shared_key'])->toBe('child-default');
});
