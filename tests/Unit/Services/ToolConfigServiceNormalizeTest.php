<?php

declare(strict_types=1);

use Spora\Services\ToolConfigSchemaInspector;
use Spora\Tools\HandoverTool;

it('normalizes a JSON-string multi-select value to int[]', function (): void {
    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->normalizeMultiSelectValues(
        HandoverTool::class,
        ['allowed_target_agents' => '[2,5]'],
    );

    expect($result['allowed_target_agents'])->toBe([2, 5]);
});

it('passes through an already-array multi-select value', function (): void {
    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->normalizeMultiSelectValues(
        HandoverTool::class,
        ['allowed_target_agents' => [2, 5]],
    );

    expect($result['allowed_target_agents'])->toBe([2, 5]);
});

it('leaves an empty multi-select value as an empty array', function (): void {
    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->normalizeMultiSelectValues(
        HandoverTool::class,
        ['allowed_target_agents' => '[]'],
    );

    expect($result['allowed_target_agents'])->toBe([]);
});

it('coerces string IDs inside the array to int', function (): void {
    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->normalizeMultiSelectValues(
        HandoverTool::class,
        ['allowed_target_agents' => '["2","5"]'],
    );

    expect($result['allowed_target_agents'])->toBe([2, 5]);
});

it('leaves non-multi-select keys untouched', function (): void {
    $inspector = new ToolConfigSchemaInspector();
    $result = $inspector->normalizeMultiSelectValues(
        HandoverTool::class,
        ['some_text_setting' => 'hello'],
    );

    expect($result)->toBe(['some_text_setting' => 'hello']);
});
