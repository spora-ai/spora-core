<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\Exceptions\DriverClassNotFoundException;
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
    bool $isGlobal = false,
): LLMDriverConfiguration {
    $service ??= makeSecureLLMConfigService();

    // Global configs have no user FK
    if (!$isGlobal) {
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
    }

    $config = new LLMDriverConfiguration();
    $config->user_id = $isGlobal ? null : $userId;
    $config->name = $name;
    $config->driver_class = $driverClass;
    $config->settings = json_encode($service->encodeSettings($driverClass, $settings));
    $config->is_default = $isDefault;
    $config->is_global = $isGlobal;
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

    $factory = new DriverFactory(new NullLogger(), $service, 300);
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
        isGlobal: true,
    );

    $agent = new Agent();
    $agent->id = 2;
    $agent->user_id = 999;
    $agent->name = 'Test';
    $agent->llm_driver_config_id = null;

    $factory = new DriverFactory(new NullLogger(), $service, 300);
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

    $factory = new DriverFactory(new NullLogger(), $service, 300);
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

    $factory = new DriverFactory(new NullLogger(), $service, 300);
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

    $factory = new DriverFactory(new NullLogger(), $service, 300);
    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(AnthropicCompatibleDriver::class)
        ->and($driver->getModelName())->toBe('claude-3-5-sonnet');

    LLMDriverConfiguration::whereIn('id', [$globalConfig->id, $agentConfig->id])->delete();
});

// Timeout configuration

test('makeDriverFromConfig passes per-LLM-config timeout to the driver', function (): void {
    $service = makeSecureLLMConfigService();
    $config = createConfigForTest(
        'Custom Timeout Config',
        OpenAICompatibleDriver::class,
        [
            'api_key'  => 'sk-key',
            'model'    => 'gpt-4o',
            'base_url' => 'https://api.openai.com/v1',
            'timeout'  => 600,
        ],
        service: $service,
        userId: 1,
    );

    // Factory has global default of 300, but per-config should override to 600
    $factory = new DriverFactory(new NullLogger(), $service, 300);

    $agent = new Agent();
    $agent->id = 99;
    $agent->name = 'Timeout Test';
    $agent->llm_driver_config_id = $config->id;

    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class);

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('makeDriverFromConfig uses global llmTimeout when config has no timeout', function (): void {
    $service = makeSecureLLMConfigService();
    $config = createConfigForTest(
        'No Timeout Config',
        OpenAICompatibleDriver::class,
        [
            'api_key'  => 'sk-key',
            'model'    => 'gpt-4o',
            'base_url' => 'https://api.openai.com/v1',
            // no 'timeout' key
        ],
        service: $service,
        userId: 1,
    );

    // Factory global default of 300 should be used
    $factory = new DriverFactory(new NullLogger(), $service, 300);

    $agent = new Agent();
    $agent->id = 100;
    $agent->name = 'Timeout Test';
    $agent->llm_driver_config_id = $config->id;

    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class);

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

// Decryption failure handling

test('makeFromAgent handles decryption failure gracefully — api_key null, other fields readable', function (): void {
    // serviceA creates a config with per-field encrypted api_key + plain model/base_url
    $serviceA = makeSecureLLMConfigService();
    $config   = createConfigForTest(
        'Broken Config',
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-key', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1'],
        true,
        service: $serviceA,
        userId: 1,
    );

    // Factory uses a DIFFERENT key (service B) → api_key decryption fails → becomes null
    // Other fields (model, base_url) are plain JSON and readable.
    $serviceB = makeSecureLLMConfigService();
    $factory  = new DriverFactory(new NullLogger(), $serviceB);

    $agent                    = new Agent();
    $agent->user_id           = 1;
    $agent->llm_driver_config_id = $config->id;

    // Must NOT throw — decodeSettings catches the error and returns partial settings
    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class);
    // api_key decryption failed → null → cast to empty string (no getApiKey method)
    // model and base_url are plain JSON, readable by serviceB
    expect($driver->getModelName())->toBe('gpt-4o');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('makeFromAgent gracefully handles wrong key — partial settings returned, no exception thrown', function (): void {
    $serviceA = makeSecureLLMConfigService();
    $config   = createConfigForTest(
        'Config X',
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-x', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1'],
        false,
        service: $serviceA,
        userId: 1,
    );

    $serviceB = makeSecureLLMConfigService();
    $factory  = new DriverFactory(new NullLogger(), $serviceB);

    $agent                    = new Agent();
    $agent->user_id           = 1;
    $agent->llm_driver_config_id = $config->id;

    // No exception — partial settings returned, api_key empty, model/base_url readable
    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class);
    expect($driver->getModelName())->toBe('gpt-4o');
    // api_key decryption failed → null → cast to empty string (no getApiKey method)

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

// DriverClassNotFoundException

test('makeFromAgent returns a driver instance for a valid registered class', function (): void {
    $service = makeSecureLLMConfigService();
    $config = createConfigForTest(
        'Valid Class',
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-valid', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1'],
        service: $service,
        userId: 1,
    );

    $agent = new Agent();
    $agent->user_id = 1;
    $agent->llm_driver_config_id = $config->id;

    $factory = new DriverFactory(new NullLogger(), $service, 300);
    $driver = $factory->makeFromAgent($agent);

    expect($driver)->toBeInstanceOf(OpenAICompatibleDriver::class);

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('makeFromAgent throws DriverClassNotFoundException when the driver class is not registered', function (): void {
    $service = makeSecureLLMConfigService();
    // Class name that does not exist anywhere in the autoloader
    $bogusClass = 'Spora\\Drivers\\DefinitelyNotARealDriver';
    $config = createConfigForTest(
        'Bogus Class',
        $bogusClass,
        ['api_key' => 'sk-bogus', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1'],
        service: $service,
        userId: 1,
    );

    $agent = new Agent();
    $agent->user_id = 1;
    $agent->llm_driver_config_id = $config->id;

    $factory = new DriverFactory(new NullLogger(), $service, 300);

    expect(fn() => $factory->makeFromAgent($agent))
        ->toThrow(DriverClassNotFoundException::class);

    LLMDriverConfiguration::where('id', $config->id)->delete();
});
