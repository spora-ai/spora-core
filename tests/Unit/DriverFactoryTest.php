<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMConfiguration;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Services\ToolConfigService;

test('it creates AnthropicDriver when agent uses anthropic provider', function () {
    $toolConfigService = Mockery::mock(ToolConfigService::class);
    $toolConfigService->shouldReceive('getEffectiveSettings')
        ->with(LLMConfiguration::class, 1)
        ->once()
        ->andReturn(['anthropic_api_key' => 'test-anthropic-key']);

    $factory = new DriverFactory($toolConfigService, new \Psr\Log\NullLogger());

    $agent = new Agent();
    $agent->id = 1;
    $agent->llm_provider = 'anthropic';
    $agent->llm_model = 'claude-3-5-sonnet-20241022';

    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(AnthropicCompatibleDriver::class)
        ->and($driver->getProviderName())->toBe('anthropic')
        ->and($driver->getModelName())->toBe('claude-3-5-sonnet-20241022');
});

test('it creates OpenAICompatibleDriver when agent uses openai_compatible provider', function () {
    $toolConfigService = Mockery::mock(ToolConfigService::class);
    $toolConfigService->shouldReceive('getEffectiveSettings')
        ->with(LLMConfiguration::class, 2)
        ->once()
        ->andReturn(['openai_api_key' => 'test-openai-key']);

    $factory = new DriverFactory($toolConfigService, new \Psr\Log\NullLogger());

    $agent = new Agent();
    $agent->id = 2;
    $agent->llm_provider = 'openai_compatible';
    $agent->llm_model = 'gpt-4o';
    $agent->llm_base_url = 'https://api.openai.com/v1';

    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class)
        ->and($driver->getProviderName())->toBe('openai_compatible')
        ->and($driver->getModelName())->toBe('gpt-4o');
});

test('it defaults to openai_compatible when provider is null', function () {
    $toolConfigService = Mockery::mock(ToolConfigService::class);
    $toolConfigService->shouldReceive('getEffectiveSettings')
        ->with(LLMConfiguration::class, 3)
        ->once()
        ->andReturn([]);

    $factory = new DriverFactory($toolConfigService, new \Psr\Log\NullLogger());

    $agent = new Agent();
    $agent->id = 3;
    $agent->llm_provider = null;

    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class);
});
