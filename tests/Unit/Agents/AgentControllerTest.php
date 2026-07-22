<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\AgentController;
use Spora\Http\AgentOverrideController;
use Spora\Http\AgentToolController;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\UserPreference;
use Spora\Security\CsrfTokenService;
use Spora\Services\AgentService;
use Spora\Services\AgentToolSettingsService;
use Spora\Services\LLMConfigService;
use Spora\Services\ToolConfigService;
use Symfony\Component\HttpFoundation\Request;
use Tests\Fixtures\TestTool;

const AGENTS_API_PATH = '/api/v1/agents';
const AGENT_NAME_MY_BOT = 'My Bot';
const AGENT_NAME_MY_AGENT = 'My Agent';
const AGENT_PATH_PREFIX = '/api/v1/agents/';
const TOOL_PATH_SEGMENT = '/tools/';
const TOOL_PATH_ENABLE = '/enable';
const TOOL_PATH_OVERRIDE = '/override';
const TOOL_PATH_STATUS = '/status';

/**
 * Boot a fresh in-memory DB and return all three agent controllers + supporting services.
 *
 * Returned tuple indices (kept stable for easy destructuring):
 *   0  AgentController        (CRUD)
 *   1  AgentToolController    (tool enablement / status / operations)
 *   2  AgentOverrideController (per-agent overrides)
 *   3  AuthService
 *   4  ToolConfigService
 *   5  LLMConfigService
 *   6  AuthMiddleware
 *   7  CsrfMiddleware
 */
function makeAgentControllers(): array
{
    $authService = bootAuthLayer();

    $key        = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security   = new SecurityManager($key);
    $logger     = new Monolog\Logger('test');
    $toolConfig = new ToolConfigService($security, $logger, [TestTool::class]);
    $llmConfig  = new LLMConfigService($security, [OpenAICompatibleDriver::class, AnthropicCompatibleDriver::class]);
    $agentService = new AgentService();
    // Tool enablement / overrides / operations moved to AgentToolSettingsService
    // when AgentService was split to satisfy SonarCloud S1448.
    $toolSettings = new AgentToolSettingsService($toolConfig, $llmConfig);
    $crudController      = new AgentController($authService, $agentService);
    $toolController      = new AgentToolController($authService, $toolSettings, $toolConfig);
    $overrideController  = new AgentOverrideController($authService, $toolSettings, $toolConfig);
    $authMiddleware = new AuthMiddleware($authService);
    $csrfService = new CsrfTokenService();
    $csrfMiddleware = new CsrfMiddleware($csrfService);

    return [
        $crudController,
        $toolController,
        $overrideController,
        $authService,
        $toolConfig,
        $llmConfig,
        $authMiddleware,
        $csrfMiddleware,
    ];
}

/**
 * Backward-compatible alias used by the CRUD-only test cases.
 *
 * @return array{0: AgentController, 1: AuthService, 2: ToolConfigService, 3: LLMConfigService, 4: AuthMiddleware}
 */
