<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Models\LLMDriverConfiguration;
use Spora\Security\CsrfTokenService;
use Symfony\Component\HttpFoundation\Response;

const OPENAI_BASE_URL = 'https://api.openai.com/v1';
const LLM_CONFIGS_URI = '/api/v1/llm-configs';
const NAME_TEST_CONFIG = 'Test Config';
const NAME_USER_A_CONFIG = 'UserA Config';
const NAME_USER_B_CONFIG = 'UserB Config';
const NAME_COMPANY_WIDE_CONFIG = 'Company Wide Config';
const NAME_GLOBAL_FOR_ALL = 'Global for All';
const NAME_PERSONAL_CONFIG = 'Personal Config';
const NAME_GLOBAL_COMPANY = 'Global Company';
const NAME_PERSONAL_ONLY = 'Personal Only';
const NAME_GLOBAL_ONLY = 'Global Only';
const EMAIL_USER_A = 'usera@example.com';
const EMAIL_USER_B = 'userb@example.com';

function makeLLMConfigController(): array
{
    $authService = bootAuthLayer();
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $llmConfigService = new Spora\Services\LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
    $validator = new Spora\Services\LlmConfigValidator($llmConfigService);
    $controller = new Spora\Http\LLMConfigController($authService, $llmConfigService, $validator);
    $authMiddleware = new AuthMiddleware($authService);
    $csrfService = new CsrfTokenService();
    $csrfMiddleware = new CsrfMiddleware($csrfService);

    return [$controller, $authService, $llmConfigService, $key, $authMiddleware, $csrfMiddleware];
}

// makeAdmin() and createTestConfig() are loaded globally via composer.json
// (autoload-dev.files -> tests/Support/CrossFileTestHelpers.php) so they are
// visible to tests in other files under Pest's parallel runner.

// Helpers

// jsonRequest() is defined globally in tests/Pest.php

// Tests

test('drivers() returns all registered drivers with schemas', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    $userId = $authService->register('drivers@example.com', 'Password1!', 'Drivers User');
    simulateLoggedInSession($userId, 'drivers@example.com');

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = callController($controller, 'drivers', $request, [$authMiddleware]);

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
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService);

    $body = [
        'name' => NAME_TEST_CONFIG,
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => [
            'api_key' => 'sk-test',
            'base_url' => OPENAI_BASE_URL,
            'model' => 'gpt-4o',
        ],
    ];

    $request = jsonRequest('POST', LLM_CONFIGS_URI, $body);
    $response = callController($controller, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['name'])->toBe(NAME_TEST_CONFIG)
        ->and($result['driver_class'])->toBe(OpenAICompatibleDriver::class)
        ->and($result['settings']['api_key'])->toBe('***')  // masked for API response
        ->and($result['is_default'])->toBe(false);

    // Cleanup
    LLMDriverConfiguration::where('name', NAME_TEST_CONFIG)->delete();
});

test('store() saves context_window and max_tokens_output columns', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService, 'tokentest@example.com');

    $body = [
        'name' => 'Token Config Test',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => [
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
        ],
        'context_window' => 128000,
        'max_tokens_output' => 4096,
    ];

    $request = jsonRequest('POST', LLM_CONFIGS_URI, $body);
    $response = callController($controller, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['context_window'])->toBe(128000)
        ->and($result['max_tokens_output'])->toBe(4096);

    LLMDriverConfiguration::where('id', $result['id'])->delete();
});

