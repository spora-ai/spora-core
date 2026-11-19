<?php

declare(strict_types=1);

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\PrincipalPreference;
use Spora\Services\LLMConfigService;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/v1/llm-configs/effective?agent_id=N` — the answer to "which
 * configuration does this agent actually run with?".
 *
 * The frontend used to re-implement the tier cascade, and got it wrong for
 * group-owned agents. The server resolves it here, and names the tier that
 * won so the client can label it.
 */
function makeEffectiveController(): array
{
    $authService = bootAuthLayer();
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $llmConfigService = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
    $validator = new Spora\Services\LlmConfigValidator($llmConfigService);
    $controller = new Spora\Http\LLMConfigController(
        $authService,
        $llmConfigService,
        $validator,
        makeAgentService(),
    );

    return [$controller, $authService, $llmConfigService, new AuthMiddleware($authService)];
}

function makeEffectivePrincipalConfig(LLMConfigService $service, int $principalId, string $name, bool $isDefault = false): LLMDriverConfiguration
{
    $persistence = makeLlmPersistenceForService($service);

    $config = new LLMDriverConfiguration();
    $config->principal_id = $principalId;
    $config->is_global = false;
    $config->name = $name;
    $config->driver_class = OpenAICompatibleDriver::class;
    $config->settings = json_encode($persistence->encodeSettings(OpenAICompatibleDriver::class, [
        'api_key' => 'sk-effective',
        'model' => 'gpt-4o',
    ]));
    $config->is_default = $isDefault;
    $config->save();

    return $config;
}

function seedEffectiveAgent(int $principalId, string $name): int
{
    $now = date('Y-m-d H:i:s');

    return (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId([
        'principal_id' => $principalId,
        'name'         => $name,
        'max_steps'    => 10,
        'is_active'    => 1,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
}

function callEffective(Spora\Http\LLMConfigController $controller, AuthMiddleware $authMiddleware, ?int $agentId): Response
{
    $uri = '/api/v1/llm-configs/effective';
    if ($agentId !== null) {
        $uri .= '?agent_id=' . $agentId;
    }

    $request = jsonRequest('GET', $uri, []);

    return callController($controller, 'effective', $request, [$authMiddleware]);
}

test('effective() answers with the resolved config and the tier it came from', function (): void {
    [$controller, $authService, $llmConfigService, $authMiddleware] = makeEffectiveController();
    $userId = bootAuth($authService, 'effective-user@example.com');
    $principalId = createUserPrincipalPublic($userId);

    $config = makeEffectivePrincipalConfig($llmConfigService, $principalId, 'User Preferred Config');
    PrincipalPreference::create([
        'principal_id' => $principalId,
        'preferred_llm_config_id' => (int) $config->id,
    ]);

    $agentId = seedEffectiveAgent($principalId, 'Effective User Agent');

    $response = callEffective($controller, $authMiddleware, $agentId);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['source'])->toBe('user_preference')
        ->and($body['data']['config']['id'])->toBe((int) $config->id)
        ->and($body['data']['config']['name'])->toBe('User Preferred Config')
        ->and($body['data']['config']['driver_class'])->toBe(OpenAICompatibleDriver::class)
        ->and($body['data']['config']['is_global'])->toBeFalse()
        ->and($body['data']['config']['principal_id'])->toBe($principalId)
        ->and($body['data']['config'])->toHaveKeys([
            'id',
            'name',
            'driver_class',
            'driver_name',
            'driver_display_name',
            'settings',
            'context_window',
            'max_tokens_output',
            'is_default',
            'principal_id',
            'is_global',
            'created_at',
            'updated_at',
        ]);
});

test('effective() resolves a group-owned agent against the group\'s preference', function (): void {
    [$controller, $authService, $llmConfigService, $authMiddleware] = makeEffectiveController();
    $ownerId = bootAuth($authService, 'effective-group@example.com');
    createUserPrincipalPublic($ownerId);
    $groupPrincipalId = $this->makeGroupPrincipal($ownerId, 'Effective Group');

    $groupPreferred = makeEffectivePrincipalConfig($llmConfigService, $groupPrincipalId, 'Group Preferred Config');
    PrincipalPreference::create([
        'principal_id' => $groupPrincipalId,
        'preferred_llm_config_id' => (int) $groupPreferred->id,
    ]);

    $agentId = seedEffectiveAgent($groupPrincipalId, 'Effective Group Agent');

    $response = callEffective($controller, $authMiddleware, $agentId);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['data']['source'])->toBe('group_preference')
        ->and($body['data']['config']['id'])->toBe((int) $groupPreferred->id);
});

test('effective() answers 404 for an agent the caller cannot see', function (): void {
    [$controller, $authService, $llmConfigService, $authMiddleware] = makeEffectiveController();
    $ownerId = bootAuth($authService, 'effective-owner@example.com');
    $ownerPrincipalId = createUserPrincipalPublic($ownerId);

    $agentId = seedEffectiveAgent($ownerPrincipalId, 'Private Effective Agent');

    $strangerId = bootAuth($authService, 'effective-stranger@example.com');
    createUserPrincipalPublic($strangerId);

    $response = callEffective($controller, $authMiddleware, $agentId);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('AGENT_NOT_FOUND');
});

test('effective() answers 422 when agent_id is missing or not a positive integer', function (): void {
    [$controller, $authService, , $authMiddleware] = makeEffectiveController();
    bootAuth($authService, 'effective-validation@example.com');

    $response = callEffective($controller, $authMiddleware, null);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_decode($response->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');

    foreach (['abc', '0', '-3'] as $invalid) {
        $request = jsonRequest('GET', '/api/v1/llm-configs/effective?agent_id=' . $invalid, []);
        $response = callController($controller, 'effective', $request, [$authMiddleware]);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->and(json_decode($response->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
    }
});

test('effective() answers 422 for an array-valued agent_id', function (): void {
    [$controller, $authService, , $authMiddleware] = makeEffectiveController();
    bootAuth($authService, 'effective-array-agent-id@example.com');

    // `?agent_id[]=1` arrives as an array. Symfony's InputBag::get()
    // throws on a non-scalar, so the endpoint would answer 500 instead
    // of the 422 it documents.
    $request = jsonRequest('GET', '/api/v1/llm-configs/effective?agent_id[]=1', []);
    $response = callController($controller, 'effective', $request, [$authMiddleware]);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_decode($response->getContent(), true)['error']['code'])->toBe('VALIDATION_ERROR');
});

test('the effective path dispatches to the effective handler, not to the {id} route', function (): void {
    $collector = new Spora\Core\MiddlewareRouteCollector(
        new FastRoute\RouteParser\Std(),
        new FastRoute\DataGenerator\GroupCountBased(),
    );
    Spora\Core\RouteDefinitions::register($collector);

    $dispatcher = new FastRoute\Dispatcher\GroupCountBased($collector->getData());
    [, $handler] = $dispatcher->dispatch('GET', '/api/v1/llm-configs/effective');

    expect($handler['handler'])->toBe([Spora\Http\LLMConfigController::class, 'effective']);
});
