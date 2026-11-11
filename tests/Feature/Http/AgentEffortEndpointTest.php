<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Psr\Log\NullLogger;
use Spora\Core\RouteDefinitions;
use Spora\Core\SecurityManager;
use Spora\Drivers\AbstractCompatibleDriver;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Http\AgentEffortController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\OpenApi\RouteSpecCollector;
use Spora\Services\AgentService;
use Spora\Services\EffortService;
use Spora\Services\LLMConfigPreferences;
use Spora\Services\LLMConfigService;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /api/v1/agents/{id}/effort` — the levels an agent's driver can
 * express, and the one it defaults to.
 *
 * The effective configuration is resolved on the server, through the same
 * tiers the tick uses. A client that re-implemented that would offer levels
 * the driver cannot express.
 */
afterEach(fn() => \Spora\Core\Database::resetBootState());

test('the endpoint reports the levels of the effective driver and its default', function (): void {
    [$controller, $agentId] = effortEndpointController('high');

    $request = Request::create('/api/v1/agents/' . $agentId . '/effort', 'GET');
    $request->attributes->set('id', $agentId);

    $response = $controller->show($request);

    expect($response->getStatusCode())->toBe(200);

    $payload = json_decode($response->getContent(), true);
    expect($payload['data']['supported'])->toBeTrue()
        ->and($payload['data']['default'])->toBe('high');

    $levels = $payload['data']['levels'];
    expect($levels)->toBeArray()
        ->and(array_column($levels, 'value'))->toContain('low', 'high', 'max')
        ->and(array_column($levels, 'label'))->toContain('Low', 'High', 'Max');
});

test('the endpoint reports no default when the configuration names no effort', function (): void {
    [$controller, $agentId] = effortEndpointController(null);

    $request = Request::create('/api/v1/agents/' . $agentId . '/effort', 'GET');
    $request->attributes->set('id', $agentId);

    $response = $controller->show($request);

    expect($response->getStatusCode())->toBe(200);

    $payload = json_decode($response->getContent(), true);
    expect($payload['data']['default'])->toBeNull()
        ->and($payload['data']['supported'])->toBeTrue();
});

test('the endpoint reports no support for a driver that cannot express an effort', function (): void {
    [$controller, $agentId] = effortEndpointController(null, NoEffortTestDriver::class);

    $request = Request::create('/api/v1/agents/' . $agentId . '/effort', 'GET');
    $request->attributes->set('id', $agentId);

    $response = $controller->show($request);

    expect($response->getStatusCode())->toBe(200);

    $payload = json_decode($response->getContent(), true);
    expect($payload['data']['supported'])->toBeFalse()
        ->and($payload['data']['levels'])->toBe([]);
});

test('the endpoint answers 404 for an agent the caller cannot see', function (): void {
    [$controller, $agentId] = effortEndpointController('high');

    $request = Request::create('/api/v1/agents/' . $agentId . '/effort', 'GET');
    $request->attributes->set('id', 999999);

    $response = $controller->show($request);

    expect($response->getStatusCode())->toBe(404);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('NOT_FOUND');
});

test('the route is registered', function (): void {
    $collector = new RouteSpecCollector();
    RouteDefinitions::register($collector);

    $routes = array_map(
        static fn(array $route): string => $route['method'] . ' ' . $route['route'],
        $collector->routes(),
    );

    expect($routes)->toContain('GET /api/v1/agents/{id}/effort');
});

/**
 * @return array{0: AgentEffortController, 1: int} the controller and the agent id
 */
function effortEndpointController(?string $configuredEffort, string $driverClass = OpenAICompatibleDriver::class): array
{
    $authService = bootAuthLayer();
    $userId = bootAuth($authService);

    $settings = [
        'api_key' => 'test',
        'model' => 'gpt-4o-mini',
        'base_url' => 'https://example.invalid/v1',
    ];
    if ($configuredEffort !== null) {
        $settings['effort'] = $configuredEffort;
    }

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Effort Endpoint Config',
        'driver_class' => $driverClass,
        'settings' => json_encode($settings),
        'is_global' => true,
        'is_default' => true,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Effort Endpoint Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $llmConfigService = new LLMConfigService($security, [OpenAICompatibleDriver::class, $driverClass]);
    $driverFactory = new DriverFactory(new NullLogger(), $llmConfigService, 60);

    $effortService = new EffortService(
        new LLMConfigPreferences(),
        $llmConfigService,
        $driverFactory,
    );

    return [
        new AgentEffortController(
            $authService,
            new AgentService(null, null, new PrincipalResolver()),
            $effortService,
        ),
        (int) $agent->id,
    ];
}

/**
 * A driver that cannot express a reasoning effort — the shape of every
 * driver that is not effort-aware.
 */
final class NoEffortTestDriver extends AbstractCompatibleDriver
{
    public static function getName(): string
    {
        return 'no_effort_test';
    }

    public static function getDisplayName(): string
    {
        return 'No Effort Test';
    }

    public function getProviderName(): string
    {
        return 'no_effort_test';
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        return new LLMResponse('', [], 0, 0, '');
    }
}
