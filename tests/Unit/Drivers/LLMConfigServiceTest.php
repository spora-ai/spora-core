<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\SecurityManagerInterface;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;

test('getDrivers returns all registered drivers', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);

    $drivers = $service->getDrivers();

    expect($drivers)->toBeArray()
        ->and(count($drivers))->toBe(2);

    $names = array_column($drivers, 'name');
    expect($names)->toContain('openai_compatible')
        ->and($names)->toContain('anthropic_compatible');
});

test('getDrivers returns empty array when no drivers registered', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, []);

    expect($service->getDrivers())->toBeEmpty();
});

test('getDrivers excludes non-existent classes', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    /** @var list<class-string<Spora\Drivers\LLMDriverConfigInterface>> $driverClasses */
    $driverClasses = [
        OpenAICompatibleDriver::class,
        'Spora\Drivers\NonExistentDriver',
    ];
    $service = new LLMConfigService($security, $driverClasses);

    $drivers = $service->getDrivers();

    expect(count($drivers))->toBe(1)
        ->and($drivers[0]['name'])->toBe('openai_compatible');
});

test('getDrivers returns correct display names', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);

    $drivers = $service->getDrivers();

    $byName = [];
    foreach ($drivers as $d) {
        $byName[$d['name']] = $d;
    }

    expect($byName['openai_compatible']['display_name'])->toBe('OpenAI Compatible')
        ->and($byName['anthropic_compatible']['display_name'])->toBe('Anthropic Compatible');
});

test('getDrivers returns settings_schema for each driver', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
    ]);

    $drivers = $service->getDrivers();

    expect($drivers[0]['settings_schema'])->toBeArray()
        ->and($drivers[0]['settings_schema'])->not->toBeEmpty();

    $keys = array_column($drivers[0]['settings_schema'], 'key');
    expect($keys)->toContain('api_key')
        ->and($keys)->toContain('base_url')
        ->and($keys)->toContain('model');
});

test('encodeSettings encrypts only password fields and stores others as plain strings', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);

    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $result = $service->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-test-key',
        'base_url' => 'https://api.example.com',
        'model' => 'gpt-4o',
    ]);

    // api_key should be encrypted (base64 string, not plain)
    expect(is_string($result['api_key']))->toBe(true);
    expect($result['api_key'])->not->toBe('sk-test-key');
    // base_url and model should be plain strings
    expect($result['base_url'])->toBe('https://api.example.com');
    expect($result['model'])->toBe('gpt-4o');
});

test('encodeSettings handles empty password field', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $result = $service->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => '',
        'base_url' => 'https://api.example.com',
    ]);

    // Empty password should not be encrypted
    expect($result['api_key'])->toBe('');
    expect($result['base_url'])->toBe('https://api.example.com');
});

test('decodeSettings decrypts per-field format correctly', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $encoded = $service->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-secret-key',
        'base_url' => 'https://api.example.com',
    ]);

    $json = json_encode($encoded);
    $decoded = $service->decodeSettings(OpenAICompatibleDriver::class, $json);

    expect($decoded['api_key'])->toBe('sk-secret-key');
    expect($decoded['base_url'])->toBe('https://api.example.com');
});

test('decodeSettings handles legacy wholesale-encrypted format', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    // Simulate legacy wholesale encryption
    $legacyStorage = $security->encrypt(json_encode(['api_key' => 'sk-legacy']));
    $legacyString = $legacyStorage->toStorageString();

    $decoded = $service->decodeSettings(OpenAICompatibleDriver::class, $legacyString);

    expect($decoded['api_key'])->toBe('sk-legacy');
});

test('decodeSettings returns empty array for null/empty input', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $service = new LLMConfigService($security, []);

    expect($service->decodeSettings(OpenAICompatibleDriver::class, null))->toBe([]);
    expect($service->decodeSettings(OpenAICompatibleDriver::class, ''))->toBe([]);
});

test('encodeSettings + decodeSettings is a lossless round-trip', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $original = [
        'api_key' => 'sk-round-trip-test',
        'base_url' => 'https://example.com/v1',
        'model' => 'gpt-4o-mini',
        'max_tokens' => 2048,
    ];

    $encoded = $service->encodeSettings(OpenAICompatibleDriver::class, $original);
    $json = json_encode($encoded);
    $decoded = $service->decodeSettings(OpenAICompatibleDriver::class, $json);

    expect($decoded)->toEqual($original);
});

