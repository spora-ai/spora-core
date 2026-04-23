<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Http\ToolController;
use Spora\Services\ToolConfigService;
use Tests\Fixtures\TestTool;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Boot a fresh in-memory DB and return ToolController + AuthService.
 *
 * @param string[] $toolClasses
 */
function makeToolController(array $toolClasses = []): array
{
    $authService = bootAuthLayer();

    $key        = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security   = new SecurityManager($key);
    $logger     = new Monolog\Logger('test');
    $toolConfig = new ToolConfigService($security, $logger, $toolClasses);
    $controller = new ToolController($authService, $toolConfig, $toolClasses);

    return [$controller, $authService, $toolConfig];
}

// ---------------------------------------------------------------------------
// Auth guard
// ---------------------------------------------------------------------------

test('unauthenticated request throws UnauthenticatedException', function (): void {
    clearSession();
    [$controller] = makeToolController([TestTool::class]);

    expect(fn() => $controller->index(jsonRequest('GET', '/api/v1/tools')))
        ->toThrow(UnauthenticatedException::class);
});

// ---------------------------------------------------------------------------
// index
// ---------------------------------------------------------------------------

test('index returns schema for registered tool classes', function (): void {
    clearSession();
    [$controller, $authService] = makeToolController([TestTool::class]);
    $userId = $authService->register('user@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'user@example.com');

    $response = $controller->index(jsonRequest('GET', '/api/v1/tools'));

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['tools'])->toHaveCount(1);

    $tool = $body['data']['tools'][0];
    expect($tool['tool_class'])->toBe(TestTool::class);
    expect($tool['tool_name'])->toBe('test_tool');
    expect($tool['settings_schema'])->toHaveCount(2);
});

test('index returns correct schema field structure', function (): void {
    clearSession();
    [$controller, $authService] = makeToolController([TestTool::class]);
    $userId = $authService->register('user@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'user@example.com');

    $response = $controller->index(jsonRequest('GET', '/api/v1/tools'));
    $body     = json_decode($response->getContent(), true);
    $schema   = $body['data']['tools'][0]['settings_schema'];

    $apiKeyField = collect($schema)->firstWhere('key', 'api_key');
    expect($apiKeyField['type'])->toBe('password');
    expect($apiKeyField['scope'])->toBe('agent');
    expect($apiKeyField['label'])->toBe('API Key');

    $maxResultsField = collect($schema)->firstWhere('key', 'max_results');
    expect($maxResultsField['type'])->toBe('text');
    expect($maxResultsField['scope'])->toBe('global');
});

test('index returns empty tools list when no classes registered', function (): void {
    clearSession();
    [$controller, $authService] = makeToolController([]);
    $userId = $authService->register('user@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'user@example.com');

    $response = $controller->index(jsonRequest('GET', '/api/v1/tools'));
    $body     = json_decode($response->getContent(), true);

    expect($body['data']['tools'])->toBe([]);
});

// ---------------------------------------------------------------------------
// getSettings
// ---------------------------------------------------------------------------

test('getSettings returns empty array when no settings saved yet', function (): void {
    clearSession();
    [$controller, $authService] = makeToolController([TestTool::class]);
    $userId = $authService->register('user@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'user@example.com');

    $request = jsonRequest('GET', '/api/v1/tools/test_tool/settings');
    $request->attributes->set('toolId', 'test_tool');
    $response = $controller->getSettings($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings'])->toBe([]);
});

test('getSettings returns masked password after putSettings', function (): void {
    clearSession();
    [$controller, $authService] = makeToolController([TestTool::class]);
    $userId = $authService->register('user@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'user@example.com');

    $putRequest = jsonRequest('PUT', '/api/v1/tools/test_tool/settings', ['api_key' => 'my-secret']);
    $putRequest->attributes->set('toolId', 'test_tool');
    $controller->putSettings($putRequest);

    $getRequest = jsonRequest('GET', '/api/v1/tools/test_tool/settings');
    $getRequest->attributes->set('toolId', 'test_tool');
    $response = $controller->getSettings($getRequest);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings']['api_key'])->toBe('***');
});

// ---------------------------------------------------------------------------
// putSettings
// ---------------------------------------------------------------------------

test('putSettings saves settings and returns masked result', function (): void {
    clearSession();
    [$controller, $authService, $toolConfig] = makeToolController([TestTool::class]);
    $userId = $authService->register('user@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'user@example.com');

    $request = jsonRequest('PUT', '/api/v1/tools/test_tool/settings', [
        'api_key'     => 'secret-value',
        'max_results' => '25',
    ]);
    $request->attributes->set('toolId', 'test_tool');
    $response = $controller->putSettings($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    // password masked in response
    expect($body['data']['settings']['api_key'])->toBe('***');
    // plain text field visible
    expect($body['data']['settings']['max_results'])->toBe('25');

    // Verify plaintext value is actually decryptable
    $saved = $toolConfig->getGlobalSettings(TestTool::class);
    expect($saved['api_key'])->toBe('secret-value');
    expect($saved['max_results'])->toBe('25');
});

test('putSettings accepts settings nested under a settings key', function (): void {
    clearSession();
    [$controller, $authService] = makeToolController([TestTool::class]);
    $userId = $authService->register('user@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'user@example.com');

    $request = jsonRequest('PUT', '/api/v1/tools/test_tool/settings', [
        'settings' => ['max_results' => '5'],
    ]);
    $request->attributes->set('toolId', 'test_tool');
    $response = $controller->putSettings($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings']['max_results'])->toBe('5');
});

test('getSettings resolves tool by name for real tool classes', function (): void {
    clearSession();
    [$controller, $authService] = makeToolController([Spora\Tools\ReadUrlTool::class]);
    $userId = $authService->register('user@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'user@example.com');

    $request = jsonRequest('GET', '/api/v1/tools/read_url/settings');
    $request->attributes->set('toolId', 'read_url');
    $response = $controller->getSettings($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings'])->toBe([]);
});

test('getSettings returns 404 for unknown tool name', function (): void {
    clearSession();
    [$controller, $authService] = makeToolController([Spora\Tools\ReadUrlTool::class]);
    $userId = $authService->register('user@example.com', 'Password1!');
    simulateLoggedInSession($userId, 'user@example.com');

    $request = jsonRequest('GET', '/api/v1/tools/nonexistent_tool/settings');
    $request->attributes->set('toolId', 'nonexistent_tool');
    $response = $controller->getSettings($request);

    expect($response->getStatusCode())->toBe(404);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_FOUND');
});
