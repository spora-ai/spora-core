<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery;
use ReflectionObject;
use Spora\Core\SecurityManager;
use Spora\Core\SecurityManagerInterface;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\LLMDriverConfigInterface;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\PrincipalPreference;
use Spora\Services\LLMConfigPersistence;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigSchemaInspector;
use Spora\Services\LLMConfigService;
use Spora\Services\PrincipalResolver;

const TEST_API_BASE_URL = 'https://api.example.com';
const TEST_USER_PASSWORD = 'Password1!';
defined('TEST_TIMESTAMP_FORMAT') || define('TEST_TIMESTAMP_FORMAT', 'Y-m-d H:i:s');
const TEST_USER_DEFAULT_NAME = 'User Default';

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
    /** @var list<class-string<LLMDriverConfigInterface>> $driverClasses */
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
    $security = new SecurityManager($key);
    $persistence = new LLMConfigPersistence($security, new LLMConfigSchemaInspector(), new PrincipalResolver());

    $result = $persistence->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-test-key',
        'base_url' => TEST_API_BASE_URL,
        'model' => 'gpt-4o',
    ]);

    // api_key should be encrypted (base64 string, not plain)
    expect(is_string($result['api_key']))->toBe(true);
    expect($result['api_key'])->not->toBe('sk-test-key');
    // base_url and model should be plain strings
    expect($result['base_url'])->toBe(TEST_API_BASE_URL);
    expect($result['model'])->toBe('gpt-4o');
});

test('encodeSettings handles empty password field', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $persistence = new LLMConfigPersistence($security, new LLMConfigSchemaInspector(), new PrincipalResolver());

    $result = $persistence->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => '',
        'base_url' => TEST_API_BASE_URL,
    ]);

    // Empty password should not be encrypted
    expect($result['api_key'])->toBe('');
    expect($result['base_url'])->toBe(TEST_API_BASE_URL);
});

test('decodeSettings decrypts per-field format correctly', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $persistence = new LLMConfigPersistence($security, new LLMConfigSchemaInspector(), new PrincipalResolver());
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $encoded = $persistence->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-secret-key',
        'base_url' => TEST_API_BASE_URL,
    ]);

    $json = json_encode($encoded);
    $decoded = $service->decodeSettings(OpenAICompatibleDriver::class, $json);

    expect($decoded['api_key'])->toBe('sk-secret-key');
    expect($decoded['base_url'])->toBe(TEST_API_BASE_URL);
});

test('decodeSettings handles legacy wholesale-encrypted format', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    // Simulate legacy wholesale encryption
    $legacyStorage = $security->encrypt(json_encode(['api_key' => 'sk-legacy']));
    $legacyString = $legacyStorage->toStorageString();

    $decoded = $service->decodeSettings(OpenAICompatibleDriver::class, $legacyString);

    expect($decoded['api_key'])->toBe('sk-legacy');
});

test('decodeSettings returns empty array for null/empty input', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $service = new LLMConfigService($security, []);

    expect($service->decodeSettings(OpenAICompatibleDriver::class, null))->toBe([]);
    expect($service->decodeSettings(OpenAICompatibleDriver::class, ''))->toBe([]);
});