function makeAgentController(): array
{
    $controllers = makeAgentControllers();

    return [
        $controllers[0],
        $controllers[3],
        $controllers[4],
        $controllers[5],
        $controllers[6],
    ];
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
 * Create an agent via the CRUD controller's store() and return the agent ID.
 */
function createAgent(AgentController $controller, string $name = 'My Assistant', array $middleware = []): int
{
    $response = callController($controller, 'store', jsonRequest('POST', AGENTS_API_PATH, ['name' => $name]), $middleware);
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


test('unauthenticated request throws UnauthenticatedException', function (): void {
    clearSession();
    [$controller, , , , $authMiddleware] = makeAgentController();

    expect(fn() => callController($controller, 'index', jsonRequest('GET', AGENTS_API_PATH), [$authMiddleware]))
        ->toThrow(UnauthenticatedException::class);
});


test('index returns empty array when no agents exist', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);

    $response = callController($controller, 'index', jsonRequest('GET', AGENTS_API_PATH), [$authMiddleware]);

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

    $response = callController($controller, 'index', jsonRequest('GET', AGENTS_API_PATH), [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agents'])->toHaveCount(2);
});

test('index with ?select=id,name returns only id and name columns', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    createAgent($controller, 'Selected Agent', [$authMiddleware]);

    $request = jsonRequest('GET', AGENTS_API_PATH . '?select=id,name');
    $response = callController($controller, 'index', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agents'])->toHaveCount(1);
    $row = (array) $body['data']['agents'][0];
    expect($row)->toHaveKey('id')
        ->and($row)->toHaveKey('name')
        // Full payload fields must NOT be present when ?select is supplied
        ->and($row)->not->toHaveKey('description')
        ->and($row)->not->toHaveKey('system_prompt')
        ->and($row)->not->toHaveKey('max_steps');
});

test('index without ?select returns the full payload (backward compat)', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    createAgent($controller, 'Full Payload Agent', [$authMiddleware]);

    $response = callController($controller, 'index', jsonRequest('GET', AGENTS_API_PATH), [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    $row = $body['data']['agents'][0];
    expect($row)->toHaveKey('id')
        ->and($row)->toHaveKey('name')
        ->and($row)->toHaveKey('description')
        ->and($row)->toHaveKey('max_steps')
        ->and($row)->toHaveKey('tools');
});

test('store creates a new agent and returns 201', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);

    $response = callController(
        $controller,
        'store',
        jsonRequest('POST', AGENTS_API_PATH, ['name' => 'Research Bot']),
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
        jsonRequest('POST', AGENTS_API_PATH, ['name' => '']),
        [$authMiddleware],
    );

    expect($response->getStatusCode())->toBe(422);
});


test('show returns the agent by id', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, AGENT_NAME_MY_BOT, [$authMiddleware]);

    $response = callController($controller, 'show', agentJsonRequest('GET', AGENT_PATH_PREFIX . $agentId, [], $agentId), [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agent']['name'])->toBe(AGENT_NAME_MY_BOT);
});

test('show returns 404 for another user\'s agent', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, AGENT_NAME_MY_BOT, [$authMiddleware]);

    // Register and switch to a different user
    registerUser($authService, 'other@example.com');
    $response = callController($controller, 'show', agentJsonRequest('GET', AGENT_PATH_PREFIX . $agentId, [], $agentId), [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
});

test('show includes tools in the agent resource', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_BOT, [$authMiddleware]);

    $request = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $request, [$authMiddleware]);

    $response = callController($crud, 'show', agentJsonRequest('GET', AGENT_PATH_PREFIX . $agentId, [], $agentId), [$authMiddleware]);
    $body = json_decode($response->getContent(), true);

    expect($body['data']['agent']['tools'])->toHaveCount(1);
    expect($body['data']['agent']['tools'][0]['tool_class'])->toBe(TestTool::class);
});

test('update patches allowed agent fields', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, AGENT_NAME_MY_BOT, [$authMiddleware]);

    $response = callController(
        $controller,
        'update',
        agentJsonRequest('PATCH', AGENT_PATH_PREFIX . $agentId, [
            'name' => AGENT_NAME_MY_BOT,
            'description' => 'An updated description',
        ], $agentId),
        [$authMiddleware],
    );

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agent']['name'])->toBe(AGENT_NAME_MY_BOT);
    expect($body['data']['agent']['description'])->toBe('An updated description');
});

