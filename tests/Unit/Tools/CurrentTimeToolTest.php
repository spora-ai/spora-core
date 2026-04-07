<?php

declare(strict_types=1);

use Spora\Tools\CurrentTimeTool;

it('returns the current time formatted', function () {
    $tool = new CurrentTimeTool();
    $result = $tool->execute([], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Current Date & Time:')
        ->and($result->content)->toContain('Timezone:')
        ->and($result->content)->toContain('Unix Timestamp:');
});

it('has an empty parameter schema', function () {
    $tool = new CurrentTimeTool();
    $schema = $tool->getParametersSchema();

    expect($schema['type'])->toBe('object')
        // properties must be a stdClass (empty object {}), not [] (empty sequential array).
        // The OpenAI API rejects "properties": [] as invalid JSON Schema.
        ->and($schema['properties'])->toBeInstanceOf(stdClass::class)
        ->and((array) $schema['properties'])->toBeEmpty()
        ->and($schema['required'])->toBeEmpty();
});