test('encodeSettings + decodeSettings is a lossless round-trip', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $persistence = new LLMConfigPersistence($security, new LLMConfigSchemaInspector(), new PrincipalResolver());
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

    $original = [
        'api_key' => 'sk-round-trip-test',
        'base_url' => 'https://example.com/v1',
        'model' => 'gpt-4o-mini',
        'timeout' => '120',
    ];

    $encoded = $persistence->encodeSettings(OpenAICompatibleDriver::class, $original);
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

// getEffectiveConfigForAgent

test('getEffectiveConfigForAgent returns tier-1 agent-specific config', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
    $preferences = new LLMConfigPreferences();

    $userId = Capsule::table('users')->insertGetId([
        'email'    => 'agent-test@example.com',
        'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date(TEST_TIMESTAMP_FORMAT),
        'updated_at' => date(TEST_TIMESTAMP_FORMAT),
    ]);

    $agentConfig = new LLMDriverConfiguration();
    $agentConfig->principal_id = createUserPrincipalPublic($userId);
    $agentConfig->name = 'Agent Config';
    $agentConfig->driver_class = OpenAICompatibleDriver::class;
    $agentConfig->settings = json_encode([]);
    $agentConfig->is_default = false;
    $agentConfig->save();

    $userDefault = new LLMDriverConfiguration();
    $userDefault->principal_id = createUserPrincipalPublic($userId);
    $userDefault->name = TEST_USER_DEFAULT_NAME;
    $userDefault->driver_class = OpenAICompatibleDriver::class;
    $userDefault->settings = json_encode([]);
    $userDefault->is_default = true;
    $userDefault->save();

    $agent = new Agent();
    $agent->id = 999;
    $agent->principal_id = createUserPrincipalPublic($userId);
    $agent->llm_driver_config_id = $agentConfig->id;

    $result = $preferences->getEffectiveConfigForAgent($agent);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($agentConfig->id)
        ->and($result->name)->toBe('Agent Config');
});

test('getEffectiveConfigForAgent falls back to tier-2 user default when no agent config', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
    $preferences = new LLMConfigPreferences();

    $userId = Capsule::table('users')->insertGetId([
        'email'    => 'user-default-test@example.com',
        'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date(TEST_TIMESTAMP_FORMAT),
        'updated_at' => date(TEST_TIMESTAMP_FORMAT),
    ]);

    $userDefault = new LLMDriverConfiguration();
    $userDefault->principal_id = createUserPrincipalPublic($userId);
    $userDefault->name = TEST_USER_DEFAULT_NAME;
    $userDefault->driver_class = OpenAICompatibleDriver::class;
    $userDefault->settings = json_encode([]);
    $userDefault->save();

    PrincipalPreference::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'preferred_llm_config_id' => $userDefault->id,
    ]);

    $agent = new Agent();
    $agent->id = 998;
    $agent->principal_id = createUserPrincipalPublic($userId);
    $agent->llm_driver_config_id = null;

    $result = $preferences->getEffectiveConfigForAgent($agent);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($userDefault->id)
        ->and($result->name)->toBe(TEST_USER_DEFAULT_NAME);
});

test('getEffectiveConfigForAgent falls back to tier-3 global default when no user default', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
    $preferences = new LLMConfigPreferences();

    $userId = Capsule::table('users')->insertGetId([
        'email'    => 'global-default-test@example.com',
        'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date(TEST_TIMESTAMP_FORMAT),
        'updated_at' => date(TEST_TIMESTAMP_FORMAT),
    ]);

    $globalDefault = new LLMDriverConfiguration();
    $globalDefault->principal_id = null;
    $globalDefault->name = 'Global Default';
    $globalDefault->driver_class = OpenAICompatibleDriver::class;
    $globalDefault->settings = json_encode([]);
    $globalDefault->is_global = true;
    $globalDefault->is_default = true;
    $globalDefault->save();

    $agent = new Agent();
    $agent->id = 997;
    $agent->principal_id = createUserPrincipalPublic($userId);
    $agent->llm_driver_config_id = null;

    $result = $preferences->getEffectiveConfigForAgent($agent);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($globalDefault->id)
        ->and($result->name)->toBe('Global Default');
});

test('getEffectiveConfigForAgent returns null when no config at any tier', function (): void {
    $security = Mockery::mock(SecurityManagerInterface::class);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
    $preferences = new LLMConfigPreferences();

    $userId = Capsule::table('users')->insertGetId([
        'email'    => 'no-config-test@example.com',
        'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
        'registered' => time(),
        'created_at' => date(TEST_TIMESTAMP_FORMAT),
        'updated_at' => date(TEST_TIMESTAMP_FORMAT),
    ]);

    $agent = new Agent();
    $agent->id = 996;
    $agent->principal_id = createUserPrincipalPublic($userId);
    $agent->llm_driver_config_id = null;

    $result = $preferences->getEffectiveConfigForAgent($agent);

    expect($result)->toBeNull();
});

