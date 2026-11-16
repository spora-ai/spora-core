<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LlmConfigSchemaValidator;
use Spora\Services\LLMConfigService;
use Spora\Services\LlmConfigValidator;

/**
 * The reasoning-effort default on the save path.
 *
 * The default is free text, because a static option list cannot describe
 * a per-row level set. What it accepts is therefore decided by the
 * configuration the value belongs to: the driver the settings describe,
 * asked for the levels it offers.
 *
 * The validator builds the driver rather than asking a static, so the
 * save path and the runtime cannot disagree about which levels a
 * configuration offers.
 */
function effortValidatorDriverFactory(): DriverFactory
{
    $llmConfigService = new LLMConfigService(
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        [OpenAICompatibleDriver::class, AnthropicCompatibleDriver::class],
    );

    return new DriverFactory(new NullLogger(), $llmConfigService, 60);
}

function effortValidator(): LlmConfigSchemaValidator
{
    return new LlmConfigSchemaValidator(effortValidatorDriverFactory());
}

/**
 * @param array<string, mixed> $settings
 */
function effortValidatorError(array $settings): ?string
{
    $validator = effortValidator();

    return $validator->validateSettings(
        OpenAICompatibleDriver::class,
        $settings,
        $validator->getSchemaForDriver(OpenAICompatibleDriver::class),
    );
}

test('an effort the level set does not offer is refused, naming the levels it does offer', function (): void {
    expect(effortValidatorError(['effort' => 'medium', 'effort_levels' => 'low, high']))
        ->toBe('effort "medium" is not offered by this LLM configuration. Supported levels: low, high.');
});

test('an effort the level set offers is accepted', function (): void {
    expect(effortValidatorError(['effort' => 'low', 'effort_levels' => 'low, high']))->toBeNull();
});

test('an effort with surrounding whitespace is refused, naming the levels it offers', function (): void {
    // A level name cannot carry whitespace, so " high " is not a level
    // name — and the value that is checked is the value that is saved, or
    // the saved default would silently lose to the model's own.
    expect(effortValidatorError(['effort' => ' high ', 'effort_levels' => 'low, high']))
        ->toBe('effort " high " is not offered by this LLM configuration. Supported levels: low, high.');
});

test('a level named with digits is offered and accepted', function (): void {
    // The levels are named "1" and "2", so the driver's map is keyed by
    // the int 1 and 2 — PHP turns a numeric-string key into an int.
    expect(effortValidatorError(['effort' => '1', 'effort_levels' => '1,2']))->toBeNull();
});

test('an effort outside the model\'s own set is refused when no list is configured', function (): void {
    // The Anthropic driver takes no `none` and no `minimal`.
    $validator = effortValidator();

    $error = $validator->validateSettings(
        AnthropicCompatibleDriver::class,
        ['effort' => 'none'],
        $validator->getSchemaForDriver(AnthropicCompatibleDriver::class),
    );

    expect($error)->toContain('effort "none" is not offered by this LLM configuration.')
        ->and($error)->toContain('Supported levels: low, medium, high, xhigh, max');
});

test('a malformed level list is refused with the reason', function (): void {
    expect(effortValidatorError(['effort_levels' => 'low, not a level']))
        ->toContain('may only contain letters, digits, dots, dashes and underscores');
});

test('a level name that is too long is refused with the reason', function (): void {
    expect(effortValidatorError(['effort_levels' => 'a-very-long-level-name']))
        ->toContain('is longer than 16 characters');
});

test('an empty default is the model default and is accepted', function (): void {
    expect(effortValidatorError(['effort' => '', 'effort_levels' => 'low, high']))->toBeNull();
});

/**
 * A configuration whose stored default is `medium`.
 */
function effortValidatorStoredConfig(): LLMDriverConfiguration
{
    return createTestGlobalConfig(
        'Effort Validator Config',
        OpenAICompatibleDriver::class,
        ['api_key' => 'test', 'model' => 'gpt-4o', 'effort' => 'medium'],
        true,
    );
}

test('narrowing the level list under a saved default is refused', function (): void {
    $llmConfigService = new LLMConfigService(
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        [OpenAICompatibleDriver::class],
    );

    $validator = new LlmConfigValidator(
        $llmConfigService,
        new LlmConfigSchemaValidator(effortValidatorDriverFactory()),
    );

    $response = $validator->validateUpdateSettings(
        ['settings' => ['effort_levels' => 'low, high']],
        effortValidatorStoredConfig(),
    );

    expect($response)->not->toBeNull()
        ->and($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['message'])
        ->toBe('effort "medium" is not offered by this LLM configuration. Supported levels: low, high.');
});

test('narrowing the level list to a set that still holds the saved default is accepted', function (): void {
    $llmConfigService = new LLMConfigService(
        new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        [OpenAICompatibleDriver::class],
    );

    $validator = new LlmConfigValidator(
        $llmConfigService,
        new LlmConfigSchemaValidator(effortValidatorDriverFactory()),
    );

    expect($validator->validateUpdateSettings(
        ['settings' => ['effort_levels' => 'low, medium, high']],
        effortValidatorStoredConfig(),
    ))->toBeNull();
});
