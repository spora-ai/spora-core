<?php

declare(strict_types=1);

use Spora\Services\MaxLengthValidator;

it('is a no-op when both payload and max-length map are empty', function (): void {
    expect(fn() => MaxLengthValidator::assertFits([], [], 'tool_calls', 'Spora\Tools\Whatever'))
        ->not()->toThrow(Throwable::class);
});

it('is a no-op when the payload has no fields the map declares', function (): void {
    expect(fn() => MaxLengthValidator::assertFits(
        ['other_field' => 'whatever'],
        ['tool_name' => 100],
        'tool_calls',
        'Spora\Tools\Whatever',
    ))->not()->toThrow(Throwable::class);
});

it('is a no-op when the max-length map is empty', function (): void {
    expect(fn() => MaxLengthValidator::assertFits(
        ['tool_name' => str_repeat('x', 1000)],
        [],
        'tool_calls',
        'Spora\Tools\Whatever',
    ))->not()->toThrow(Throwable::class);
});

it('passes when every declared value fits its cap', function (): void {
    expect(fn() => MaxLengthValidator::assertFits(
        [
            'provider_call_id' => 'call_xyz',
            'tool_name'        => 'search',
            'tool_class'       => 'Spora\Tools\SearchTool',
        ],
        [
            'provider_call_id' => 100,
            'tool_name'        => 100,
            'tool_class'       => 200,
        ],
        'tool_calls',
        'Spora\Tools\SearchTool',
    ))->not()->toThrow(Throwable::class);
});

it('accepts a value exactly at its cap (length == max is the boundary)', function (): void {
    expect(fn() => MaxLengthValidator::assertFits(
        ['tool_name' => str_repeat('x', 100)],
        ['tool_name' => 100],
        'tool_calls',
        'Spora\Tools\Whatever',
    ))->not()->toThrow(Throwable::class);
});

it('throws InvalidArgumentException with a structured message when a value exceeds its cap', function (): void {
    try {
        MaxLengthValidator::assertFits(
            ['tool_name' => str_repeat('x', 101)],
            ['tool_name' => 100],
            'tool_calls',
            'Spora\Tools\SearchTool',
        );
        $this->fail('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('tool_calls.tool_name')
            ->and($e->getMessage())->toContain('101 chars')
            ->and($e->getMessage())->toContain('column limit is 100')
            ->and($e->getMessage())->toContain('Spora\\Tools\\SearchTool');
    }
});

it('counts multi-byte characters under mb_strlen to match utf8mb4 VARCHAR semantics', function (): void {
    // 100 emoji = 100 chars under mb_strlen, fits VARCHAR(100). If the helper
    // used strlen() it would see 400 bytes and falsely reject.
    expect(fn() => MaxLengthValidator::assertFits(
        ['tool_name' => str_repeat('🚀', 100)],
        ['tool_name' => 100],
        'tool_calls',
        'Spora\Tools\Whatever',
    ))->not()->toThrow(Throwable::class);

    // 101 emoji = 101 chars, exceeds the cap and must trip the helper.
    expect(fn() => MaxLengthValidator::assertFits(
        ['tool_name' => str_repeat('🚀', 101)],
        ['tool_name' => 100],
        'tool_calls',
        'Spora\Tools\Whatever',
    ))->toThrow(InvalidArgumentException::class);
});

it('skips null values silently', function (): void {
    expect(fn() => MaxLengthValidator::assertFits(
        ['tool_name' => null],
        ['tool_name' => 100],
        'tool_calls',
        'Spora\Tools\Whatever',
    ))->not()->toThrow(Throwable::class);
});

it('skips non-string values silently (ints, arrays, bools, floats)', function (): void {
    expect(fn() => MaxLengthValidator::assertFits(
        [
            'tool_name' => 12345,
            'tool_type' => ['not', 'a', 'string'],
            'status'    => true,
            'operation' => 3.14,
        ],
        [
            'tool_name' => 100,
            'tool_type' => 10,
            'status'    => 20,
            'operation' => 100,
        ],
        'tool_calls',
        'Spora\Tools\Whatever',
    ))->not()->toThrow(Throwable::class);
});

it('skips columns that are not present in the payload', function (): void {
    // tool_name isn't in the payload — even if the map declares it, nothing to check.
    expect(fn() => MaxLengthValidator::assertFits(
        ['tool_class' => 'Spora\Tools\SearchTool'],
        ['tool_name' => 100, 'tool_class' => 200],
        'tool_calls',
        'Spora\Tools\Whatever',
    ))->not()->toThrow(Throwable::class);
});

it('throws on the first violation when multiple columns overflow', function (): void {
    // The contract is fail-fast; assertFits does not aggregate errors.
    try {
        MaxLengthValidator::assertFits(
            [
                'tool_name'  => str_repeat('x', 101),
                'tool_class' => str_repeat('y', 201),
            ],
            [
                'tool_name'  => 100,
                'tool_class' => 200,
            ],
            'tool_calls',
            'Spora\Tools\SearchTool',
        );
        $this->fail('Expected InvalidArgumentException was not thrown.');
    } catch (InvalidArgumentException $e) {
        // Fail-fast: tool_name is iterated first and trips before tool_class is reached.
        expect($e->getMessage())->toContain('tool_calls.tool_name')
            ->and($e->getMessage())->not()->toContain('tool_calls.tool_class');
    }
});

it('embeds the row and origin labels exactly as supplied', function (): void {
    // Confirms the labels are not reformatted/prefixed — callers control
    // the wording they want in the failure message.
    expect(fn() => MaxLengthValidator::assertFits(
        ['tool_name' => str_repeat('x', 6)],
        ['tool_name' => 5],
        'orders',
        'Order #42',
    ))->toThrow(
        InvalidArgumentException::class,
        'orders.tool_name for Order #42 is 6 chars; column limit is 5.',
    );
});