describe('LLMConfigService::validateNewConfigurationInputs', function (): void {

    beforeEach(function (): void {
        if (!Capsule::table('users')->where('id', 1)->exists()) {
            Capsule::table('users')->insert([
                'id'         => 1,
                'email'      => 'validate-cfg-1@example.com',
                'password'   => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
                'registered' => time(),
                'created_at' => date(TEST_TIMESTAMP_FORMAT),
                'updated_at' => date(TEST_TIMESTAMP_FORMAT),
            ]);
        }
    });

    test('returns null when name is empty', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $result = $service->createConfiguration(
            1,
            ['name' => '', 'driver_class' => OpenAICompatibleDriver::class, 'settings' => []],
            false,
        );

        expect($result)->toBeNull();
    });

    test('returns null when name is whitespace only', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $result = $service->createConfiguration(
            1,
            ['name' => '   ', 'driver_class' => OpenAICompatibleDriver::class, 'settings' => []],
            false,
        );

        expect($result)->toBeNull();
    });

    test('returns null when driver_class does not exist', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $result = $service->createConfiguration(
            1,
            ['name' => 'Bad Driver', 'driver_class' => 'Spora\\Drivers\\NonExistentDriver', 'settings' => []],
            false,
        );

        expect($result)->toBeNull();
    });

    test('returns null when non-admin tries to create a global config', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $result = $service->createConfiguration(
            1,
            [
                'name' => 'Global Attempt',
                'driver_class' => OpenAICompatibleDriver::class,
                'settings' => [],
                'is_global' => true,
            ],
            false,
        );

        expect($result)->toBeNull();
    });

    test('persists a configuration when all inputs are valid', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $userId = Capsule::table('users')->insertGetId([
            'email'    => 'validate-ok@example.com',
            'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date(TEST_TIMESTAMP_FORMAT),
            'updated_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);

        $result = $service->createConfiguration(
            $userId,
            [
                'name' => '  Trimmed Name  ',
                'driver_class' => OpenAICompatibleDriver::class,
                'settings' => ['model' => 'gpt-4o'],
            ],
            false,
        );

        expect($result)->not->toBeNull()
            ->and((int) $result->getKey())->toBeGreaterThan(0)
            ->and($result->name)->toBe('Trimmed Name')
            ->and($result->driver_class)->toBe(OpenAICompatibleDriver::class)
            ->and($result->is_global)->toBeFalse();
    });
});

