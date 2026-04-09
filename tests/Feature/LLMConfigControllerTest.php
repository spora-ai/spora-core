<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Models\LLMDriverConfiguration;
use Symfony\Component\HttpFoundation\Response;

function makeLLMConfigController(): array
{
    $authService = bootAuthLayer();
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $llmConfigService = new Spora\Services\LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
    $controller = new Spora\Http\LLMConfigController($authService, $llmConfigService);

    return [$controller, $authService, $llmConfigService, $key];
}

function createTestConfig(string $name, string $driverClass, array $settings, bool $isDefault = false, ?int $userId = null, ?Spora\Services\LLMConfigService $llmConfigService = null): LLMDriverConfiguration
{
    if ($llmConfigService === null) {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $security = new Spora\Core\SecurityManager($key);
        $llmConfigService = new Spora\Services\LLMConfigService($security, [
            OpenAICompatibleDriver::class,
            AnthropicCompatibleDriver::class,
        ]);
    }

    $config = new LLMDriverConfiguration();
    $config->user_id = $userId ?? ($_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID] ?? 1);
    $config->name = $name;
    $config->driver_class = $driverClass;
    $config->settings = $llmConfigService->encryptSettings($settings);
    $config->is_default = $isDefault;
    $config->save();

    return $config;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// jsonRequest() is defined globally in tests/Pest.php

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('drivers() returns all registered drivers with schemas', function (): void {
    [$controller] = makeLLMConfigController();

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->drivers($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['drivers'])->toBeArray();

    $names = array_column($body['data']['drivers'], 'name');
    expect($names)->toContain('openai_compatible')
        ->and($names)->toContain('anthropic_compatible');

    // Each driver should have a settings_schema
    foreach ($body['data']['drivers'] as $driver) {
        expect($driver['settings_schema'])->toBeArray();
        $keys = array_column($driver['settings_schema'], 'key');
        expect($keys)->toContain('api_key')
            ->and($keys)->toContain('base_url')
            ->and($keys)->toContain('model');
    }
});

test('store() creates a new config', function (): void {
    [$controller, $authService] = makeLLMConfigController();
    bootAuth($authService);

    $body = [
        'name' => 'Test Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => [
            'api_key' => 'sk-test',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
        ],
    ];

    $request = jsonRequest('POST', '/api/v1/llm-configs', $body);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['name'])->toBe('Test Config')
        ->and($result['driver_class'])->toBe(OpenAICompatibleDriver::class)
        ->and($result['settings']['api_key'])->toBe('sk-test')  // decrypted from encrypted storage
        ->and($result['is_default'])->toBe(false);

    // Cleanup
    LLMDriverConfiguration::where('name', 'Test Config')->delete();
});