test('update ignores unknown fields', function (): void {
    clearSession();
    [$controller, $authService, , , $authMiddleware] = makeAgentController();
    registerUser($authService);
    $agentId = createAgent($controller, AGENT_NAME_MY_BOT, [$authMiddleware]);

    $response = callController(
        $controller,
        'update',
        agentJsonRequest('PATCH', AGENT_PATH_PREFIX . $agentId, [
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
    $agentId = createAgent($controller, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $response = callController(
        $controller,
        'destroy',
        agentJsonRequest('DELETE', AGENT_PATH_PREFIX . $agentId, [], $agentId),
        [$authMiddleware],
    );

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);
    expect(Capsule::table('agents')->count())->toBe(0);
});


test('enableTool inserts an AgentTool row and returns 201', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $request = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($tool, 'enableTool', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(201);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['tool']['tool_class'])->toBe(TestTool::class);
});

test('enableTool is idempotent: second call returns 200 without duplicating', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $request = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    callController($tool, 'enableTool', $request, [$authMiddleware]);
    $response = callController($tool, 'enableTool', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    expect(Capsule::table('agent_tools')->count())->toBe(1);
});

test('disableTool removes the AgentTool row and returns 204', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $enableReq = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $enableReq, [$authMiddleware]);

    $disableReq = jsonRequest('DELETE', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $disableReq->attributes->set('id', $agentId);
    $disableReq->attributes->set('toolId', 'test_tool');
    $response = callController($tool, 'disableTool', $disableReq, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);
    expect(Capsule::table('agent_tools')->count())->toBe(0);
});


test('getOverride returns empty settings when no override set', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, , $override] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($override, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    // Schema default (max_results = '10') is seeded when no global/override exists
    expect($body['data']['settings']['max_results']['value'])->toBe('10');
    expect($body['data']['settings']['max_results']['source'])->toBe('default');
});

test('getOverride for llm_configuration falls back to the user default LLMDriverConfiguration', function (): void {
    clearSession();
    [$crud, $authService, , $llmConfig, $authMiddleware] = makeAgentController();
    [, , $override] = makeAgentControllers();
    $userId = registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // Create a default LLMDriverConfiguration for the same user
    $config = new LLMDriverConfiguration();
    $config->user_id = $userId;
    $config->name = 'Test Default';
    $config->driver_class = OpenAICompatibleDriver::class;
    $config->settings = json_encode($llmConfig->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-test-secret',
        'model' => 'gpt-4o',
    ]));
    $config->save();

    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $config->id,
    ]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('llm_configuration') . TOOL_PATH_OVERRIDE);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'llm_configuration');
    $response = callController($override, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    // api_key is password → masked; model is returned as-is
    expect($body['data']['settings']['api_key'])->toBe('***');
    expect($body['data']['settings']['model'])->toBe('gpt-4o');

    LLMDriverConfiguration::where('id', $config->id)->delete();
});


test('getOverride for llm_configuration returns empty settings when decryption fails', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, , $override] = makeAgentControllers();
    $userId = registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

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
    $config->save();

    UserPreference::create([
        'user_id' => $userId,
        'preferred_llm_config_id' => $config->id,
    ]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . '/tools/llm_configuration' . TOOL_PATH_OVERRIDE);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'llm_configuration');

    // Must return 200 with empty settings — NOT a 500
    $response = callController($override, 'getOverride', $request, [$authMiddleware]);
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
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool, $override] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // Enable the tool first (override requires the tool to be assigned)
    $enableReq = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $enableReq, [$authMiddleware]);

    $request = jsonRequest('PUT', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE, ['api_key' => 'secret-key']);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($override, 'putOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings']['api_key'])->toBe('***');
});

test('putOverride stores all keys (scope removed)', function (): void {
    clearSession();
    [$crud, $tool, $override, $authService, $toolConfig, , $authMiddleware] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // Enable the tool first (override requires the tool to be assigned)
    $enableReq = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $enableReq, [$authMiddleware]);

    $request = jsonRequest('PUT', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE, [
        'api_key'     => 'secret',
        'max_results' => '10',
    ]);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    callController($override, 'putOverride', $request, [$authMiddleware]);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    // All keys are now stored as overrides
    expect($effective['max_results'])->toBe('10');
    expect($effective['api_key'])->toBe('secret');
});