test('update() can update context_window and max_tokens_output', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    $userId = bootAuth($authService, 'updatetoken@example.com');

    $config = createTestConfig('Update Token Test', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-test',
        'model' => 'gpt-4o',
    ], false, $userId);

    $request = jsonRequest('PUT', LLM_CONFIGS_URI . "/{$config->id}", [
        'context_window' => 200000,
        'max_tokens_output' => 8192,
    ]);
    $request->attributes->set('id', $config->id);
    $response = callController($controller, 'update', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['context_window'])->toBe(200000)
        ->and($result['max_tokens_output'])->toBe(8192);

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('configResource() includes context_window and max_tokens_output', function (): void {
    [$controller, $authService] = makeLLMConfigController();
    $userId = bootAuth($authService, 'resourcetest@example.com');

    $config = createTestConfig('Resource Test', AnthropicCompatibleDriver::class, [
        'api_key' => 'sk-anthropic',
        'model' => 'claude-3-5-sonnet',
    ], false, $userId);

    $config->context_window = 200000;
    $config->max_tokens_output = 8192;
    $config->save();

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->show($config->id);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['context_window'])->toBe(200000)
        ->and($result['max_tokens_output'])->toBe(8192);

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('store() returns 422 when name is empty', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService);

    $body = [
        'name' => '',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk-test'],
    ];

    $request = jsonRequest('POST', LLM_CONFIGS_URI, $body);
    $response = callController($controller, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('store() returns 422 for invalid driver_class', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService);

    $body = [
        'name' => 'Bad Config',
        'driver_class' => 'Spora\Drivers\NonExistent',
        'settings' => [],
    ];

    $request = jsonRequest('POST', LLM_CONFIGS_URI, $body);
    $response = callController($controller, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('show() returns a config by id', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService);

    $config = createTestConfig('Show Test', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-show-test',
        'model' => 'gpt-4o',
    ], false, null, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('id', $config->id);
    $response = callController($controller, 'show', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['name'])->toBe('Show Test')
        ->and($result['settings']['api_key'])->toBe('***');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('show() returns 404 for unknown id', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('id', 99999);
    $response = callController($controller, 'show', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

test('update() modifies a config', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService);

    $config = createTestConfig('Update Test', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-old',
        'model' => 'gpt-4o',
    ], false, null, $llmConfigService);

    $body = ['name' => 'Updated Name'];
    $request = jsonRequest('PUT', LLM_CONFIGS_URI . "/{$config->id}", $body);
    $request->attributes->set('id', $config->id);
    $response = callController($controller, 'update', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['name'])->toBe('Updated Name');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('destroy() deletes a config', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService);

    $config = createTestConfig('Delete Test', OpenAICompatibleDriver::class, ['api_key' => 'sk-del'], false, null, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('id', $config->id);
    $response = callController($controller, 'destroy', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);
    expect(LLMDriverConfiguration::find($config->id))->toBeNull();
});

test('setDefault() sets a global config as default when called by admin', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    $userId = bootAuth($authService);
    makeAdmin($authService, $userId);

    // Create two global configs
    $config1 = createTestConfig('Global Default 1', OpenAICompatibleDriver::class, ['api_key' => 'sk-1'], false, null, $llmConfigService);
    $config1->is_global = true;
    $config1->save();

    $config2 = createTestConfig('Global Default 2', OpenAICompatibleDriver::class, ['api_key' => 'sk-2'], false, null, $llmConfigService);
    $config2->is_global = true;
    $config2->save();

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('id', $config2->id);
    $response = callController($controller, 'setDefault', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['is_default'])->toBe(true);

    // Verify config1 is no longer default
    $config1Refresh = LLMDriverConfiguration::find($config1->id);
    expect($config1Refresh->is_default)->toBe(false);

    LLMDriverConfiguration::whereIn('id', [$config1->id, $config2->id])->delete();
});

test('setDefault() returns 403 when called on personal config by non-admin', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    $userId = bootAuth($authService);

    // Create a personal config (user_id = current user, is_global = false)
    $config = createTestConfig('Personal Config Default', OpenAICompatibleDriver::class, ['api_key' => 'sk-personal'], false, $userId, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('id', $config->id);
    $response = callController($controller, 'setDefault', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('setDefault() returns 403 when non-admin calls set-default on global config', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService);

    // Create a global config
    $globalConfig = createTestConfig('Global Config', OpenAICompatibleDriver::class, ['api_key' => 'sk-global'], false, null, $llmConfigService);
    $globalConfig->is_global = true;
    $globalConfig->save();

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('id', $globalConfig->id);
    $response = callController($controller, 'setDefault', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);

    LLMDriverConfiguration::where('id', $globalConfig->id)->delete();
});

test('index() returns all configs', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService);

    $config = createTestConfig('Index Test', AnthropicCompatibleDriver::class, [
        'api_key' => 'sk-anthropic',
        'model' => 'claude-3-5-sonnet',
    ], false, null, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = callController($controller, 'index', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['configs'])->toBeArray();

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

// Multi-tenancy isolation

test('index() only returns configs belonging to the current user', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService, EMAIL_USER_A);

    createTestConfig(NAME_USER_A_CONFIG, OpenAICompatibleDriver::class, ['api_key' => 'sk-usera'], false, null, $llmConfigService);

    // Register and log in as a different user
    bootAuth($authService, EMAIL_USER_B);
    createTestConfig(NAME_USER_B_CONFIG, AnthropicCompatibleDriver::class, ['api_key' => 'sk-userb'], false, null, $llmConfigService);

    // User B should only see their own config
    $request = new Symfony\Component\HttpFoundation\Request();
    $response = callController($controller, 'index', $request, [$authMiddleware]);
    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    $names = array_column($body['data']['configs'], 'name');
    expect($names)->toContain(NAME_USER_B_CONFIG)
        ->and($names)->not()->toContain(NAME_USER_A_CONFIG);

    LLMDriverConfiguration::whereIn('name', [NAME_USER_A_CONFIG, NAME_USER_B_CONFIG])->delete();
});

test('show() returns 404 when fetching another user\'s config', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService, EMAIL_USER_A);

    $configA = createTestConfig('UserA Private', OpenAICompatibleDriver::class, ['api_key' => 'sk-private'], false, null, $llmConfigService);

    // Log in as a different user
    bootAuth($authService, EMAIL_USER_B);

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('id', $configA->id);
    $response = callController($controller, 'show', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);

    LLMDriverConfiguration::where('id', $configA->id)->delete();
});

