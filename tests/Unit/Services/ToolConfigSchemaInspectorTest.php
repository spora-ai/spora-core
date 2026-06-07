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
    expect($defaults)->toBe(['max_results' => '10']);
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

    // TestTool has no exposeToLlm fields — result must be empty.
    $result = $inspector->getLlmToolSettings(TestTool::class, [
        'api_key'     => 'secret',
        'max_results' => '25',
    ]);

    expect($result)->toBe([]);
});
