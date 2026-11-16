<?php

declare(strict_types=1);

use Spora\Services\LlmConfigSchemaValidator;

/**
 * A `select` setting accepts only the values its option list names.
 *
 * The reasoning-effort setting is free text now — what it accepts is
 * decided by the configuration it belongs to, not by a static list — but
 * the check itself still governs every other select a driver or a plugin
 * declares.
 */
it('accepts a value the option list names', function (): void {
    $validator = new LlmConfigSchemaValidator();

    expect($validator->validateSettings('', ['effort' => 'high'], [
        ['key' => 'effort', 'label' => 'Effort', 'type' => 'select', 'options' => ['low' => 'Low', 'high' => 'High']],
    ]))->toBeNull();
});

it('refuses a value the option list does not name', function (): void {
    $validator = new LlmConfigSchemaValidator();

    expect($validator->validateSettings('', ['effort' => 'turbo'], [
        ['key' => 'effort', 'label' => 'Effort', 'type' => 'select', 'options' => ['low' => 'Low', 'high' => 'High']],
    ]))->toBe("Field 'Effort' has an invalid value.");
});

it('leaves a free-text field to the configuration it belongs to', function (): void {
    $validator = new LlmConfigSchemaValidator();

    expect($validator->validateSettings('', ['effort' => 'turbo'], [
        ['key' => 'effort', 'label' => 'Effort', 'type' => 'text', 'options' => []],
    ]))->toBeNull();
});