test('maskForApi replaces password fields with ***', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, []);

    $settings = [
        'api_key' => 'sk-secret',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'gpt-4o',
    ];

    $schema = [
        ['key' => 'api_key', 'type' => 'password'],
        ['key' => 'base_url', 'type' => 'text'],
        ['key' => 'model', 'type' => 'text'],
    ];

    $masked = $service->maskForApi($settings, $schema);

    expect($masked['api_key'])->toBe('***')
        ->and($masked['base_url'])->toBe('https://api.openai.com/v1')
        ->and($masked['model'])->toBe('gpt-4o');
});

test('maskForApi leaves empty password fields unchanged', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, []);

    $settings = [
        'api_key' => '',
    ];

    $schema = [
        ['key' => 'api_key', 'type' => 'password'],
    ];

    $masked = $service->maskForApi($settings, $schema);

    expect($masked['api_key'])->toBe('');
});

// ── getEffectiveConfigForAgent ─────────────────────────────────────────────────

test('getEffectiveConfigForAgent returns tier-1 agent-specific config', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $userId = Capsule::table('users')->insertGetId([
        'email'    => 'agent-test@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $agentConfig = new LLMDriverConfiguration();
    $agentConfig->user_id = $userId;
    $agentConfig->name = 'Agent Config';
    $agentConfig->driver_class = OpenAICompatibleDriver::class;
    $agentConfig->settings = json_encode([]);
    $agentConfig->is_default = false;
    $agentConfig->save();

    $userDefault = new LLMDriverConfiguration();
    $userDefault->user_id = $userId;
    $userDefault->name = 'User Default';
    $userDefault->driver_class = OpenAICompatibleDriver::class;
    $userDefault->settings = json_encode([]);
    $userDefault->is_default = true;
    $userDefault->save();

    $agent = new Agent();
    $agent->id = 999;
    $agent->user_id = $userId;
    $agent->llm_driver_config_id = $agentConfig->id;

    $result = $service->getEffectiveConfigForAgent($agent);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($agentConfig->id)
        ->and($result->name)->toBe('Agent Config');
});

test('getEffectiveConfigForAgent falls back to tier-2 user default when no agent config', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $userId = Capsule::table('users')->insertGetId([
        'email'    => 'user-default-test@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $userDefault = new LLMDriverConfiguration();
    $userDefault->user_id = $userId;
    $userDefault->name = 'User Default';
    $userDefault->driver_class = OpenAICompatibleDriver::class;
    $userDefault->settings = json_encode([]);
    $userDefault->is_default = true;
    $userDefault->save();

    $agent = new Agent();
    $agent->id = 998;
    $agent->user_id = $userId;
    $agent->llm_driver_config_id = null;

    $result = $service->getEffectiveConfigForAgent($agent);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($userDefault->id)
        ->and($result->name)->toBe('User Default');
});

test('getEffectiveConfigForAgent falls back to tier-3 global default when no user default', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $userId = Capsule::table('users')->insertGetId([
        'email'    => 'global-default-test@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $globalDefault = new LLMDriverConfiguration();
    $globalDefault->user_id = null;
    $globalDefault->name = 'Global Default';
    $globalDefault->driver_class = OpenAICompatibleDriver::class;
    $globalDefault->settings = json_encode([]);
    $globalDefault->is_global = true;
    $globalDefault->is_default = true;
    $globalDefault->save();

    $agent = new Agent();
    $agent->id = 997;
    $agent->user_id = $userId;
    $agent->llm_driver_config_id = null;

    $result = $service->getEffectiveConfigForAgent($agent);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($globalDefault->id)
        ->and($result->name)->toBe('Global Default');
});

test('getEffectiveConfigForAgent returns null when no config at any tier', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $userId = Capsule::table('users')->insertGetId([
        'email'    => 'no-config-test@example.com',
        'password' => password_hash('Password1!', PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $agent = new Agent();
    $agent->id = 996;
    $agent->user_id = $userId;
    $agent->llm_driver_config_id = null;

    $result = $service->getEffectiveConfigForAgent($agent);

    expect($result)->toBeNull();
});
