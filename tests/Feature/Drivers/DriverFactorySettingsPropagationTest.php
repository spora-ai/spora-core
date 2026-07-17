<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use Mockery;
use Psr\Log\NullLogger;
use Spora\Core\SecurityManagerInterface;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;

defined('TEST_PASSWORD') || define('TEST_PASSWORD', 'Password1!');

function bootDriverFactoryTestEnv(): void
{
    // LLMConfigService is constructed by the DI container in production;
    // tests instantiate it directly with the same constructor signature.
}

function makeDriverFactory(?LLMConfigService $svc = null): DriverFactory
{
    $security = Mockery::mock(SecurityManagerInterface::class);
    $security->shouldReceive('looksEncrypted')->andReturn(false)->byDefault();
    $security->shouldReceive('decrypt')->andReturn('') ->byDefault();
    return new DriverFactory(
        logger: new NullLogger(),
        llmConfigService: $svc ?? new LLMConfigService($security, [
            OpenAICompatibleDriver::class,
            AnthropicCompatibleDriver::class,
        ]),
        llmTimeout: 60,
    );
}

test('OpenAI driver gets supports_image_input=true from "true" string setting', function (): void {
    $config = LLMDriverConfiguration::create([
        'user_id'      => null,
        'name'         => 'Vision-enabled',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'             => 'test',
            'model'               => 'gpt-3.5-turbo',
            'base_url'            => 'https://api.openai.com/v1',
            'supports_image_input' => 'true',
        ]),
        'is_global'  => true,
        'is_default' => true,
    ]);
    $factory = makeDriverFactory();
    $driver = $factory->makeDriverFromConfig($config);
    // Even though gpt-3.5-turbo is non-vision, the operator's choice wins.
    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class);
    expect($driver->supportsImageInput())->toBeTrue();
});

test('OpenAI driver gets supports_image_input=false from "false" string setting', function (): void {
    $config = LLMDriverConfiguration::create([
        'user_id'      => null,
        'name'         => 'Text-only',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'              => 'test',
            'model'                => 'gpt-4o',
            'base_url'             => 'https://api.openai.com/v1',
            'supports_image_input' => 'false',
        ]),
        'is_global'  => true,
        'is_default' => true,
    ]);
    $factory = makeDriverFactory();
    $driver = $factory->makeDriverFromConfig($config);
    // Even though gpt-4o is vision-capable, the operator's choice wins.
    expect($driver->supportsImageInput())->toBeFalse();
});

test('OpenAI driver falls back to model heuristic when setting key is absent', function (): void {
    $config = LLMDriverConfiguration::create([
        'user_id'      => null,
        'name'         => 'Legacy row',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'  => 'test',
            'model'    => 'gpt-4o',
            'base_url' => 'https://api.openai.com/v1',
        ]),
        'is_global'  => true,
        'is_default' => true,
    ]);
    $factory = makeDriverFactory();
    $driver = $factory->makeDriverFromConfig($config);
    expect($driver->supportsImageInput())->toBeTrue();
});

test('Anthropic driver propagates toggle', function (): void {
    $config = LLMDriverConfiguration::create([
        'user_id'      => null,
        'name'         => 'Anthropic with toggle',
        'driver_class' => AnthropicCompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'              => 'test',
            'model'                => 'claude-2.0',
            'base_url'             => 'https://api.anthropic.com',
            'supports_image_input' => 'true',
        ]),
        'is_global'  => true,
        'is_default' => true,
    ]);
    $factory = makeDriverFactory();
    $driver = $factory->makeDriverFromConfig($config);
    expect($driver)->toBeInstanceOf(AnthropicCompatibleDriver::class);
    expect($driver->supportsImageInput())->toBeTrue();
});

test('Anthropic driver falls back to model heuristic when toggle is absent', function (): void {
    $config = LLMDriverConfiguration::create([
        'user_id'      => null,
        'name'         => 'Anthropic legacy',
        'driver_class' => AnthropicCompatibleDriver::class,
        'settings'     => json_encode([
            'api_key'  => 'test',
            'model'    => 'claude-2.0',
            'base_url' => 'https://api.anthropic.com',
        ]),
        'is_global'  => true,
        'is_default' => true,
    ]);
    $factory = makeDriverFactory();
    $driver = $factory->makeDriverFromConfig($config);
    expect($driver->supportsImageInput())->toBeFalse();
});