test('update() returns 404 when updating another user\'s config', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    $userA = bootAuth($authService, EMAIL_USER_A);

    $configA = createTestConfig('UserA Update', OpenAICompatibleDriver::class, ['api_key' => 'sk-update'], false, $userA, $llmConfigService);

    // Log in as a different user
    bootAuth($authService, EMAIL_USER_B);

    $request = jsonRequest('PUT', LLM_CONFIGS_URI . "/{$configA->id}", ['name' => 'Hijacked']);
    $request->attributes->set('id', $configA->id);
    $response = callController($controller, 'update', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);

    LLMDriverConfiguration::where('id', $configA->id)->delete();
});

test('destroy() returns 404 when deleting another user\'s config', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    $userA = bootAuth($authService, EMAIL_USER_A);

    $configA = createTestConfig('UserA Delete', OpenAICompatibleDriver::class, ['api_key' => 'sk-delete'], false, $userA, $llmConfigService);

    // Log in as a different user
    bootAuth($authService, EMAIL_USER_B);

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('id', $configA->id);
    $response = callController($controller, 'destroy', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    // Verify config still exists
    expect(LLMDriverConfiguration::find($configA->id))->not()->toBeNull();

    LLMDriverConfiguration::where('id', $configA->id)->delete();
});

test('setDefault() only affects the current user\'s configs', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService, EMAIL_USER_A);

    $configA = createTestConfig('UserA Default', OpenAICompatibleDriver::class, ['api_key' => 'sk-usera'], false, null, $llmConfigService);

    // Log in as a different user and set their own default
    bootAuth($authService, EMAIL_USER_B);
    $configB = createTestConfig('UserB Default', AnthropicCompatibleDriver::class, ['api_key' => 'sk-userb'], false, null, $llmConfigService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $request->attributes->set('id', $configB->id);
    callController($controller, 'setDefault', $request, [$authMiddleware]);

    // User A's config should not have been changed
    $configARefresh = LLMDriverConfiguration::find($configA->id);
    expect($configARefresh->is_default)->toBe(false);

    LLMDriverConfiguration::whereIn('id', [$configA->id, $configB->id])->delete();
});

// Fix: update() ignores settings when value is a JSON array (not an object)

