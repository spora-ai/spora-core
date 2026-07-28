<?php

declare(strict_types=1);

use Spora\Drivers\Utilities\ToolArgumentsNormalizer;

it('leaves a list of primitives alone', function (): void {
    expect(ToolArgumentsNormalizer::unboxItemWrappers(['weather', 'serper']))
        ->toBe(['weather', 'serper']);
});

it('unwraps {item: [...]} into a plain list', function (): void {
    expect(ToolArgumentsNormalizer::unboxItemWrappers(['item' => ['weather']]))
        ->toBe(['weather']);
});

it('unwraps {items: [...]} into a plain list', function (): void {
    expect(ToolArgumentsNormalizer::unboxItemWrappers(['items' => ['a', 'b']]))
        ->toBe(['a', 'b']);
});

it('does not touch an arbitrary single-key object', function (): void {
    expect(ToolArgumentsNormalizer::unboxItemWrappers(['id' => 'weather-agent']))
        ->toBe(['id' => 'weather-agent']);
});

it('does not touch a {item: scalar} shape', function (): void {
    // The wrapper's value has to be an array, not a scalar — a real
    // legitimate key named `item` whose value happens to be a string is
    // never confused for a wrap, so legacy single-key payloads survive.
    expect(ToolArgumentsNormalizer::unboxItemWrappers(['item' => 'literal-value']))
        ->toBe(['item' => 'literal-value']);
});

it('walks recursive structures and unwraps nested item wrappers', function (): void {
    $input = [
        'id'   => 'weather-agent',
        'tools' => [
            'item' => [
                [
                    'tool_class' => 'Spora\\Tools\\TimeTool',
                    'operations' => ['item' => [
                        ['name' => 'now'],
                        ['name' => 'format'],
                    ]],
                ],
            ],
        ],
        'required_plugins' => ['item' => ['weather']],
    ];

    expect(ToolArgumentsNormalizer::unboxItemWrappers($input))->toBe([
        'id'   => 'weather-agent',
        'tools' => [
            [
                'tool_class' => 'Spora\\Tools\\TimeTool',
                'operations' => [
                    ['name' => 'now'],
                    ['name' => 'format'],
                ],
            ],
        ],
        'required_plugins' => ['weather'],
    ]);
});

it('returns the input unchanged when there are no wrappers', function (): void {
    $input = ['id' => 'weather-agent', 'name' => 'Weather Agent', 'version' => '1.0.0'];
    expect(ToolArgumentsNormalizer::unboxItemWrappers($input))->toBe($input);
});

it('handles top-level lists with wrapped elements', function (): void {
    expect(ToolArgumentsNormalizer::unboxItemWrappers([['item' => ['nested' => 1]]]))
        ->toBe([['nested' => 1]]);
});

it('coerces stringified booleans for declared boolean properties', function (): void {
    $properties = [
        'enabled'      => ['type' => 'boolean'],
        'auto_approve' => ['type' => 'boolean'],
        'name'         => ['type' => 'string'],
    ];

    expect(ToolArgumentsNormalizer::coerceScalarStrings(
        ['enabled' => 'true', 'auto_approve' => 'false', 'name' => 'now'],
        $properties,
    ))->toBe(['enabled' => true, 'auto_approve' => false, 'name' => 'now']);
});

it('coerces stringified ints and floats for declared numeric properties', function (): void {
    $properties = [
        'max_steps' => ['type' => 'integer'],
        'ratio'     => ['type' => 'number'],
    ];

    expect(ToolArgumentsNormalizer::coerceScalarStrings(
        ['max_steps' => '10', 'ratio' => '1.5'],
        $properties,
    ))->toBe(['max_steps' => 10, 'ratio' => 1.5]);
});

it('leaves non-numeric strings alone for integer/number properties', function (): void {
    $properties = ['max_steps' => ['type' => 'integer']];

    expect(ToolArgumentsNormalizer::coerceScalarStrings(
        ['max_steps' => 'not-a-number'],
        $properties,
    ))->toBe(['max_steps' => 'not-a-number']);
});

it('leaves declared-string properties alone when they receive strings', function (): void {
    $properties = ['content' => ['type' => 'string']];

    expect(ToolArgumentsNormalizer::coerceScalarStrings(
        ['content' => 'true'],
        $properties,
    ))->toBe(['content' => 'true']);
});

it('does not touch arguments for properties absent from the schema', function (): void {
    $properties = ['enabled' => ['type' => 'boolean']];

    // `note` is not in the schema; it stays a string regardless.
    expect(ToolArgumentsNormalizer::coerceScalarStrings(
        ['enabled' => 'true', 'note' => 'true'],
        $properties,
    ))->toBe(['enabled' => true, 'note' => 'true']);
});

it('coerces inside nested objects when the schema declares them', function (): void {
    $properties = [
        'agent' => [
            'type'       => 'object',
            'properties' => [
                'allow_followup' => ['type' => 'boolean'],
                'max_steps'      => ['type' => 'integer'],
            ],
        ],
    ];

    expect(ToolArgumentsNormalizer::coerceScalarStrings(
        ['agent' => ['allow_followup' => 'true', 'max_steps' => '10']],
        $properties,
    ))->toBe(['agent' => ['allow_followup' => true, 'max_steps' => 10]]);
});
