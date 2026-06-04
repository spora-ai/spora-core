<?php

declare(strict_types=1);

use Spora\Core\SecurityManager;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Http\ToolController;
use Spora\Security\CsrfTokenService;
use Spora\Services\ToolConfigService;
use Tests\Fixtures\TestTool;

const TC_API_TOOLS = '/api/v1/tools';
const TC_TOOL_SETTINGS = '/api/v1/tools/test_tool/settings';
const TC_USER_EMAIL = 'user@example.com';
const TC_USER_NAME = 'Test User';
const TC_USER_PASSWORD = 'Password1!';

// Helpers

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
    $authMiddleware = new AuthMiddleware($authService);
    $csrfService = new CsrfTokenService();
    $csrfMiddleware = new CsrfMiddleware($csrfService);

    return [$controller, $authService, $toolConfig, $authMiddleware, $csrfMiddleware, $csrfService];
}

// Auth guard

test('unauthenticated request throws UnauthenticatedException', function (): void {
    clearSession();
    [$controller, , , $authMiddleware, $csrfMiddleware] = makeToolController([TestTool::class]);

    expect(fn() => callController($controller, 'index', jsonRequest('GET', TC_API_TOOLS), [$authMiddleware, $csrfMiddleware]))
        ->toThrow(UnauthenticatedException::class);
});

// index

