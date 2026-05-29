<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\AgentController;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Models\LLMDriverConfiguration;
use Spora\Security\CsrfTokenService;
use Spora\Services\AgentService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Symfony\Component\HttpFoundation\Request;
use Tests\Fixtures\TestTool;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Boot a fresh in-memory DB and return AgentController + AuthService + supporting services.
 */
function makeAgentController(): array
{
    $authService = bootAuthLayer();

    $key        = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security   = new SecurityManager($key);
    $logger     = new Monolog\Logger('test');
    $toolConfig = new ToolConfigService($security, $logger, [TestTool::class]);
    $llmConfig  = new LLMConfigService($security, [OpenAICompatibleDriver::class, AnthropicCompatibleDriver::class]);
    $agentService = new AgentService($toolConfig, $llmConfig);
    $controller = new AgentController($authService, $agentService, $toolConfig);
    $authMiddleware = new AuthMiddleware($authService);
    $csrfService = new CsrfTokenService();
    $csrfMiddleware = new CsrfMiddleware($csrfService);

    return [$controller, $authService, $toolConfig, $llmConfig, $authMiddleware, $csrfMiddleware];
}

/**
 * Register a user and simulate their session. Returns the user ID.
 */
function registerUser(AuthService $authService, string $email = 'user@example.com', string $password = 'Password1!', string $displayName = 'Test User'): int
{
    $userId = $authService->register($email, $password, $displayName);
    simulateLoggedInSession($userId, $email);

    return $userId;
}

/**
 * Create an agent via the controller's store() and return the agent ID.
 */
function createAgent(AgentController $controller, string $name = 'My Assistant', array $middleware = []): int
{
    $response = callController($controller, 'store', jsonRequest('POST', '/api/v1/agents', ['name' => $name]), $middleware);
    $body = json_decode($response->getContent(), true);

    return (int) $body['data']['agent']['id'];
}

/**
 * Build a JSON request with the agent ID set in route attributes.
 */
function agentJsonRequest(string $method, string $path, array $body = [], int $agentId = 1): Request
{
    $request = jsonRequest($method, $path, $body);
    $request->attributes->set('id', $agentId);

    return $request;
}

// ---------------------------------------------------------------------------
// Auth guard
// ---------------------------------------------------------------------------

test('unauthenticated request throws UnauthenticatedException', function (): void {
    clearSession();
    [$controller, , , , $authMiddleware] = makeAgentController();

    expect(fn() => callController($controller, 'index', jsonRequest('GET', '/api/v1/agents'), [$authMiddleware]))
        ->toThrow(UnauthenticatedException::class);
});

// ---------------------------------------------------------------------------
// index / store
// ---------------------------------------------------------------------------

test('index returns empty array when no agents exist', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);

    $response = callController($controller, 'index', jsonRequest('GET', '/api/v1/agents'), [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agents'])->toBe([]);
});

test('index returns all agents for the current user', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    createAgent($controller, 'Agent A', [$authMiddleware]);
    createAgent($controller, 'Agent B', [$authMiddleware]);

    $response = callController($controller, 'index', jsonRequest('GET', '/api/v1/agents'), [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agents'])->toHaveCount(2);
});

test('store creates a new agent and returns 201', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);

    $response = callController(
        $controller,
        'store',
        jsonRequest('POST', '/api/v1/agents', ['name' => 'Research Bot']),
        [$authMiddleware],
    );

    expect($response->getStatusCode())->toBe(201);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agent']['name'])->toBe('Research Bot');
    expect($body['data']['agent'])->toHaveKey('id');
});

test('store requires a name', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);

    $response = callController(
        $controller,
        'store',
        jsonRequest('POST', '/api/v1/agents', ['name' => '']),
        [$authMiddleware],
    );

    expect($response->getStatusCode())->toBe(422);
});

// ---------------------------------------------------------------------------
// show / update / destroy
// ---------------------------------------------------------------------------

