<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplateValidator;

function validatePayload(array $raw): Spora\AgentTemplates\ValidationResult
{
    return (new AgentTemplateValidator())->validate($raw);
}

test('empty payload is rejected', function (): void {
    $result = validatePayload([]);
    expect($result->isValid())->toBeFalse();
    expect(array_column($result->errors(), 'code'))->toContain('EMPTY_PAYLOAD');
});

test('missing id, name, version, or agent blocks all fail validation', function (): void {
    $cases = [
        'no id'     => ['name' => 'X', 'version' => '1.0.0', 'agent' => [], 'tools' => []],
        'no name'   => ['id' => 'x', 'version' => '1.0.0', 'agent' => [], 'tools' => []],
        'no version' => ['id' => 'x', 'name' => 'X', 'agent' => [], 'tools' => []],
        'no agent'  => ['id' => 'x', 'name' => 'X', 'version' => '1.0.0', 'tools' => []],
    ];

    foreach ($cases as $label => $raw) {
        $result = validatePayload($raw);
        expect($result->isValid())->toBeFalse("case '{$label}' should be invalid");
    }
});

test('id pattern rejects uppercase and leading dash', function (): void {
    $base = [
        'name' => 'X', 'version' => '1.0.0', 'agent' => [], 'tools' => [],
    ];

    $bad = $base + ['id' => 'BadID'];
    expect(validatePayload($bad)->isValid())->toBeFalse();

    $bad = $base + ['id' => '-bad'];
    expect(validatePayload($bad)->isValid())->toBeFalse();

    $good = $base + ['id' => 'good-id_1'];
    expect(validatePayload($good)->isValid())->toBeTrue();
});

test('duplicate tool_class is rejected', function (): void {
    $raw = [
        'id' => 'dup', 'name' => 'Dup', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5],
        'tools' => [
            ['tool_class' => 'Spora\\Tools\\CurrentTimeTool', 'enabled' => true, 'operations' => [['name' => 'now']]],
            ['tool_class' => 'Spora\\Tools\\CurrentTimeTool', 'enabled' => true, 'operations' => [['name' => 'now']]],
        ],
        'required_plugins' => [],
    ];
    $result = validatePayload($raw);
    expect(array_column($result->errors(), 'code'))->toContain('TOOL_CLASS_DUPLICATE');
});

test('unknown top-level key is rejected', function (): void {
    $raw = [
        'id' => 'x', 'name' => 'X', 'version' => '1.0.0',
        'agent' => [], 'tools' => [],
        'haxx0r' => 'whatever',
    ];
    $result = validatePayload($raw);
    expect(array_column($result->errors(), 'code'))->toContain('UNKNOWN_TOP_LEVEL_KEY');
});

test('missing system_prompt produces a warning (not an error)', function (): void {
    $raw = [
        'id' => 'x', 'name' => 'X', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5],
        'tools' => [],
        'required_plugins' => [],
    ];
    $result = validatePayload($raw);
    expect($result->isValid())->toBeTrue();
    expect(array_column($result->warnings(), 'code'))->toContain('SYSTEM_PROMPT_MISSING');
});

test('unknown operation name on a known tool produces a warning', function (): void {
    $raw = [
        'id' => 'x', 'name' => 'X', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5],
        'tools' => [[
            'tool_class' => 'Spora\\Tools\\CurrentTimeTool',
            'enabled' => true,
            'operations' => [['name' => 'bogus', 'enabled' => true, 'auto_approve' => true]],
        ]],
        'required_plugins' => [],
    ];
    $result = validatePayload($raw);
    expect($result->isValid())->toBeTrue();
    expect(array_column($result->warnings(), 'code'))->toContain('OPERATION_UNKNOWN');
});

test('unknown metadata category produces a warning', function (): void {
    $raw = [
        'id' => 'x', 'name' => 'X', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5],
        'tools' => [],
        'required_plugins' => [],
        'metadata' => ['category' => 'mystery'],
    ];
    $result = validatePayload($raw);
    expect(array_column($result->warnings(), 'code'))->toContain('METADATA_CATEGORY_UNKNOWN');
});

test('max_steps out of range fails validation', function (): void {
    $raw = [
        'id' => 'x', 'name' => 'X', 'version' => '1.0.0',
        'agent' => ['max_steps' => 9999],
        'tools' => [],
        'required_plugins' => [],
    ];
    $result = validatePayload($raw);
    expect(array_column($result->errors(), 'code'))->toContain('MAX_STEPS_RANGE');
});

test('non-list tools or required_plugins fails validation', function (): void {
    $raw = [
        'id' => 'x', 'name' => 'X', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5],
        'tools' => ['not' => 'a list'],
        'required_plugins' => ['also' => 'not a list'],
    ];
    $result = validatePayload($raw);
    $codes = array_column($result->errors(), 'code');
    expect($codes)->toContain('TOOLS_NOT_LIST');
    expect($codes)->toContain('REQUIRED_PLUGINS_NOT_LIST');
});

test('valid minimal payload passes validation with no warnings', function (): void {
    $raw = [
        'id' => 'ok', 'name' => 'OK', 'version' => '1.0.0',
        'agent' => ['max_steps' => 5, 'system_prompt' => 'hello'],
        'tools' => [],
        'required_plugins' => [],
    ];
    $result = validatePayload($raw);
    expect($result->isValid())->toBeTrue();
    expect($result->warnings())->toBe([]);
});
