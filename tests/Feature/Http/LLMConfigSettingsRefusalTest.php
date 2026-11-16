<?php

declare(strict_types=1);

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;
use Spora\Services\LlmConfigValidator;

/**
 * The refusals an LLM configuration's settings carry, and the codes they
 * answer with.
 *
 * The effort refusal says which refusal it is: a level the configuration
 * does not offer means the client's cached capability for that agent is
 * out of date, while a malformed field has to be corrected. A client that
 * cannot tell the two apart retries the wrong thing.
 */
function makeSettingsValidator(): LlmConfigValidator
{
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    return new LlmConfigValidator($service);
}

/**
 * @param array<string, mixed> $settings
 * @return array{0: string, 1: string} the code and the message
 */
function settingsRefusal(array $settings): array
{
    $validator = makeSettingsValidator();

    $response = $validator->validateStoreBody([
        'name'         => 'Effort Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => $settings,
    ]);

    expect($response)->not->toBeNull();

    $body = json_decode((string) $response->getContent(), true);

    return [$body['error']['code'], $body['error']['message']];
}

it('refuses a level the configuration does not offer, with its own code', function (): void {
    [$code, $message] = settingsRefusal(['effort' => 'medium', 'effort_levels' => 'low, high']);

    expect($code)->toBe('EFFORT_LEVEL_UNSUPPORTED')
        ->and($message)->toContain('Supported levels: low, high');
});

it('refuses a malformed level list, with its own code', function (): void {
    [$code] = settingsRefusal(['effort_levels' => 'not a level']);

    expect($code)->toBe('INVALID_EFFORT');
});

it('refuses a malformed field with the generic code', function (): void {
    // The `temperature` field's own bound, which is a regex rather than
    // an effort rule.
    [$code] = settingsRefusal(['temperature' => '9']);

    expect($code)->toBe('VALIDATION_ERROR');
});

it('accepts a level the configuration offers', function (): void {
    $validator = makeSettingsValidator();

    expect($validator->validateStoreBody([
        'name'         => 'Effort Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => ['effort' => 'low', 'effort_levels' => 'low, high'],
    ]))->toBeNull();
});

it('refuses an unsupported level on an update too', function (): void {
    $validator = makeSettingsValidator();

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name'         => 'Effort Update Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode(['effort_levels' => 'low, high']),
        'is_global'    => true,
    ]);

    $response = $validator->validateUpdateSettings(
        ['settings' => ['effort' => 'medium']],
        $config,
    );

    expect($response)->not->toBeNull();

    $body = json_decode((string) $response->getContent(), true);

    expect($body['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED')
        ->and($body['error']['message'])->toContain('Supported levels: low, high');
});
