<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Services\LlmConfigSchemaValidator;

/**
 * A `select` setting accepts only the values its option list names.
 *
 * The effort setting is the one that matters: a configuration that saved
 * `effort: "turbo"` would otherwise carry a typo all the way to the
 * provider, which answers with a 400 nobody can act on.
 */
it('accepts a value the option list names', function (): void {
    $validator = new LlmConfigSchemaValidator();
    $schema = $validator->getSchemaForDriver(OpenAICompatibleDriver::class);

    expect($validator->validateSettings(['effort' => 'high'], $schema))->toBeNull();
});

it('accepts the empty value, which is the model default', function (): void {
    $validator = new LlmConfigSchemaValidator();
    $schema = $validator->getSchemaForDriver(OpenAICompatibleDriver::class);

    expect($validator->validateSettings(['effort' => ''], $schema))->toBeNull();
});

it('refuses a value the option list does not name', function (): void {
    $validator = new LlmConfigSchemaValidator();
    $schema = $validator->getSchemaForDriver(OpenAICompatibleDriver::class);

    expect($validator->validateSettings(['effort' => 'turbo'], $schema))
        ->toBe("Field 'Effort' has an invalid value.");
});

it('refuses a level the driver itself does not offer', function (): void {
    $validator = new LlmConfigSchemaValidator();
    $schema = $validator->getSchemaForDriver(AnthropicCompatibleDriver::class);

    expect($validator->validateSettings(['effort' => 'none'], $schema))
        ->toBe("Field 'Effort' has an invalid value.");
});
