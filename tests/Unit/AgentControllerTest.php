<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Http\AgentController;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Services\ToolConfigService;
use Tests\Fixtures\TestTool;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Boot a fresh in-memory DB and return AgentController + AuthService + ToolConfigService.
 */
function makeAgentController(): array
{
    $authService = bootAuthLayer();

    $key        = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security   = new SecurityManager($key);
    $toolConfig = new ToolConfigService($security);
    $controller = new AgentController($authService, $toolConfig);

    return [$controller, $authService, $toolConfig];
}

/**
 * Register a user and simulate their session. Returns the user ID.
 */
function registerUser(AuthService $authService, string $email = 'user@example.com', string $password = 'Password1!'): int
{
    $userId = $authService->register($email, $password);
    simulateLoggedInSession($userId, $email);

    return $userId;
}

// ---------------------------------------------------------------------------
// Auth guard
// ---------------------------------------------------------------------------

test('unauthenticated request throws UnauthenticatedException', function (): void {
    clearSession();
    [$controller] = makeAgentController();

    expect(fn () => $controller->show(jsonRequest('GET', '/api/v1/agent')))
        ->toThrow(UnauthenticatedException::class);
});

// ---------------------------------------------------------------------------
// show
// ---------------------------------------------------------------------------

test('show auto-creates default agent for new user', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $response = $controller->show(jsonRequest('GET', '/api/v1/agent'));

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agent'])->toHaveKey('id');
    expect($body['data']['agent']['name'])->toBe('My Assistant');
    expect($body['data']['agent']['tools'])->toBe([]);
});

test('show returns existing agent without creating a duplicate', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $controller->show(jsonRequest('GET', '/api/v1/agent'));
    $controller->show(jsonRequest('GET', '/api/v1/agent'));

    expect(Capsule::table('agents')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

test('update patches allowed agent fields', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $response = $controller->update(
        jsonRequest('PATCH', '/api/v1/agent', ['name' => 'My Bot', 'llm_model' => 'gpt-4o-mini'])
    );

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agent']['name'])->toBe('My Bot');
    expect($body['data']['agent']['llm_model'])->toBe('gpt-4o-mini');
});

test('update ignores unknown fields', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $response = $controller->update(
        jsonRequest('PATCH', '/api/v1/agent', ['user_id' => 999, 'name' => 'Safe Name'])
    );

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['agent']['name'])->toBe('Safe Name');
    // user_id must not have changed
    $row = Capsule::table('agents')->first();
    expect((int) $row->user_id)->not()->toBe(999);
});

// ---------------------------------------------------------------------------
// enableTool / disableTool
// ---------------------------------------------------------------------------

test('enableTool inserts an AgentTool row and returns 201', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $request = jsonRequest('POST', '/api/v1/agent/tools/test/enable');
    $request->attributes->set('toolClass', TestTool::class);

    $response = $controller->enableTool($request);

    expect($response->getStatusCode())->toBe(201);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['tool']['tool_class'])->toBe(TestTool::class);
    expect($body['data']['tool']['auto_approve'])->toBeNull();
});

test('enableTool is idempotent: second call returns 200 without duplicating', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $request = jsonRequest('POST', '/api/v1/agent/tools/test/enable');
    $request->attributes->set('toolClass', TestTool::class);

    $controller->enableTool($request);
    $response = $controller->enableTool($request);

    expect($response->getStatusCode())->toBe(200);
    expect(Capsule::table('agent_tools')->count())->toBe(1);
});

test('show lists enabled tools on the agent resource', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $request = jsonRequest('POST', '/api/v1/agent/tools/test/enable');
    $request->attributes->set('toolClass', TestTool::class);
    $controller->enableTool($request);

    $response = $controller->show(jsonRequest('GET', '/api/v1/agent'));
    $body     = json_decode($response->getContent(), true);

    expect($body['data']['agent']['tools'])->toHaveCount(1);
    expect($body['data']['agent']['tools'][0]['tool_class'])->toBe(TestTool::class);
});