describe('LLMConfigService::applyConfigurationUpdates', function (): void {

    test('updates an existing configuration settings and preserves others', function (): void {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $security = new SecurityManager($key);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
        $persistence = new LLMConfigPersistence($security, new LLMConfigSchemaInspector(), new PrincipalResolver());

        $userId = Capsule::table('users')->insertGetId([
            'email'    => 'apply-updates-settings@example.com',
            'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date(TEST_TIMESTAMP_FORMAT),
            'updated_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);

        $config = new LLMDriverConfiguration();
        $config->principal_id = createUserPrincipalPublic($userId);
        $config->name = 'Original Name';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode($persistence->encodeSettings(OpenAICompatibleDriver::class, [
            'api_key' => 'sk-original',
            'model' => 'gpt-4o',
        ]));
        $config->save();

        $result = $service->updateConfiguration(
            (int) $config->getKey(),
            $userId,
            ['settings' => ['model' => 'gpt-4-turbo']],
            false,
        );

        expect($result)->not->toBeNull();

        $reloaded = LLMDriverConfiguration::find((int) $config->getKey());
        $decoded = $service->decodeSettings($reloaded->driver_class, $reloaded->getRawOriginal('settings'));
        expect($decoded['model'])->toBe('gpt-4-turbo')
            ->and($decoded['api_key'])->toBe('sk-original');
    });

    test('updates the name of an existing configuration', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $userId = Capsule::table('users')->insertGetId([
            'email'    => 'apply-updates-name@example.com',
            'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date(TEST_TIMESTAMP_FORMAT),
            'updated_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);

        $config = new LLMDriverConfiguration();
        $config->principal_id = createUserPrincipalPublic($userId);
        $config->name = 'Original';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode([]);
        $config->save();

        $result = $service->updateConfiguration(
            (int) $config->getKey(),
            $userId,
            ['name' => 'Renamed'],
            false,
        );

        expect($result)->not->toBeNull();
        expect(LLMDriverConfiguration::find((int) $config->getKey())->name)->toBe('Renamed');
    });

    test('an empty update payload does not change existing fields', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $userId = Capsule::table('users')->insertGetId([
            'email'    => 'apply-updates-empty@example.com',
            'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date(TEST_TIMESTAMP_FORMAT),
            'updated_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);

        $config = new LLMDriverConfiguration();
        $config->principal_id = createUserPrincipalPublic($userId);
        $config->name = 'Untouched';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode([]);
        $config->save();
        $originalName = $config->name;
        $originalSettings = $config->getRawOriginal('settings');

        $result = $service->updateConfiguration(
            (int) $config->getKey(),
            $userId,
            [],
            false,
        );

        expect($result)->not->toBeNull();
        $reloaded = LLMDriverConfiguration::find((int) $config->getKey());
        expect($reloaded->name)->toBe($originalName)
            ->and($reloaded->getRawOriginal('settings'))->toBe($originalSettings);
    });
});

describe('LLMConfigService::loadDefaultableConfiguration', function (): void {

    test('returns null for a non-global configuration', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $userId = Capsule::table('users')->insertGetId([
            'email'    => 'default-personal@example.com',
            'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date(TEST_TIMESTAMP_FORMAT),
            'updated_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);

        $config = new LLMDriverConfiguration();
        $config->principal_id = createUserPrincipalPublic($userId);
        $config->name = 'Personal';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode([]);
        $config->is_global = false;
        $config->save();

        $result = $service->setDefaultConfiguration((int) $config->getKey(), $userId, true);

        expect($result)->toBeNull();
    });

    test('returns null when caller is not admin', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $config = new LLMDriverConfiguration();
        $config->principal_id = null;
        $config->name = 'Global';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode([]);
        $config->is_global = true;
        $config->save();

        $result = $service->setDefaultConfiguration((int) $config->getKey(), 999, false);

        expect($result)->toBeNull();
    });

    test('marks a global configuration as default when caller is admin', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        $config = new LLMDriverConfiguration();
        $config->principal_id = null;
        $config->name = 'Global Default';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode([]);
        $config->is_global = true;
        $config->save();

        $result = $service->setDefaultConfiguration((int) $config->getKey(), 1, true);

        expect($result)->not->toBeNull()
            ->and($result->is_default)->toBeTrue()
            ->and((int) $result->getKey())->toBe((int) $config->getKey());
    });
});

describe('LLMConfigService::getEffectiveConfigForAgent', function (): void {

    test('agent-specific config wins over user preference and global default', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
        $preferences = new LLMConfigPreferences();

        $userId = Capsule::table('users')->insertGetId([
            'email'    => 'eff-tier1-wins@example.com',
            'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date(TEST_TIMESTAMP_FORMAT),
            'updated_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);

        $agentConfig = new LLMDriverConfiguration();
        $agentConfig->principal_id = createUserPrincipalPublic($userId);
        $agentConfig->name = 'Agent Specific';
        $agentConfig->driver_class = OpenAICompatibleDriver::class;
        $agentConfig->settings = json_encode([]);
        $agentConfig->save();

        $preferred = new LLMDriverConfiguration();
        $preferred->principal_id = createUserPrincipalPublic($userId);
        $preferred->name = 'User Preferred';
        $preferred->driver_class = OpenAICompatibleDriver::class;
        $preferred->settings = json_encode([]);
        $preferred->save();
        PrincipalPreference::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'preferred_llm_config_id' => (int) $preferred->getKey(),
        ]);

        $globalDefault = new LLMDriverConfiguration();
        $globalDefault->principal_id = null;
        $globalDefault->name = 'Global Default';
        $globalDefault->driver_class = OpenAICompatibleDriver::class;
        $globalDefault->settings = json_encode([]);
        $globalDefault->is_global = true;
        $globalDefault->is_default = true;
        $globalDefault->save();

        $agent = new Agent();
        $agent->id = 901;
        $agent->principal_id = createUserPrincipalPublic($userId);
        $agent->llm_driver_config_id = (int) $agentConfig->getKey();

        $result = $preferences->getEffectiveConfigForAgent($agent);

        expect($result)->not->toBeNull()
            ->and((int) $result->getKey())->toBe((int) $agentConfig->getKey())
            ->and($result->name)->toBe('Agent Specific');
    });

    test('user preference wins over global default when no agent config', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
        $preferences = new LLMConfigPreferences();

        $userId = Capsule::table('users')->insertGetId([
            'email'    => 'eff-tier2-wins@example.com',
            'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date(TEST_TIMESTAMP_FORMAT),
            'updated_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);

        $preferred = new LLMDriverConfiguration();
        $preferred->principal_id = createUserPrincipalPublic($userId);
        $preferred->name = 'Preferred';
        $preferred->driver_class = OpenAICompatibleDriver::class;
        $preferred->settings = json_encode([]);
        $preferred->save();
        PrincipalPreference::create([
            'principal_id' => createUserPrincipalPublic($userId),
            'preferred_llm_config_id' => (int) $preferred->getKey(),
        ]);

        $globalDefault = new LLMDriverConfiguration();
        $globalDefault->principal_id = null;
        $globalDefault->name = 'Global';
        $globalDefault->driver_class = OpenAICompatibleDriver::class;
        $globalDefault->settings = json_encode([]);
        $globalDefault->is_global = true;
        $globalDefault->is_default = true;
        $globalDefault->save();

        $agent = new Agent();
        $agent->id = 902;
        $agent->principal_id = createUserPrincipalPublic($userId);
        $agent->llm_driver_config_id = null;

        $result = $preferences->getEffectiveConfigForAgent($agent);

        expect($result)->not->toBeNull()
            ->and((int) $result->getKey())->toBe((int) $preferred->getKey())
            ->and($result->name)->toBe('Preferred');
    });

    test('returns null when no configuration exists at any tier', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
        $preferences = new LLMConfigPreferences();

        $userId = Capsule::table('users')->insertGetId([
            'email'    => 'eff-none@example.com',
            'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date(TEST_TIMESTAMP_FORMAT),
            'updated_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);

        $agent = new Agent();
        $agent->id = 903;
        $agent->principal_id = createUserPrincipalPublic($userId);
        $agent->llm_driver_config_id = null;

        $result = $preferences->getEffectiveConfigForAgent($agent);

        expect($result)->toBeNull();
    });

    test('uses the global default as the last-resort tier-3 fallback', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
        $preferences = new LLMConfigPreferences();

        $userId = Capsule::table('users')->insertGetId([
            'email'    => 'eff-tier3-fallback@example.com',
            'password' => password_hash(TEST_USER_PASSWORD, PASSWORD_DEFAULT),
            'registered' => time(),
            'created_at' => date(TEST_TIMESTAMP_FORMAT),
            'updated_at' => date(TEST_TIMESTAMP_FORMAT),
        ]);

        $globalDefault = new LLMDriverConfiguration();
        $globalDefault->principal_id = null;
        $globalDefault->name = 'Global Default';
        $globalDefault->driver_class = OpenAICompatibleDriver::class;
        $globalDefault->settings = json_encode([]);
        $globalDefault->is_global = true;
        $globalDefault->is_default = true;
        $globalDefault->save();

        $agent = new Agent();
        $agent->id = 904;
        $agent->principal_id = createUserPrincipalPublic($userId);
        $agent->llm_driver_config_id = null;

        $result = $preferences->getEffectiveConfigForAgent($agent);

        expect($result)->not()->toBeNull()
            ->and((int) $result->getKey())->toBe((int) $globalDefault->getKey())
            ->and($result->name)->toBe('Global Default');
    });
});

describe('LLMConfigService facade → schema inspector (getPasswordKeys)', function (): void {
    // After the LLMConfigService split (refactor/split-llm-config-service),
    // getPasswordKeys lives on LLMConfigSchemaInspector. The facade still
    // exposes it (delegated), so we drive it via the public API path.

    test('returns the api_key list for OpenAICompatibleDriver', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

        // Use the public facade to reach the inspector.
        $ref  = new ReflectionObject($service);
        $prop = $ref->getProperty('schemaInspector');
        /** @var LLMConfigSchemaInspector $inspector */
        $inspector = $prop->getValue($service);

        $keys = $inspector->getPasswordKeysFor(OpenAICompatibleDriver::class);
        expect($keys)->toBe(['api_key']);
    });

    test('returns the api_key list for AnthropicCompatibleDriver', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, [AnthropicCompatibleDriver::class]);

        $ref  = new ReflectionObject($service);
        $prop = $ref->getProperty('schemaInspector');
        /** @var LLMConfigSchemaInspector $inspector */
        $inspector = $prop->getValue($service);

        $keys = $inspector->getPasswordKeysFor(AnthropicCompatibleDriver::class);
        expect($keys)->toBe(['api_key']);
    });

    test('returns an empty list for a driver class with no password-typed settings', function (): void {
        $security = Mockery::mock(SecurityManagerInterface::class);
        $service = new LLMConfigService($security, []);

        $ref  = new ReflectionObject($service);
        $prop = $ref->getProperty('schemaInspector');
        /** @var LLMConfigSchemaInspector $inspector */
        $inspector = $prop->getValue($service);

        $keys = $inspector->getPasswordKeysFor(NoPasswordFixtureDriver::class);
        expect($keys)->toBe([]);
    });

    describe('getConfigurationsForAgent', function (): void {
        test('returns the agent-principal configs plus every global config', function (): void {
            $security = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

            $userA = (int) Capsule::table('users')->insertGetId([
                'email'      => 'scope-llm-a@example.com',
                'username'   => 'scope_llm_a',
                'password'   => str_repeat("\0", 60),
                'status'     => 1,
                'verified'   => 1,
                'resettable' => 1,
                'roles_mask' => 0,
                'registered' => time(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $userB = (int) Capsule::table('users')->insertGetId([
                'email'      => 'scope-llm-b@example.com',
                'username'   => 'scope_llm_b',
                'password'   => str_repeat("\0", 60),
                'status'     => 1,
                'verified'   => 1,
                'resettable' => 1,
                'roles_mask' => 0,
                'registered' => time(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $principalA = createUserPrincipalPublic($userA);
            $principalB = createUserPrincipalPublic($userB);

            $userAConfig = new LLMDriverConfiguration();
            $userAConfig->principal_id = $principalA;
            $userAConfig->name = 'A-User';
            $userAConfig->driver_class = OpenAICompatibleDriver::class;
            $userAConfig->settings = '{}';
            $userAConfig->is_default = false;
            $userAConfig->save();

            $userBConfig = new LLMDriverConfiguration();
            $userBConfig->principal_id = $principalB;
            $userBConfig->name = 'B-User';
            $userBConfig->driver_class = OpenAICompatibleDriver::class;
            $userBConfig->settings = '{}';
            $userBConfig->is_default = false;
            $userBConfig->save();

            $globalConfig = new LLMDriverConfiguration();
            $globalConfig->name = 'Global';
            $globalConfig->driver_class = OpenAICompatibleDriver::class;
            $globalConfig->settings = '{}';
            $globalConfig->is_default = false;
            $globalConfig->is_global = true;
            $globalConfig->save();

            $agentRow = new Agent();
            $agentRow->id = 8001;
            $agentRow->principal_id = $principalA;
            $agentRow->llm_driver_config_id = null;

            // Insert the agent row directly so Agent::find() in the
            // service can resolve principal_id (Agent is an Eloquent
            // model with the agents table; save it via Capsule).
            Capsule::table('agents')->insert([
                'id'         => 8001,
                'principal_id' => $principalA,
                'name'       => 'A',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $returned = $service->getConfigurationsForAgent(8001);
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $returned);
            sort($ids);

            expect($ids)->toBe([min((int) $globalConfig->id, (int) $userAConfig->id), max((int) $globalConfig->id, (int) $userAConfig->id)])
                ->and($ids)->not->toContain((int) $userBConfig->id);
        });

        test('returns only the group-scope configs when the agent is owned by a group, plus global', function (): void {
            $security = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);

            $ownerId = (int) Capsule::table('users')->insertGetId([
                'email'      => 'scope-llm-grp-owner@example.com',
                'username'   => 'scope_llm_grp',
                'password'   => str_repeat("\0", 60),
                'status'     => 1,
                'verified'   => 1,
                'resettable' => 1,
                'roles_mask' => 0,
                'registered' => time(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $userPrincipalId = createUserPrincipalPublic($ownerId);

            $groupId = (int) Capsule::table('groups')->insertGetId([
                'name'               => 'scope-llm-group',
                'description'        => null,
                'created_by_user_id' => $ownerId,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
            $groupPrincipalId = (int) Capsule::table('principals')->insertGetId([
                'type'       => \Spora\Models\Principal::TYPE_GROUP,
                'group_id'   => $groupId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $groupConfig = new LLMDriverConfiguration();
            $groupConfig->principal_id = $groupPrincipalId;
            $groupConfig->name = 'Group';
            $groupConfig->driver_class = OpenAICompatibleDriver::class;
            $groupConfig->settings = '{}';
            $groupConfig->is_default = false;
            $groupConfig->save();

            $userConfig = new LLMDriverConfiguration();
            $userConfig->principal_id = $userPrincipalId;
            $userConfig->name = 'User';
            $userConfig->driver_class = OpenAICompatibleDriver::class;
            $userConfig->settings = '{}';
            $userConfig->is_default = false;
            $userConfig->save();

            $globalConfig = new LLMDriverConfiguration();
            $globalConfig->name = 'Global';
            $globalConfig->driver_class = OpenAICompatibleDriver::class;
            $globalConfig->settings = '{}';
            $globalConfig->is_default = false;
            $globalConfig->is_global = true;
            $globalConfig->save();

            Capsule::table('agents')->insert([
                'id'         => 8002,
                'principal_id' => $groupPrincipalId,
                'name'       => 'G',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $returned = $service->getConfigurationsForAgent(8002);
            $ids = array_map(static fn(array $r): int => (int) $r['id'], $returned);
            sort($ids);

            expect($ids)->toBe([min((int) $globalConfig->id, (int) $groupConfig->id), max((int) $globalConfig->id, (int) $groupConfig->id)])
                ->and($ids)->not->toContain((int) $userConfig->id);
        });

        test('returns an empty list when the agent row does not exist', function (): void {
            $security = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $service = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
            expect($service->getConfigurationsForAgent(999_999_999))->toBe([]);
        });
    });
});