test('show returns the agent by id', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Bot', [$authMiddleware]);

    $response = callController($controller, 'show', agentJsonRequest('GET', '/api/v1/agents/' . $agentId, [], $agentId), [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agent']['name'])->toBe('My Bot');
});

test('show returns 404 for another user\'s agent', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Bot', [$authMiddleware]);

    // Register and switch to a different user
    registerUser($authService, 'other@example.com');
    $response = callController($controller, 'show', agentJsonRequest('GET', '/api/v1/agents/' . $agentId, [], $agentId), [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
});

test('show includes tools in the agent resource', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Bot', [$authMiddleware]);

    $request = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $request, [$authMiddleware]);

    $response = callController($controller, 'show', agentJsonRequest('GET', '/api/v1/agents/' . $agentId, [], $agentId), [$authMiddleware]);
    $body = json_decode($response->getContent(), true);

    expect($body['data']['agent']['tools'])->toHaveCount(1);
    expect($body['data']['agent']['tools'][0]['tool_class'])->toBe(TestTool::class);
});

test('update patches allowed agent fields', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Bot', [$authMiddleware]);

    $response = callController(
        $controller,
        'update',
        agentJsonRequest('PATCH', '/api/v1/agents/' . $agentId, [
            'name' => 'My Bot',
            'description' => 'An updated description',
        ], $agentId),
        [$authMiddleware],
    );

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agent']['name'])->toBe('My Bot');
    expect($body['data']['agent']['description'])->toBe('An updated description');
});

test('update ignores unknown fields', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Bot', [$authMiddleware]);

    $response = callController(
        $controller,
        'update',
        agentJsonRequest('PATCH', '/api/v1/agents/' . $agentId, [
            'user_id' => 999,
            'name' => 'Safe Name',
        ], $agentId),
        [$authMiddleware],
    );

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agent']['name'])->toBe('Safe Name');
    // user_id must not have changed
    $row = Capsule::table('agents')->first();
    expect((int) $row->user_id)->not()->toBe(999);
});

test('update returns 404 for non-existent agent', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);

    $response = callController(
        $controller,
        'update',
        agentJsonRequest('PATCH', '/api/v1/agents/99999', ['name' => 'Ghost'], 99999),
        [$authMiddleware],
    );

    expect($response->getStatusCode())->toBe(404);
});

test('destroy removes the agent and returns 204', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $response = callController(
        $controller,
        'destroy',
        agentJsonRequest('DELETE', '/api/v1/agents/' . $agentId, [], $agentId),
        [$authMiddleware],
    );

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);
    expect(Capsule::table('agents')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// enableTool / disableTool
// ---------------------------------------------------------------------------

test('enableTool inserts an AgentTool row and returns 201', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $request = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($controller, 'enableTool', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(201);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['tool']['tool_class'])->toBe(TestTool::class);
    // InputToolInterface tools default to auto_approve = true (read-only, safe to auto-run)
    expect($body['data']['tool']['auto_approve'])->toBe(true);
});

test('enableTool is idempotent: second call returns 200 without duplicating', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $request = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    callController($controller, 'enableTool', $request, [$authMiddleware]);
    $response = callController($controller, 'enableTool', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    expect(Capsule::table('agent_tools')->count())->toBe(1);
});

test('disableTool removes the AgentTool row and returns 204', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    $disableReq = jsonRequest('DELETE', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $disableReq->attributes->set('id', $agentId);
    $disableReq->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'disableTool', $disableReq, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);
    expect(Capsule::table('agent_tools')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// patchTool
// ---------------------------------------------------------------------------

test('patchTool sets auto_approve to true', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    $patchReq = jsonRequest('PATCH', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool'), ['auto_approve' => true]);
    $patchReq->attributes->set('id', $agentId);
    $patchReq->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'patchTool', $patchReq, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['tool']['auto_approve'])->toBeTrue();
});

test('patchTool sets auto_approve back to null', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    $patchReq = jsonRequest('PATCH', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool'), ['auto_approve' => true]);
    $patchReq->attributes->set('id', $agentId);
    $patchReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'patchTool', $patchReq, [$authMiddleware]);

    $patchReq2 = jsonRequest('PATCH', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool'), ['auto_approve' => null]);
    $patchReq2->attributes->set('id', $agentId);
    $patchReq2->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'patchTool', $patchReq2, [$authMiddleware]);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['tool']['auto_approve'])->toBeNull();
});

