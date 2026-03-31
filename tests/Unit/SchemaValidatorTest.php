<?php

declare(strict_types=1);

use Spora\Agents\SchemaValidator;

test('passes when all required fields are present', function (): void {
    $schema = [
        'type'       => 'object',
        'properties' => ['email' => ['type' => 'string']],
        'required'   => ['email'],
    ];

    // Should not throw.
    SchemaValidator::validate(['email' => 'user@example.com'], $schema);
    expect(true)->toBeTrue();
});

test('throws when a required field is missing', function (): void {
    $schema = [
        'type'       => 'object',
        'properties' => ['email' => ['type' => 'string']],
        'required'   => ['email'],
    ];

    expect(fn() => SchemaValidator::validate([], $schema))
        ->toThrow(InvalidArgumentException::class, "'email' is missing");
});

test('throws when supplied value has wrong type', function (): void {
    $schema = [
        'type'       => 'object',
        'properties' => ['count' => ['type' => 'integer']],
        'required'   => [],
    ];

    expect(fn() => SchemaValidator::validate(['count' => 'not-an-int'], $schema))
        ->toThrow(InvalidArgumentException::class, "'count'");
});

test('passes for correct boolean type', function (): void {
    $schema = [
        'type'       => 'object',
        'properties' => ['flag' => ['type' => 'boolean']],
        'required'   => ['flag'],
    ];

    SchemaValidator::validate(['flag' => true], $schema);
    expect(true)->toBeTrue();
});

test('extra keys beyond schema properties are silently permitted', function (): void {
    $schema = [
        'type'       => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'required'   => ['name'],
    ];

    // 'extra' is not in properties — should not throw.
    SchemaValidator::validate(['name' => 'Alice', 'extra' => 'ignored'], $schema);
    expect(true)->toBeTrue();
});

test('passes when no properties or required defined', function (): void {
    SchemaValidator::validate(['anything' => 123], ['type' => 'object', 'properties' => [], 'required' => []]);
    expect(true)->toBeTrue();
});