test('store() returns 422 when name is empty', function (): void {
    [$controller, $authService] = makeLLMConfigController();
    bootAuth($authService);

    $body = [
        'name' => '',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk-test'],
    ];

    $request = jsonRequest('POST', '/api/v1/llm-configs', $body);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('store() returns 422 for invalid driver_class', function (): void {
    [$controller, $authService] = makeLLMConfigController();
    bootAuth($authService);

    $body = [
        'name' => 'Bad Config',
        'driver_class' => 'Spora\Drivers\NonExistent',
        'settings' => [],
    ];

    $request = jsonRequest('POST', '/api/v1/llm-configs', $body);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('show() returns a config by id', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    bootAuth($authService);

    $config = createTestConfig('Show Test', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-show-test',
        'model' => 'gpt-4o',
    ], false, null, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->show($request, $config->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['name'])->toBe('Show Test')
        ->and($result['settings']['api_key'])->toBe('sk-show-test');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('show() returns 404 for unknown id', function (): void {
    [$controller, $authService] = makeLLMConfigController();
    bootAuth($authService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->show($request, 99999);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

test('update() modifies a config', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    bootAuth($authService);

    $config = createTestConfig('Update Test', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-old',
        'model' => 'gpt-4o',
    ], false, null, $llmConfigService);

    $body = ['name' => 'Updated Name'];
    $request = jsonRequest('PUT', "/api/v1/llm-configs/{$config->id}", $body);
    $response = $controller->update($request, $config->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['name'])->toBe('Updated Name');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('destroy() deletes a config', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    bootAuth($authService);

    $config = createTestConfig('Delete Test', OpenAICompatibleDriver::class, ['api_key' => 'sk-del'], false, null, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->destroy($request, $config->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT);
    expect(LLMDriverConfiguration::find($config->id))->toBeNull();
});

test('setDefault() sets a config as global default', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    bootAuth($authService);

    // Create two configs
    $config1 = createTestConfig('Default 1', OpenAICompatibleDriver::class, ['api_key' => 'sk-1'], false, null, $llmConfigService);
    $config2 = createTestConfig('Default 2', OpenAICompatibleDriver::class, ['api_key' => 'sk-2'], false, null, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->setDefault($request, $config2->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['is_default'])->toBe(true);

    // Verify config1 is no longer default
    $config1Refresh = LLMDriverConfiguration::find($config1->id);
    expect($config1Refresh->is_default)->toBe(false);

    LLMDriverConfiguration::whereIn('id', [$config1->id, $config2->id])->delete();
});

test('index() returns all configs', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    bootAuth($authService);

    $config = createTestConfig('Index Test', AnthropicCompatibleDriver::class, [
        'api_key' => 'sk-anthropic',
        'model' => 'claude-3-5-sonnet',
    ], false, null, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->index($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['configs'])->toBeArray();

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

// ---------------------------------------------------------------------------
// Multi-tenancy isolation
// ---------------------------------------------------------------------------

test('index() only returns configs belonging to the current user', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    $userA = bootAuth($authService, 'usera@example.com');

    $configA = createTestConfig('UserA Config', OpenAICompatibleDriver::class, ['api_key' => 'sk-usera'], false, null, $llmConfigService);

    // Register and log in as a different user
    $userB = bootAuth($authService, 'userb@example.com');
    createTestConfig('UserB Config', AnthropicCompatibleDriver::class, ['api_key' => 'sk-userb'], false, null, $llmConfigService);

    // User B should only see their own config
    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->index($request);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    $names = array_column($body['data']['configs'], 'name');
    expect($names)->toContain('UserB Config')
        ->and($names)->not()->toContain('UserA Config');

    LLMDriverConfiguration::whereIn('name', ['UserA Config', 'UserB Config'])->delete();
});

test('show() returns 404 when fetching another user\'s config', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    $userA = bootAuth($authService, 'usera@example.com');

    $configA = createTestConfig('UserA Private', OpenAICompatibleDriver::class, ['api_key' => 'sk-private'], false, null, $llmConfigService);

    // Log in as a different user
    bootAuth($authService, 'userb@example.com');

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->show($request, $configA->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);

    LLMDriverConfiguration::where('id', $configA->id)->delete();
});

test('update() returns 404 when updating another user\'s config', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    $userA = bootAuth($authService, 'usera@example.com');

    $configA = createTestConfig('UserA Update', OpenAICompatibleDriver::class, ['api_key' => 'sk-update'], false, null, $llmConfigService);

    // Log in as a different user
    bootAuth($authService, 'userb@example.com');

    $request = jsonRequest('PUT', "/api/v1/llm-configs/{$configA->id}", ['name' => 'Hijacked']);
    $response = $controller->update($request, $configA->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);

    LLMDriverConfiguration::where('id', $configA->id)->delete();
});

test('destroy() returns 404 when deleting another user\'s config', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    $userA = bootAuth($authService, 'usera@example.com');

    $configA = createTestConfig('UserA Delete', OpenAICompatibleDriver::class, ['api_key' => 'sk-delete'], false, null, $llmConfigService);

    // Log in as a different user
    bootAuth($authService, 'userb@example.com');

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->destroy($request, $configA->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    // Verify config still exists
    expect(LLMDriverConfiguration::find($configA->id))->not()->toBeNull();

    LLMDriverConfiguration::where('id', $configA->id)->delete();
});

test('setDefault() only affects the current user\'s configs', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    $userA = bootAuth($authService, 'usera@example.com');

    $configA = createTestConfig('UserA Default', OpenAICompatibleDriver::class, ['api_key' => 'sk-usera'], false, null, $llmConfigService);

    // Log in as a different user and set their own default
    bootAuth($authService, 'userb@example.com');
    $configB = createTestConfig('UserB Default', AnthropicCompatibleDriver::class, ['api_key' => 'sk-userb'], false, null, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $controller->setDefault($request, $configB->id);

    // User A's config should not have been changed
    $configARefresh = LLMDriverConfiguration::find($configA->id);
    expect($configARefresh->is_default)->toBe(false);

    LLMDriverConfiguration::whereIn('id', [$configA->id, $configB->id])->delete();
});

// ---------------------------------------------------------------------------
// Fix: update() ignores settings when value is a JSON array (not an object)
// ---------------------------------------------------------------------------

test('update() does not corrupt settings when client sends a JSON array instead of object', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    $userId = bootAuth($authService, 'arrayfix@example.com');

    $config = createTestConfig(
        'Array Guard Test',
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-original', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1'],
        false,
        $userId,
        $llmConfigService,
    );

    // Send settings as a sequential JSON array — should be silently ignored
    $request = jsonRequest('PUT', "/api/v1/llm-configs/{$config->id}", [
        'settings' => [['api_key' => 'sk-hijacked']],
    ]);
    $response = $controller->update($request, $config->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    // Original settings must be unchanged
    $config->refresh();
    $decrypted = $llmConfigService->decryptSettings($config->getRawOriginal('settings'));
    expect($decrypted['api_key'])->toBe('sk-original');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('update() applies settings normally when value is a proper JSON object', function (): void {
    [$controller, $authService, $llmConfigService] = makeLLMConfigController();
    $userId = bootAuth($authService, 'objectupdate@example.com');

    $config = createTestConfig(
        'Object Update Test',
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-old', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1'],
        false,
        $userId,
        $llmConfigService,
    );

    $request = jsonRequest('PUT', "/api/v1/llm-configs/{$config->id}", [
        'settings' => ['model' => 'gpt-4-turbo'],
    ]);
    $response = $controller->update($request, $config->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $config->refresh();
    $decrypted = $llmConfigService->decryptSettings($config->getRawOriginal('settings'));
    // model updated, api_key preserved via merge
    expect($decrypted['model'])->toBe('gpt-4-turbo')
        ->and($decrypted['api_key'])->toBe('sk-old');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('store() creates a config owned by the current user', function (): void {
    [$controller, $authService] = makeLLMConfigController();
    $userA = bootAuth($authService, 'ownera@example.com');

    $body = [
        'name' => 'Owned Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => [
            'api_key' => 'sk-owned',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o',
        ],
    ];

    $request = jsonRequest('POST', '/api/v1/llm-configs', $body);
    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

    $result = json_decode($response->getContent(), true)['data']['config'];
    $savedConfig = LLMDriverConfiguration::find($result['id']);
    expect($savedConfig->user_id)->toBe((int) $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID]);

    LLMDriverConfiguration::where('id', $result['id'])->delete();
});