test('patchTool on non-enabled tool returns 404', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $request = jsonRequest('PATCH', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool'), ['auto_approve' => true]);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'patchTool', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
});

// ---------------------------------------------------------------------------
// getOverride / putOverride / deleteOverride
// ---------------------------------------------------------------------------

test('getOverride returns empty settings when no override set', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    // Schema default (max_results = '10') is seeded when no global/override exists
    expect($body['data']['settings']['max_results']['value'])->toBe('10');
    expect($body['data']['settings']['max_results']['source'])->toBe('default');
});

test('getOverride for llm_configuration falls back to the user default LLMDriverConfiguration', function (): void {
    clearSession();
    [$controller, $authService, , $llmConfig, $authMiddleware] = makeAgentController();
    $userId = registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Create a default LLMDriverConfiguration for the same user
    $config = new LLMDriverConfiguration();
    $config->user_id = $userId;
    $config->name = 'Test Default';
    $config->driver_class = OpenAICompatibleDriver::class;
    $config->settings = json_encode($llmConfig->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-test-secret',
        'model' => 'gpt-4o',
    ]));
    $config->is_default = true;
    $config->save();

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('llm_configuration') . '/override');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'llm_configuration');
    $response = callController($controller, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    // api_key is password → masked; model is returned as-is
    expect($body['data']['settings']['api_key'])->toBe('***');
    expect($body['data']['settings']['model'])->toBe('gpt-4o');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

// ---------------------------------------------------------------------------
// Fix: getOverride llm_configuration gracefully handles corrupted settings
// ---------------------------------------------------------------------------

test('getOverride for llm_configuration returns empty settings when decryption fails', function (): void {
    clearSession();
    [$controller, $authService, , $llmConfig, $authMiddleware] = makeAgentController();
    $userId = registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Create a config encoded with a DIFFERENT key than the one the controller uses.
    // With per-field encryption: non-password fields (model, base_url) decrypt fine,
    // but api_key was encrypted with the alien key so it becomes null after decryption.
    $alienKey      = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $alienSecurity = new SecurityManager($alienKey);
    $alienService  = new LLMConfigService($alienSecurity, [OpenAICompatibleDriver::class]);

    $config             = new LLMDriverConfiguration();
    $config->user_id    = $userId;
    $config->name       = 'Corrupted Config';
    $config->driver_class = OpenAICompatibleDriver::class;
    $config->settings   = json_encode($alienService->encodeSettings(OpenAICompatibleDriver::class, ['api_key' => 'secret', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com/v1']));
    $config->is_default = true;
    $config->save();

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/llm_configuration/override');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'llm_configuration');

    // Must return 200 with empty settings — NOT a 500
    $response = callController($controller, 'getOverride', $request, [$authMiddleware]);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode($response->getContent(), true);
    // api_key (password) fails to decrypt with wrong key → becomes null → masked as '***'
    // model and base_url are non-password fields and are stored as plain JSON → returned as-is
    expect($body['data']['settings']['api_key'])->toBe('***');
    expect($body['data']['settings']['model'])->toBe('gpt-4o');
    expect($body['data']['settings']['base_url'])->toBe('https://api.openai.com/v1');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});

test('putOverride saves agent-scoped settings and masks passwords', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Enable the tool first (override requires the tool to be assigned)
    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    $request = jsonRequest('PUT', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override', ['api_key' => 'secret-key']);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'putOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings']['api_key'])->toBe('***');
});

test('putOverride discards global-scoped keys', function (): void {
    clearSession();
    [$controller, $authService, $toolConfig, , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Enable the tool first (override requires the tool to be assigned)
    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    $request = jsonRequest('PUT', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override', [
        'api_key'     => 'secret',   // scope: agent → stored
        'max_results' => '10',        // scope: global → discarded
    ]);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    callController($controller, 'putOverride', $request, [$authMiddleware]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    // max_results is global-scoped — not stored as override, but schema default applies
    expect($effective['max_results'])->toBe('10'); // from schema default, not override
    // api_key was stored by override (scope: agent)
    expect($effective['api_key'])->toBe('secret');
});

test('deleteOverride removes the override and returns 204', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Enable the tool first (override requires the tool to be assigned)
    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    $putReq = jsonRequest('PUT', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override', ['api_key' => 'secret-key']);
    $putReq->attributes->set('id', $agentId);
    $putReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'putOverride', $putReq, [$authMiddleware]);

    $delReq = jsonRequest('DELETE', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override');
    $delReq->attributes->set('id', $agentId);
    $delReq->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'deleteOverride', $delReq, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);

    // Override row should be gone
    $getReq = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override');
    $getReq->attributes->set('id', $agentId);
    $getReq->attributes->set('toolId', 'test_tool');
    $body = json_decode(callController($controller, 'getOverride', $getReq, [$authMiddleware])->getContent(), true);
    // Schema default (max_results = '10') still applies from getEffectiveSettingsWithSource
    expect($body['data']['settings']['max_results']['value'])->toBe('10');
    expect($body['data']['settings']['max_results']['source'])->toBe('default');
});

test('putOverride saves agent-scoped settings even when tool is not enabled', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Override is now allowed even without enabling the tool first
    $request = jsonRequest('PUT', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override', ['api_key' => 'secret-key']);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'putOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings']['api_key'])->toBe('***');
});

test('deleteOverride succeeds even when tool is not enabled', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // First save an override (no enable needed)
    $putReq = jsonRequest('PUT', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override', ['api_key' => 'secret-key']);
    $putReq->attributes->set('id', $agentId);
    $putReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'putOverride', $putReq, [$authMiddleware]);

    // Now delete without enabling first
    $delReq = jsonRequest('DELETE', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override');
    $delReq->attributes->set('id', $agentId);
    $delReq->attributes->set('toolId', 'test_tool');
    $response = callController($controller, 'deleteOverride', $delReq, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);
});

// ---------------------------------------------------------------------------
// getToolStatus
// ---------------------------------------------------------------------------

test('getToolStatus returns is_enabled false and missing_required when tool not enabled', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/status');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($controller, 'getToolStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['is_enabled'])->toBe(false);
    // TestTool has no required settings, so missing_required should be empty
    expect($body['data']['missing_required'])->toBe([]);
    expect($body['data']['can_enable'])->toBe(true);
});

test('getToolStatus returns is_enabled true when tool is enabled', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/status');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($controller, 'getToolStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['is_enabled'])->toBe(true);
});

