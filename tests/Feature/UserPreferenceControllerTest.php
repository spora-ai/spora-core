<?php

declare(strict_types=1);

use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\UserPreferenceController;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\UserPreference;
use Spora\Services\LLMConfigService;
use Symfony\Component\HttpFoundation\Response;

function makeUserPreferenceController(): array
{
    $authService = bootAuthLayer();
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $llmConfigService = new LLMConfigService($security, [
        OpenAICompatibleDriver::class,
        AnthropicCompatibleDriver::class,
    ]);
    $controller = new UserPreferenceController($authService, $llmConfigService);

    return [$controller, $authService, $llmConfigService, $key];
}

// Note: makeAdmin() and createTestConfig() are defined in LLMConfigControllerTest.php
// and shared globally across all Feature tests

// Helpers

// jsonRequest() is defined globally in tests/Pest.php

// GET /api/v1/user-preferences/llm

test('get returns null when no preference set', function (): void {
    [$controller, $authService] = makeUserPreferenceController();
    bootAuth($authService);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->show($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['config'])->toBeNull();
});

test('get returns the preferred config when set', function (): void {
    [$controller, $authService, $llmConfigService] = makeUserPreferenceController();
    $userId = bootAuth($authService, 'prefuser@example.com');

    // Create a personal config
    $config = createTestConfig('My Pref Config', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-pref-test',
        'model' => 'gpt-4o',
    ], false, $userId, $llmConfigService);

    // Set it as preference
    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $config->id,
    ]);

    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->show($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['config']['id'])->toBe($config->id)
        ->and($body['data']['config']['name'])->toBe('My Pref Config');

    // Cleanup
    UserPreference::where('user_id', $userId)->delete();
    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('get returns 401 for unauthenticated request', function (): void {
    [$controller] = makeUserPreferenceController();
    clearSession();

    $request = new Symfony\Component\HttpFoundation\Request();
    expect(fn() => $controller->show($request))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

test('user cannot access another user preference (returns null for other user)', function (): void {
    [$controller, $authService, $llmConfigService] = makeUserPreferenceController();

    // User A creates a config and sets it as preference
    $userA = bootAuth($authService, 'usera_pref@example.com');
    $configA = createTestConfig('UserA Config', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-usera',
        'model' => 'gpt-4o',
    ], false, $userA, $llmConfigService);
    UserPreference::create([
        'user_id' => $userA,
        'preferred_llm_config_id' => $configA->id,
    ]);

    // User B logs in
    $userB = bootAuth($authService, 'userb_pref@example.com');

    // User B should not see User A's preference
    $request = new Symfony\Component\HttpFoundation\Request();
    $response = $controller->show($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    // User B has no preference set, so config should be null
    expect($body['data']['config'])->toBeNull();

    // Cleanup
    UserPreference::where('user_id', $userA)->delete();
    LLMDriverConfiguration::where('id', $configA->id)->delete();
});

// PUT /api/v1/user-preferences/llm

test('put sets a personal config as preference', function (): void {
    [$controller, $authService, $llmConfigService] = makeUserPreferenceController();
    $userId = bootAuth($authService, 'putpersonal@example.com');

    $config = createTestConfig('Personal Pref Test', AnthropicCompatibleDriver::class, [
        'api_key' => 'sk-anthropic-pref',
        'model' => 'claude-3-5-sonnet',
    ], false, $userId, $llmConfigService);

    $request = jsonRequest('PUT', '/api/v1/user-preferences/llm', [
        'config_id' => $config->id,
    ]);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['config']['id'])->toBe($config->id)
        ->and($body['data']['config']['name'])->toBe('Personal Pref Test');

    // Verify database
    $pref = UserPreference::where('user_id', $userId)->first();
    expect($pref->preferred_llm_config_id)->toBe($config->id);

    // Cleanup
    UserPreference::where('user_id', $userId)->delete();
    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('put sets a global config as preference', function (): void {
    [$controller, $authService, $llmConfigService] = makeUserPreferenceController();
    $userId = bootAuth($authService, 'putglobal@example.com');
    makeAdmin($authService, $userId);

    // Create a global config
    $globalConfig = createTestConfig('Global Pref Config', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-global-pref',
        'model' => 'gpt-4o',
    ], false, null, $llmConfigService);
    $globalConfig->is_global = true;
    $globalConfig->save();

    $request = jsonRequest('PUT', '/api/v1/user-preferences/llm', [
        'config_id' => $globalConfig->id,
    ]);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['config']['id'])->toBe($globalConfig->id);

    // Verify database
    $pref = UserPreference::where('user_id', $userId)->first();
    expect($pref->preferred_llm_config_id)->toBe($globalConfig->id);

    // Cleanup
    UserPreference::where('user_id', $userId)->delete();
    LLMDriverConfiguration::where('id', $globalConfig->id)->delete();
});

test('put clears preference when config_id is null', function (): void {
    [$controller, $authService, $llmConfigService] = makeUserPreferenceController();
    $userId = bootAuth($authService, 'clearpref@example.com');

    // Create and set a preference first
    $config = createTestConfig('To Clear', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-clear',
        'model' => 'gpt-4o',
    ], false, $userId, $llmConfigService);
    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $config->id,
    ]);

    // Now clear it
    $request = jsonRequest('PUT', '/api/v1/user-preferences/llm', [
        'config_id' => null,
    ]);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['config'])->toBeNull();

    // Verify database
    $pref = UserPreference::where('user_id', $userId)->first();
    expect($pref)->toBeNull();

    // Cleanup
    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('put returns 422 when config_id does not exist', function (): void {
    [$controller, $authService] = makeUserPreferenceController();
    bootAuth($authService, 'notfound@example.com');

    $request = jsonRequest('PUT', '/api/v1/user-preferences/llm', [
        'config_id' => 99999,
    ]);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('put returns 422 when config_id belongs to another user', function (): void {
    [$controller, $authService, $llmConfigService] = makeUserPreferenceController();

    // User A creates a config
    $userA = bootAuth($authService, 'ownera_pref@example.com');
    $configA = createTestConfig('Owner Config', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-owner-pref',
        'model' => 'gpt-4o',
    ], false, $userA, $llmConfigService);

    // User B tries to set User A's config as their preference
    bootAuth($authService, 'other_pref@example.com');

    $request = jsonRequest('PUT', '/api/v1/user-preferences/llm', [
        'config_id' => $configA->id,
    ]);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);

    // Cleanup
    LLMDriverConfiguration::where('id', $configA->id)->delete();
});

test('put returns 401 for unauthenticated request', function (): void {
    [$controller] = makeUserPreferenceController();
    clearSession();

    $request = jsonRequest('PUT', '/api/v1/user-preferences/llm', [
        'config_id' => 1,
    ]);
    expect(fn() => $controller->update($request))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

test('preference is deleted when the referenced config is deleted (cascade)', function (): void {
    [$controller, $authService, $llmConfigService] = makeUserPreferenceController();
    $userId = bootAuth($authService, 'cascade_pref@example.com');

    $config = createTestConfig('Cascade Test', OpenAICompatibleDriver::class, [
        'api_key' => 'sk-cascade',
        'model' => 'gpt-4o',
    ], false, $userId, $llmConfigService);

    // Set it as preference
    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $config->id,
    ]);

    // Delete the config via the service (which handles cascade)
    $llmConfigService->deleteConfiguration($config->id, $userId, false);

    // Verify preference is gone
    $pref = UserPreference::where('user_id', $userId)->first();
    expect($pref)->toBeNull();
});