test('deleteOverride removes the override and returns 204', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool, $override] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // Enable the tool first (override requires the tool to be assigned)
    $enableReq = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $enableReq, [$authMiddleware]);

    $putReq = jsonRequest('PUT', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE, ['api_key' => 'secret-key']);
    $putReq->attributes->set('id', $agentId);
    $putReq->attributes->set('toolId', 'test_tool');
    callController($override, 'putOverride', $putReq, [$authMiddleware]);

    $delReq = jsonRequest('DELETE', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE);
    $delReq->attributes->set('id', $agentId);
    $delReq->attributes->set('toolId', 'test_tool');
    $response = callController($override, 'deleteOverride', $delReq, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);

    // Override row should be gone
    $getReq = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE);
    $getReq->attributes->set('id', $agentId);
    $getReq->attributes->set('toolId', 'test_tool');
    $body = json_decode(callController($override, 'getOverride', $getReq, [$authMiddleware])->getContent(), true);
    // Schema default (max_results = '10') still applies from getEffectiveSettingsWithSource
    expect($body['data']['settings']['max_results']['value'])->toBe('10');
    expect($body['data']['settings']['max_results']['source'])->toBe('default');
});

test('putOverride saves agent-scoped settings even when tool is not enabled', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, , $override] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // Override is now allowed even without enabling the tool first
    $request = jsonRequest('PUT', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE, ['api_key' => 'secret-key']);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');
    $response = callController($override, 'putOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings']['api_key'])->toBe('***');
});

test('deleteOverride succeeds even when tool is not enabled', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, , $override] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // First save an override (no enable needed)
    $putReq = jsonRequest('PUT', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE, ['api_key' => 'secret-key']);
    $putReq->attributes->set('id', $agentId);
    $putReq->attributes->set('toolId', 'test_tool');
    callController($override, 'putOverride', $putReq, [$authMiddleware]);

    // Now delete without enabling first
    $delReq = jsonRequest('DELETE', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE);
    $delReq->attributes->set('id', $agentId);
    $delReq->attributes->set('toolId', 'test_tool');
    $response = callController($override, 'deleteOverride', $delReq, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);
});


test('getToolStatus returns is_enabled false and missing_required when tool not enabled', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_STATUS);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($tool, 'getToolStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['is_enabled'])->toBe(false);
    // TestTool has no required settings, so missing_required should be empty
    expect($body['data']['missing_required'])->toBe([]);
    expect($body['data']['can_enable'])->toBe(true);
});

test('getToolStatus returns is_enabled true when tool is enabled', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $enableReq = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $enableReq, [$authMiddleware]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_STATUS);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($tool, 'getToolStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['is_enabled'])->toBe(true);
});

test('getToolStatus returns 404 for non-existent agent', function (): void {
    clearSession();
    [, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . '99999' . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_STATUS);
    $request->attributes->set('id', 99999);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($tool, 'getToolStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
});


test('getToolsStatus returns all registered tools with correct is_enabled and missing_required', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // Enable one tool
    $enableReq = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $enableReq, [$authMiddleware]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . '/tools' . TOOL_PATH_STATUS);
    $request->attributes->set('id', $agentId);

    $response = callController($tool, 'getToolsStatus', $request, [$authMiddleware]);

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
    [, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);

    $request = jsonRequest('GET', '/api/v1/agents/99999/tools/status');
    $request->attributes->set('id', 99999);

    $response = callController($tool, 'getToolsStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(404);
});

test('getToolsStatus is_enabled is keyed by tool_class, not tool_name — same tool_name with different tool_class gives independent enabled state', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // Directly insert two agent_tool records with the SAME tool_name but DIFFERENT tool_class.
    // This simulates the orphaned MemoryTool scenario where a stale tool_class remains in the DB.
    // Both have tool_name = 'test_tool' (matching TestTool's registered name).
    Capsule::table('agent_tools')->insert([
        'agent_id'   => $agentId,
        'tool_class' => 'Tests\Fixtures\StubOutputTool',
        'tool_name'  => 'test_tool', // same tool_name as TestTool
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . '/tools' . TOOL_PATH_STATUS);
    $request->attributes->set('id', $agentId);

    $response = callController($tool, 'getToolsStatus', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);

    // TestTool should still be is_enabled=false (never enabled via enableTool)
    // StubOutputTool has the same tool_name but a different tool_class — is_enabled depends on tool_class
    $testToolStatus = collect($body['data']['statuses'])
        ->first(fn($s) => $s['tool_class'] === TestTool::class);
    expect($testToolStatus['is_enabled'])->toBe(false); // not explicitly enabled
});