test('update() does not corrupt settings when client sends a JSON array instead of object', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    $userId = bootAuth($authService, 'arrayfix@example.com');

    $config = createTestConfig(
        'Array Guard Test',
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-original', 'model' => 'gpt-4o', 'base_url' => OPENAI_BASE_URL],
        false,
        $userId,
        $llmConfigService,
    );

    // Send settings as a sequential JSON array — should be silently ignored
    $request = jsonRequest('PUT', LLM_CONFIGS_URI . "/{$config->id}", [
        'settings' => [['api_key' => 'sk-hijacked']],
    ]);
    $request->attributes->set('id', $config->id);
    $response = callController($controller, 'update', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    // Original settings must be unchanged
    $config->refresh();
    $decrypted = $llmConfigService->decryptSettings($config->driver_class, $config->getRawOriginal('settings'));
    expect($decrypted['api_key'])->toBe('sk-original');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('update() applies settings normally when value is a proper JSON object', function (): void {
    [$controller, $authService, $llmConfigService, , $authMiddleware] = makeLLMConfigController();
    $userId = bootAuth($authService, 'objectupdate@example.com');

    $config = createTestConfig(
        'Object Update Test',
        OpenAICompatibleDriver::class,
        ['api_key' => 'sk-old', 'model' => 'gpt-4o', 'base_url' => OPENAI_BASE_URL],
        false,
        $userId,
        $llmConfigService,
    );

    $request = jsonRequest('PUT', LLM_CONFIGS_URI . "/{$config->id}", [
        'settings' => ['model' => 'gpt-4-turbo'],
    ]);
    $request->attributes->set('id', $config->id);
    $response = callController($controller, 'update', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $config->refresh();
    $decrypted = $llmConfigService->decryptSettings($config->driver_class, $config->getRawOriginal('settings'));
    // model updated, api_key preserved via merge
    expect($decrypted['model'])->toBe('gpt-4-turbo')
        ->and($decrypted['api_key'])->toBe('sk-old');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('store() creates a config owned by the current user', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService, 'ownera@example.com');

    $body = [
        'name' => 'Owned Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => [
            'api_key' => 'sk-owned',
            'base_url' => OPENAI_BASE_URL,
            'model' => 'gpt-4o',
        ],
    ];

    $request = jsonRequest('POST', LLM_CONFIGS_URI, $body);
    $response = callController($controller, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

    $result = json_decode($response->getContent(), true)['data']['config'];
    $savedConfig = LLMDriverConfiguration::find($result['id']);
    expect($savedConfig->user_id)->toBe((int) $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID]);

    LLMDriverConfiguration::where('id', $result['id'])->delete();
});

// Global LLM Driver Configurations

test('admin can create a global config with is_global=true', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    $userId = bootAuth($authService, 'admin@example.com');
    makeAdmin($authService, $userId);

    $body = [
        'name' => 'Global Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => [
            'api_key' => 'sk-global',
            'base_url' => OPENAI_BASE_URL,
            'model' => 'gpt-4o',
        ],
        'is_global' => true,
    ];

    $request = jsonRequest('POST', LLM_CONFIGS_URI, $body);
    $response = callController($controller, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);

    $result = json_decode($response->getContent(), true)['data']['config'];
    expect($result['is_global'])->toBe(true);

    $savedConfig = LLMDriverConfiguration::find($result['id']);
    expect($savedConfig->user_id)->toBeNull();
    expect($savedConfig->is_global)->toBe(true);

    LLMDriverConfiguration::where('id', $result['id'])->delete();
});

test('non-admin cannot create a global config', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    bootAuth($authService, 'regular@example.com');

    $body = [
        'name' => 'Forbidden Global',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk-test'],
        'is_global' => true,
    ];

    $request = jsonRequest('POST', LLM_CONFIGS_URI, $body);
    $response = callController($controller, 'store', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);

    LLMDriverConfiguration::where('name', 'Forbidden Global')->delete();
});

test('global configs are visible to all users via index()', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();

    // User A (admin) creates a global config
    $userA = bootAuth($authService, 'adminglobal@example.com');
    makeAdmin($authService, $userA);

    $globalBody = [
        'name' => NAME_COMPANY_WIDE_CONFIG,
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk-company-wide', 'model' => 'gpt-4o'],
        'is_global' => true,
    ];

    $request = jsonRequest('POST', LLM_CONFIGS_URI, $globalBody);
    callController($controller, 'store', $request, [$authMiddleware]);

    // User B (non-admin) logs in and should see the global config
    bootAuth($authService, 'userseesglobal@example.com');

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = callController($controller, 'index', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    $names = array_column($body['data']['configs'], 'name');
    expect($names)->toContain(NAME_COMPANY_WIDE_CONFIG);

    LLMDriverConfiguration::where('name', NAME_COMPANY_WIDE_CONFIG)->delete();
});

