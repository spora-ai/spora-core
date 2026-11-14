<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;
use Spora\Tools\ToolSettingSchema;

/**
 * The reasoning-effort settings on a driver.
 *
 * Two things the operator sees in the admin depend on how a configuration
 * that never stored them is presented:
 *
 * - The **Effort levels** field is a comma-separated list, so it belongs in
 *   a single-line input, not a textarea.
 * - The **Supports reasoning effort** toggle reads as "off" when the stored
 *   settings do not carry it, while the driver treats an absent key as
 *   enabled. A configuration saved before the toggle existed would show the
 *   opposite of what runs.
 */

function effortSettingsService(): LLMConfigService
{
    $security = new Spora\Core\SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

    return new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
}

it('offers the effort levels as a single-line field, because the list is comma-separated', function (): void {
    foreach ([OpenAICompatibleDriver::class, AnthropicCompatibleDriver::class] as $driverClass) {
        $setting = null;
        foreach (ToolSettingSchema::collect($driverClass) as $candidate) {
            if ($candidate->key === 'effort_levels') {
                $setting = $candidate;
            }
        }

        expect($setting)->not->toBeNull()
            ->and($setting->type)->toBe('text')
            ->and($setting->description)->toContain('Comma-separated');
    }
});

it('shows the effort toggle as on for a configuration that never stored it', function (): void {
    $service = effortSettingsService();
    $config = createTestConfig('Legacy Effort Config', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-legacy',
        'model' => 'gpt-4o',
    ], false, null, $service);

    $resource = $service->configResource($config);

    // The driver treats an absent key as enabled, so the form must not show
    // the toggle as off.
    expect($resource['settings'])->toHaveKey('supports_effort')
        ->and($resource['settings']['supports_effort'])->toBe('true');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

it('keeps the effort toggle as the operator left it', function (): void {
    $service = effortSettingsService();
    $config = createTestConfig('Disabled Effort Config', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-disabled',
        'model' => 'gpt-4o',
        'supports_effort' => 'false',
    ], false, null, $service);

    $resource = $service->configResource($config);

    expect($resource['settings']['supports_effort'])->toBe('false');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

it('leaves the image toggle unset, because an absent key there means the model decides', function (): void {
    $service = effortSettingsService();
    $config = createTestConfig('Legacy Image Config', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-legacy-image',
        'model' => 'gpt-4o',
    ], false, null, $service);

    $resource = $service->configResource($config);

    // The driver falls back to the model when this key is absent, so filling
    // in the schema's default would switch images off on the next save.
    expect($resource['settings'])->not->toHaveKey('supports_image_input');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});