test('index returns schema for registered tool classes', function (): void {
    clearSession();
    [$controller, $authService, , $authMiddleware, $csrfMiddleware] = makeToolController([TestTool::class]);
    $userId = $authService->register(TC_USER_EMAIL, TC_USER_PASSWORD, TC_USER_NAME);
    simulateLoggedInSession($userId, TC_USER_EMAIL);

    $response = callController($controller, 'index', jsonRequest('GET', TC_API_TOOLS), [$authMiddleware, $csrfMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['tools'])->toHaveCount(1);

    $tool = $body['data']['tools'][0];
    expect($tool['tool_class'])->toBe(TestTool::class);
    expect($tool['tool_name'])->toBe('test_tool');
    expect($tool['settings_schema'])->toHaveCount(3);
});

test('index returns correct schema field structure', function (): void {
    clearSession();
    [$controller, $authService, , $authMiddleware, $csrfMiddleware] = makeToolController([TestTool::class]);
    $userId = $authService->register(TC_USER_EMAIL, TC_USER_PASSWORD, TC_USER_NAME);
    simulateLoggedInSession($userId, TC_USER_EMAIL);

    $response = callController($controller, 'index', jsonRequest('GET', TC_API_TOOLS), [$authMiddleware, $csrfMiddleware]);
    $body     = json_decode($response->getContent(), true);
    $schema   = $body['data']['tools'][0]['settings_schema'];

    $apiKeyField = collect($schema)->firstWhere('key', 'api_key');
    expect($apiKeyField['type'])->toBe('password');
    expect($apiKeyField['label'])->toBe('API Key');

    $maxResultsField = collect($schema)->firstWhere('key', 'max_results');
    expect($maxResultsField['type'])->toBe('text');
});

test('index returns empty tools list when no classes registered', function (): void {
    clearSession();
    [$controller, $authService, , $authMiddleware, $csrfMiddleware] = makeToolController([]);
    $userId = $authService->register(TC_USER_EMAIL, TC_USER_PASSWORD, TC_USER_NAME);
    simulateLoggedInSession($userId, TC_USER_EMAIL);

    $response = callController($controller, 'index', jsonRequest('GET', TC_API_TOOLS), [$authMiddleware, $csrfMiddleware]);
    $body     = json_decode($response->getContent(), true);

    expect($body['data']['tools'])->toBe([]);
});

// getSettings

test('getSettings returns empty array when no settings saved yet', function (): void {
    clearSession();
    [$controller, $authService, , $authMiddleware, $csrfMiddleware] = makeToolController([TestTool::class]);
    $userId = $authService->register(TC_USER_EMAIL, TC_USER_PASSWORD, TC_USER_NAME);
    simulateLoggedInSession($userId, TC_USER_EMAIL);

    $request = jsonRequest('GET', TC_TOOL_SETTINGS);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'getSettings', $request, [$authMiddleware, $csrfMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings'])->toBe([]);
});

test('getSettings returns masked password after putSettings', function (): void {
    clearSession();
    [$controller, $authService, , $authMiddleware, $csrfMiddleware, $csrfService] = makeToolController([TestTool::class]);
    $userId = $authService->register(TC_USER_EMAIL, TC_USER_PASSWORD, TC_USER_NAME);
    simulateLoggedInSession($userId, TC_USER_EMAIL);
    $csrfService->regenerate();

    $putRequest = jsonRequest('PUT', TC_TOOL_SETTINGS, ['api_key' => 'my-secret']);
    $putRequest->attributes->set('toolId', 'test_tool');
    callController($controller, 'putSettings', $putRequest, [$authMiddleware, $csrfMiddleware]);

    $getRequest = jsonRequest('GET', TC_TOOL_SETTINGS);
    $getRequest->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'getSettings', $getRequest, [$authMiddleware, $csrfMiddleware]);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings']['api_key'])->toBe('***');
});

// putSettings

test('putSettings saves settings and returns masked result', function (): void {
    clearSession();
    [$controller, $authService, $toolConfig, $authMiddleware, $csrfMiddleware, $csrfService] = makeToolController([TestTool::class]);
    $userId = $authService->register(TC_USER_EMAIL, TC_USER_PASSWORD, TC_USER_NAME);
    simulateLoggedInSession($userId, TC_USER_EMAIL);
    $csrfService->regenerate();

    $request = jsonRequest('PUT', TC_TOOL_SETTINGS, [
        'api_key'     => 'secret-value',
        'max_results' => '25',
    ]);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'putSettings', $request, [$authMiddleware, $csrfMiddleware]);

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
    [$controller, $authService, , $authMiddleware, $csrfMiddleware, $csrfService] = makeToolController([TestTool::class]);
    $userId = $authService->register(TC_USER_EMAIL, TC_USER_PASSWORD, TC_USER_NAME);
    simulateLoggedInSession($userId, TC_USER_EMAIL);
    $csrfService->regenerate();

    $request = jsonRequest('PUT', TC_TOOL_SETTINGS, [
        'settings' => ['max_results' => '5'],
    ]);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'putSettings', $request, [$authMiddleware, $csrfMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings']['max_results'])->toBe('5');
});

test('getSettings resolves tool by name for real tool classes', function (): void {
    clearSession();
    [$controller, $authService, , $authMiddleware, $csrfMiddleware] = makeToolController([Spora\Tools\ReadUrlTool::class]);
    $userId = $authService->register(TC_USER_EMAIL, TC_USER_PASSWORD, TC_USER_NAME);
    simulateLoggedInSession($userId, TC_USER_EMAIL);

    $request = jsonRequest('GET', '/api/v1/tools/read_url/settings');
    $request->attributes->set('toolId', 'read_url');
    $response = callController($controller, 'getSettings', $request, [$authMiddleware, $csrfMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings'])->toBe([]);
});

test('getSettings returns 404 for unknown tool name', function (): void {
    clearSession();
    [$controller, $authService, , $authMiddleware, $csrfMiddleware] = makeToolController([Spora\Tools\ReadUrlTool::class]);
    $userId = $authService->register(TC_USER_EMAIL, TC_USER_PASSWORD, TC_USER_NAME);
    simulateLoggedInSession($userId, TC_USER_EMAIL);

    $request = jsonRequest('GET', '/api/v1/tools/nonexistent_tool/settings');
    $request->attributes->set('toolId', 'nonexistent_tool');
    $response = callController($controller, 'getSettings', $request, [$authMiddleware, $csrfMiddleware]);

    expect($response->getStatusCode())->toBe(404);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('NOT_FOUND');
});