test('getToolStatus returns 404 for non-existent agent', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);

    $request = jsonRequest('GET', '/api/v1/agents/99999/tools/' . urlencode('test_tool') . '/status');
    $request->attributes->set('id', 99999);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($controller, 'getToolStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
});

// ---------------------------------------------------------------------------
// getToolsStatus (batch)
// ---------------------------------------------------------------------------

test('getToolsStatus returns all registered tools with correct is_enabled and missing_required', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Enable one tool
    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/status');
    $request->attributes->set('id', $agentId);

    $response = callController($controller, 'getToolsStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['statuses'])->toBeArray();

    // Find the test_tool entry
    $testToolStatus = collect($body['data']['statuses'])
        ->first(fn($s) => $s['tool_class'] === TestTool::class);
    expect($testToolStatus['is_enabled'])->toBe(true);
    expect($testToolStatus['can_enable'])->toBe(true);
});

test('getToolsStatus returns 404 for non-existent agent', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);

    $request = jsonRequest('GET', '/api/v1/agents/99999/tools/status');
    $request->attributes->set('id', 99999);

    $response = callController($controller, 'getToolsStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
});

test('getToolsStatus is_enabled is keyed by tool_class, not tool_name — same tool_name with different tool_class gives independent enabled state', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Directly insert two agent_tool records with the SAME tool_name but DIFFERENT tool_class.
    // This simulates the orphaned MemoryTool scenario where a stale tool_class remains in the DB.
    // Both have tool_name = 'test_tool' (matching TestTool's registered name).
    Capsule::table('agent_tools')->insert([
        'agent_id'   => $agentId,
        'tool_class' => 'Tests\Fixtures\StubOutputTool',
        'tool_name'  => 'test_tool', // same tool_name as TestTool
        'auto_approve' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/status');
    $request->attributes->set('id', $agentId);

    $response = callController($controller, 'getToolsStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);

    // TestTool should still be is_enabled=false (never enabled via enableTool)
    // StubOutputTool has the same tool_name but a different tool_class — is_enabled depends on tool_class
    $testToolStatus = collect($body['data']['statuses'])
        ->first(fn($s) => $s['tool_class'] === TestTool::class);
    expect($testToolStatus['is_enabled'])->toBe(false); // not explicitly enabled
});

// ---------------------------------------------------------------------------
// enableTool enhanced response with missing_required
// ---------------------------------------------------------------------------

test('enableTool returns warning and missing_required when settings are incomplete', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $request = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($controller, 'enableTool', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(201);
    $body = json_decode($response->getContent(), true);
    // TestTool has no required fields so no warning expected
    expect($body['data']['tool']['tool_class'])->toBe(TestTool::class);
    expect($body['data'])->not()->toHaveKey('warning');
});

test('enableTool is idempotent: no warning on already-enabled tool', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]); // First call

    $response = callController($controller, 'enableTool', $enableReq, [$authMiddleware]); // Second call (idempotent)

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data'])->not()->toHaveKey('warning');
});

