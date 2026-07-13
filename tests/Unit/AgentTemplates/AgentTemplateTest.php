<?php

declare(strict_types=1);

use Spora\AgentTemplates\AgentTemplate;
use Spora\AgentTemplates\ValidationResult;

test('AgentTemplate accessors fall back safely on missing or malformed fields', function (): void {
    $template = new AgentTemplate(raw: []);

    expect($template->id())->toBe('')
        ->and($template->name())->toBe('')
        ->and($template->description())->toBeNull()
        ->and($template->version())->toBe('1.0.0')
        ->and($template->agent())->toBe([])
        ->and($template->tools())->toBe([])
        ->and($template->requiredPlugins())->toBe([])
        ->and($template->metadata())->toBe([])
        ->and($template->source())->toBeNull()
        ->and($template->filename())->toBeNull()
        ->and($template->hasWarnings())->toBeFalse();
});

test('AgentTemplate skips non-list and non-array entries when reading tools', function (): void {
    $raw = [
        'tools' => [
            'not-an-object',
            null,
            ['tool_class' => 'Spora\\Tools\\CalculatorTool', 'enabled' => true, 'operations' => []],
        ],
    ];
    $template = new AgentTemplate(raw: $raw);

    // Only the array entry survives — scalars and nulls are dropped
    // by AgentTemplate::tools() so the importer can safely iterate.
    expect($template->tools())->toHaveCount(1);
});

test('AgentTemplate filters requiredPlugins to strings and drops empties', function (): void {
    $raw = [
        'required_plugins' => ['weather', '', null, 42, 'minimax', '   '],
    ];
    $template = new AgentTemplate(raw: $raw);

    expect($template->requiredPlugins())->toBe(['weather', 'minimax', '   ']);
});

test('AgentTemplate::addWarning + hasWarnings round-trip', function (): void {
    $template = new AgentTemplate(raw: ['id' => 'x']);
    expect($template->hasWarnings())->toBeFalse();

    $template->addWarning([
        'code'     => 'TEST_WARNING',
        'severity' => 'warning',
        'message'  => 'Hello.',
    ]);
    expect($template->hasWarnings())->toBeTrue();
    expect($template->warnings())->toHaveCount(1);
});

test('ValidationResult serializes errors and warnings distinctly', function (): void {
    $result = new ValidationResult();

    expect($result->isValid())->toBeTrue()
        ->and($result->errors())->toBe([])
        ->and($result->warnings())->toBe([]);

    $result->addError([
        'code'     => 'ERR',
        'severity' => 'error',
        'message'  => 'oops',
    ]);
    $result->addWarning([
        'code'     => 'WARN',
        'severity' => 'warning',
        'message'  => 'fyi',
    ]);

    expect($result->isValid())->toBeFalse();
    $array = $result->toArray();
    expect($array['valid'])->toBeFalse();
    expect(array_column($array['errors'], 'code'))->toBe(['ERR']);
    expect(array_column($array['warnings'], 'code'))->toBe(['WARN']);
});
