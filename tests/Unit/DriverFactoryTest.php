<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;

function makeSecureLLMConfigService(): Spora\Services\LLMConfigService
{
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);

    return new Spora\Services\LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
}

function createConfigForTest(
    string $name,
    string $driverClass,
    array $settings,
    bool $isDefault = false,
    ?Spora\Services\LLMConfigService $service = null,
    int $userId = 1,
): LLMDriverConfiguration {
    $service ??= makeSecureLLMConfigService();

    // Ensure the user exists (FK constraint on user_id). Use SELECT + INSERT to avoid
    // the deferred-FK behavior of INSERT OR IGNORE in SQLite transactions.
    $userExists = Capsule::table('users')->where('id', $userId)->exists();
    if (!$userExists) {
        Capsule::table('users')->insert([
            'id'         => $userId,
            'email'      => "user{$userId}@test.local",
            'password'   => password_hash('Password1!', PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $config = new LLMDriverConfiguration();
    $config->user_id = $userId;
    $config->name = $name;
    $config->driver_class = $driverClass;
    $config->settings = $service->encryptSettings($settings);
    $config->is_default = $isDefault;
    $config->save();

    return $config;
}

test('makeFromAgent uses agent-specific llm_driver_config_id', function (): void {
    $service = makeSecureLLMConfigService();
    $config = createConfigForTest(
        'Agent Config',
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-agent-key', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1'],
        service: $service,
        userId: 1,
    );

    $agent = new Agent();
    $agent->id = 1;
    $agent->name = 'Test';
    $agent->llm_driver_config_id = $config->id;

    $factory = new DriverFactory(new NullLogger(), $service);
    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class)
        ->and($driver->getProviderName())->toBe('openai_compatible')
        ->and($driver->getModelName())->toBe('gpt-4o');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('makeFromAgent falls back to global default when agent has no config', function (): void {
    $service = makeSecureLLMConfigService();
    $config = createConfigForTest(
        'Global Default',
        AnthropicCompatibleDriver::class,
        ['api_key' => 'sk-global-key', 'model' => 'claude-3-5-sonnet', 'base_url' => 'https://api.anthropic.com/v1/messages'],
        isDefault: true,
        service: $service,
        userId: 999,
    );

    $agent = new Agent();
    $agent->id = 2;
    $agent->user_id = 999;
    $agent->name = 'Test';
    $agent->llm_driver_config_id = null;

    $factory = new DriverFactory(new NullLogger(), $service);
    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(AnthropicCompatibleDriver::class)
        ->and($driver->getProviderName())->toBe('anthropic_compatible')
        ->and($driver->getModelName())->toBe('claude-3-5-sonnet');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('makeFromAgent falls back to OpenAI driver when no config exists', function (): void {
    $service = makeSecureLLMConfigService();

    $agent = new Agent();
    $agent->id = 3;
    $agent->name = 'Test';
    $agent->llm_driver_config_id = null;

    $factory = new DriverFactory(new NullLogger(), $service);
    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class)
        ->and($driver->getProviderName())->toBe('openai_compatible')
        ->and($driver->getModelName())->toBe('gpt-4o');
});

test('makeFromAgent returns Anthropic driver when that config is set on agent', function (): void {
    $service = makeSecureLLMConfigService();
    $config = createConfigForTest(
        'Anthropic Agent Config',
        AnthropicCompatibleDriver::class,
        ['api_key' => 'sk-ant-agent', 'model' => 'claude-3-5-sonnet-20241022', 'base_url' => 'https://api.anthropic.com/v1/messages'],
        service: $service,
    );

    $agent = new Agent();
    $agent->id = 4;
    $agent->name = 'Test';
    $agent->llm_driver_config_id = $config->id;

    $factory = new DriverFactory(new NullLogger(), $service);
    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(AnthropicCompatibleDriver::class)
        ->and($driver->getProviderName())->toBe('anthropic_compatible');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('makeFromAgent uses agent config over global default', function (): void {
    $service = makeSecureLLMConfigService();

    $globalConfig = createConfigForTest(
        'Global Default',
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-global', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1'],
        isDefault: true,
        service: $service,
    );

    $agentConfig = createConfigForTest(
        'Agent Specific',
        AnthropicCompatibleDriver::class,
        ['api_key' => 'sk-agent-ant', 'model' => 'claude-3-5-sonnet', 'base_url' => 'https://api.anthropic.com/v1/messages'],
        service: $service,
    );

    $agent = new Agent();
    $agent->id = 5;
    $agent->name = 'Test';
    $agent->llm_driver_config_id = $agentConfig->id;

    $factory = new DriverFactory(new NullLogger(), $service);
    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(AnthropicCompatibleDriver::class)
        ->and($driver->getModelName())->toBe('claude-3-5-sonnet');

    LLMDriverConfiguration::whereIn('id', [$globalConfig->id, $agentConfig->id])->delete();
});