// ---------------------------------------------------------------------------
// getOverride with ?raw=true
// ---------------------------------------------------------------------------

test('getOverride with raw=true returns only stored agent override keys (passwords masked)', function (): void {
    clearSession();
    [$controller, $authService, $toolConfig, , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Enable the tool first
    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    // Set global settings and agent override
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key'     => 'global-key',
        'max_results' => '20',
    ]);
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-key', // scope: agent, stored; type: password → masked by maskForApi
    ]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override?raw=true');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($controller, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    // api_key is type: password → maskForApi masks it to ***
    expect($body['data']['settings']['api_key'])->toBe('***');
    expect(array_key_exists('max_results', $body['data']['settings']))->toBe(false);
});

test('getOverride with raw=true returns empty when no override exists', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override?raw=true');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($controller, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings'])->toBe([]);
});

test('getOverride returns effective settings with source annotation (without raw param)', function (): void {
    clearSession();
    [$controller, $authService, $toolConfig, , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Enable the tool
    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    // Set global settings and agent override
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key'     => 'global-key',
        'max_results' => '20',
    ]);
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-key',
    ]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/override');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($controller, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    $settings = $body['data']['settings'];

    // api_key is overridden by agent, but is type: password → value is masked
    expect($settings['api_key']['value'])->toBe('***');
    expect($settings['api_key']['source'])->toBe('agent');
    // max_results is from global (not overridden, scope: global)
    expect($settings['max_results']['value'])->toBe('20');
    expect($settings['max_results']['source'])->toBe('global');
});

// ---------------------------------------------------------------------------
// getToolsOperations (batch)
// ---------------------------------------------------------------------------

test('getToolsOperations returns all operations for enabled tools with operations', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    // Enable TestTool (it has one operation: "default")
    $enableReq = jsonRequest('POST', '/api/v1/agents/' . $agentId . '/tools/' . urlencode('test_tool') . '/enable');
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($controller, 'enableTool', $enableReq, [$authMiddleware]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/operations');
    $request->attributes->set('id', $agentId);

    $response = callController($controller, 'getToolsOperations', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['operations'])->toBeArray();

    // TestTool has operation "default"
    $defaultOp = collect($body['data']['operations'])
        ->first(fn($s) => $s['tool_class'] === TestTool::class && $s['operation'] === 'default');
    expect($defaultOp['effective_enabled'])->toBe(true); // enabledByDefault: true
    expect($defaultOp['effective_requires_approval'])->toBe(false); // requiresApprovalByDefault: false
});

test('getToolsOperations returns empty operations array when no tools are enabled', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, 'My Agent', [$authMiddleware]);

    $request = jsonRequest('GET', '/api/v1/agents/' . $agentId . '/tools/operations');
    $request->attributes->set('id', $agentId);

    $response = callController($controller, 'getToolsOperations', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['operations'])->toBe([]);
});

test('getToolsOperations returns 404 for non-existent agent', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);

    $request = jsonRequest('GET', '/api/v1/agents/99999/tools/operations');
    $request->attributes->set('id', 99999);

    $response = callController($controller, 'getToolsOperations', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
});