test('global configs are not included in other user\'s personal configs', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();

    // User A (admin) creates a global config
    $userA = bootAuth($authService, 'adminglobal2@example.com');
    makeAdmin($authService, $userA);

    $globalBody = [
        'name' => NAME_GLOBAL_FOR_ALL,
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk-global-2', 'model' => 'gpt-4o'],
        'is_global' => true,
    ];

    $request = jsonRequest('POST', LLM_CONFIGS_URI, $globalBody);
    callController($controller, 'store', $request, [$authMiddleware]);

    // User B logs in
    bootAuth($authService, 'userseesglobal2@example.com');

    // User B's index should include global configs but they should have is_global=true and user_id=null
    $request = new Symfony\Component\HttpFoundation\Request();
    $response = callController($controller, 'index', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    $globalConfigs = array_filter($body['data']['configs'], static fn(array $c): bool => $c['is_global'] === true);
    $nonGlobalConfigs = array_filter($body['data']['configs'], static fn(array $c): bool => $c['is_global'] !== true);

    // Should have exactly 1 global config visible
    expect(count($globalConfigs))->toBe(1);
    // The global config should belong to no user (user_id is null)
    $firstGlobal = array_values($globalConfigs)[0];
    expect($firstGlobal['user_id'])->toBeNull();

    // User B's personal configs should not include the global
    $names = array_column($nonGlobalConfigs, 'name');
    expect($names)->not()->toContain(NAME_GLOBAL_FOR_ALL);

    LLMDriverConfiguration::where('name', NAME_GLOBAL_FOR_ALL)->delete();
});

test('getConfigurationsForUser returns both personal and global configs', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    $userId = bootAuth($authService, 'personalandglobal@example.com');

    // Personal config (non-admin user)
    $personalBody = [
        'name' => NAME_PERSONAL_CONFIG,
        'driver_class' => AnthropicCompatibleDriver::class,
        'settings' => ['api_key' => 'sk-personal', 'model' => 'claude-3-5-sonnet'],
    ];
    $request = jsonRequest('POST', LLM_CONFIGS_URI, $personalBody);
    callController($controller, 'store', $request, [$authMiddleware]);

    // Global config (created by same user but as admin)
    makeAdmin($authService, $userId);
    $globalBody = [
        'name' => NAME_GLOBAL_COMPANY,
        'driver_class' => AnthropicCompatibleDriver::class,
        'settings' => ['api_key' => 'sk-global', 'model' => 'claude-3-5-sonnet'],
        'is_global' => true,
    ];
    $request = jsonRequest('POST', LLM_CONFIGS_URI, $globalBody);
    callController($controller, 'store', $request, [$authMiddleware]);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = callController($controller, 'index', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    $names = array_column($body['data']['configs'], 'name');
    expect($names)->toContain(NAME_PERSONAL_CONFIG)
        ->and($names)->toContain(NAME_GLOBAL_COMPANY);

    LLMDriverConfiguration::whereIn('name', [NAME_PERSONAL_CONFIG, NAME_GLOBAL_COMPANY])->delete();
});

test('globalConfigs() endpoint returns only global configs', function (): void {
    [$controller, $authService, , , $authMiddleware] = makeLLMConfigController();
    $userId = bootAuth($authService, 'adminglobalconfigs@example.com');
    makeAdmin($authService, $userId);

    // Personal config
    $personalBody = [
        'name' => NAME_PERSONAL_ONLY,
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk-personal', 'model' => 'gpt-4o'],
    ];
    $request = jsonRequest('POST', LLM_CONFIGS_URI, $personalBody);
    callController($controller, 'store', $request, [$authMiddleware]);

    // Global config
    $globalBody = [
        'name' => NAME_GLOBAL_ONLY,
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => ['api_key' => 'sk-global', 'model' => 'gpt-4o'],
        'is_global' => true,
    ];
    $request = jsonRequest('POST', LLM_CONFIGS_URI, $globalBody);
    callController($controller, 'store', $request, [$authMiddleware]);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = callController($controller, 'globalConfigs', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    $names = array_column($body['data']['configs'], 'name');
    expect($names)->toContain(NAME_GLOBAL_ONLY)
        ->and($names)->not()->toContain(NAME_PERSONAL_ONLY);

    LLMDriverConfiguration::whereIn('name', [NAME_PERSONAL_ONLY, NAME_GLOBAL_ONLY])->delete();
});