test('enableTool returns warning and missing_required when settings are incomplete', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $request = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($tool, 'enableTool', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(201);
    $body = json_decode($response->getContent(), true);
    // TestTool has no required fields so no warning expected
    expect($body['data']['tool']['tool_class'])->toBe(TestTool::class);
    expect($body['data'])->not()->toHaveKey('warning');
});

test('enableTool is idempotent: no warning on already-enabled tool', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, $tool] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $enableReq = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $enableReq, [$authMiddleware]); // First call

    $response = callController($tool, 'enableTool', $enableReq, [$authMiddleware]); // Second call (idempotent)

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data'])->not()->toHaveKey('warning');
});


test('getOverride with raw=true returns only stored agent override keys (passwords masked)', function (): void {
    clearSession();
    [$crud, $tool, $override, $authService, $toolConfig, , $authMiddleware] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // Enable the tool first
    $enableReq = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $enableReq, [$authMiddleware]);

    // Clear any prior override so we start fresh
    $toolConfig->deleteAgentOverride(TestTool::class, $agentId);

    // Set global settings and agent override
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key'     => 'global-key',
        'max_results' => '20',
    ]);
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-key', // stored; type: password → masked by maskForApi
    ]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE . '?raw=true');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($override, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    // api_key is type: password → maskForApi masks it to ***
    expect($body['data']['settings']['api_key'])->toBe('***');
    expect(array_key_exists('max_results', $body['data']['settings']))->toBe(false);
});

test('getOverride with raw=true returns empty when no override exists', function (): void {
    clearSession();
    [$crud, $authService, , , $authMiddleware] = makeAgentController();
    [, , $override] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE . '?raw=true');
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($override, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings'])->toBe([]);
});

test('getOverride returns effective settings with source annotation (without raw param)', function (): void {
    clearSession();
    [$crud, $tool, $override, $authService, $toolConfig, , $authMiddleware] = makeAgentControllers();
    registerUser($authService);
    $agentId = createAgent($crud, AGENT_NAME_MY_AGENT, [$authMiddleware]);

    // Enable the tool
    $enableReq = jsonRequest('POST', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_ENABLE);
    $enableReq->attributes->set('id', $agentId);
    $enableReq->attributes->set('toolId', 'test_tool');
    callController($tool, 'enableTool', $enableReq, [$authMiddleware]);

    // Clear any prior override so we start fresh
    $toolConfig->deleteAgentOverride(TestTool::class, $agentId);

    // Set global settings and agent override
    $toolConfig->putGlobalSettings(TestTool::class, [
        'api_key'     => 'global-key',
        'max_results' => '20',
    ]);
    $toolConfig->putAgentOverride(TestTool::class, $agentId, [
        'api_key' => 'agent-key',
    ]);

    $request = jsonRequest('GET', AGENT_PATH_PREFIX . $agentId . TOOL_PATH_SEGMENT . urlencode('test_tool') . TOOL_PATH_OVERRIDE);
    $request->attributes->set('id', $agentId);
    $request->attributes->set('toolId', 'test_tool');

    $response = callController($override, 'getOverride', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    $settings = $body['data']['settings'];

    // api_key is overridden by agent, but is type: password → value is masked
    expect($settings['api_key']['value'])->toBe('***');
    expect($settings['api_key']['source'])->toBe('agent');
    // max_results is from global (not overridden)
    expect($settings['max_results']['value'])->toBe('20');
    expect($settings['max_results']['source'])->toBe('global');
});