test('disableTool removes the AgentTool row and returns 204', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $enableReq = jsonRequest('POST', '/api/v1/agent/tools/test/enable');
    $enableReq->attributes->set('toolClass', TestTool::class);
    $controller->enableTool($enableReq);

    $disableReq = jsonRequest('DELETE', '/api/v1/agent/tools/test/enable');
    $disableReq->attributes->set('toolClass', TestTool::class);
    $response = $controller->disableTool($disableReq);

    expect($response->getStatusCode())->toBe(204);
    expect($response->getContent())->toBe('');
    expect(Capsule::table('agent_tools')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// patchTool
// ---------------------------------------------------------------------------

test('patchTool sets auto_approve to true', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $enableReq = jsonRequest('POST', '/api/v1/agent/tools/test/enable');
    $enableReq->attributes->set('toolClass', TestTool::class);
    $controller->enableTool($enableReq);

    $patchReq = jsonRequest('PATCH', '/api/v1/agent/tools/test', ['auto_approve' => true]);
    $patchReq->attributes->set('toolClass', TestTool::class);
    $response = $controller->patchTool($patchReq);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['tool']['auto_approve'])->toBeTrue();
});

test('patchTool sets auto_approve back to null', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $enableReq = jsonRequest('POST', '/api/v1/agent/tools/test/enable');
    $enableReq->attributes->set('toolClass', TestTool::class);
    $controller->enableTool($enableReq);

    $patchReq = jsonRequest('PATCH', '/api/v1/agent/tools/test', ['auto_approve' => true]);
    $patchReq->attributes->set('toolClass', TestTool::class);
    $controller->patchTool($patchReq);

    $patchReq2 = jsonRequest('PATCH', '/api/v1/agent/tools/test', ['auto_approve' => null]);
    $patchReq2->attributes->set('toolClass', TestTool::class);
    $response = $controller->patchTool($patchReq2);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['tool']['auto_approve'])->toBeNull();
});

test('patchTool on non-enabled tool returns 404', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $controller->show(jsonRequest('GET', '/api/v1/agent')); // create default agent

    $request = jsonRequest('PATCH', '/api/v1/agent/tools/test', ['auto_approve' => true]);
    $request->attributes->set('toolClass', TestTool::class);
    $response = $controller->patchTool($request);

    expect($response->getStatusCode())->toBe(404);
});

// ---------------------------------------------------------------------------
// getOverride / putOverride / deleteOverride
// ---------------------------------------------------------------------------

test('getOverride returns empty settings when no override set', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $request = jsonRequest('GET', '/api/v1/agent/tools/test/override');
    $request->attributes->set('toolClass', TestTool::class);
    $response = $controller->getOverride($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings'])->toBe([]);
});

test('putOverride saves agent-scoped settings and masks passwords', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $request = jsonRequest('PUT', '/api/v1/agent/tools/test/override', ['api_key' => 'secret-key']);
    $request->attributes->set('toolClass', TestTool::class);
    $response = $controller->putOverride($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['settings']['api_key'])->toBe('***');
});

test('putOverride discards global-scoped keys', function (): void {
    clearSession();
    [$controller, $authService, $toolConfig] = makeAgentController();
    $userId = registerUser($authService);

    // Create the agent first
    $controller->show(jsonRequest('GET', '/api/v1/agent'));
    $agentId = (int) Capsule::table('agents')->where('user_id', $userId)->value('id');

    $request = jsonRequest('PUT', '/api/v1/agent/tools/test/override', [
        'api_key'     => 'secret',   // scope: agent → stored
        'max_results' => '10',        // scope: global → discarded
    ]);
    $request->attributes->set('toolClass', TestTool::class);
    $controller->putOverride($request);

    $effective = $toolConfig->getEffectiveSettings(TestTool::class, $agentId);
    // max_results is global-scoped and was not stored as override
    expect(array_key_exists('max_results', $effective))->toBeFalse();
});

test('deleteOverride removes the override and returns 204', function (): void {
    clearSession();
    [$controller, $authService] = makeAgentController();
    registerUser($authService);

    $putReq = jsonRequest('PUT', '/api/v1/agent/tools/test/override', ['api_key' => 'secret-key']);
    $putReq->attributes->set('toolClass', TestTool::class);
    $controller->putOverride($putReq);

    $delReq = jsonRequest('DELETE', '/api/v1/agent/tools/test/override');
    $delReq->attributes->set('toolClass', TestTool::class);
    $response = $controller->deleteOverride($delReq);

    expect($response->getStatusCode())->toBe(204);
    expect($response->getContent())->toBe('');

    // Override row should be gone
    $getReq = jsonRequest('GET', '/api/v1/agent/tools/test/override');
    $getReq->attributes->set('toolClass', TestTool::class);
    $body = json_decode($controller->getOverride($getReq)->getContent(), true);
    expect($body['data']['settings'])->toBe([]);
});
