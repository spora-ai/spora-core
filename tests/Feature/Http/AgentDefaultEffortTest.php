<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\AgentController;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\AgentFavoriteService;
use Spora\Services\AgentService;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * The agent's own reasoning-effort default.
 *
 * Two states only: null means "inherit", a level name means "run at this
 * level". An empty string is not a state — the column never
 * distinguishes it from null — so it is refused.
 *
 * The value is checked against the agent's effective level set on write:
 * a default the configuration does not offer would be dropped on the way
 * to the provider, and the run would happen at a depth nobody asked for.
 */
afterEach(fn() => \Spora\Core\Database::resetBootState());

/**
 * @param array<string, mixed> $settings
 * @return array{0: AgentController, 1: int} the controller and the agent id
 */
function agentDefaultEffortController(array $settings = []): array
{
    static $seq = 0;
    $seq++;

    $authService = bootAuthLayer();
    $userId = bootAuth($authService, "agent-default-effort-{$seq}@example.com");

    $config = LLMDriverConfiguration::create([
        'principal_id' => null,
        'name' => 'Agent Default Effort Config',
        'driver_class' => OpenAICompatibleDriver::class,
        'settings' => json_encode(array_merge([
            'api_key' => 'test',
            'model' => 'gpt-4o-mini',
            'base_url' => 'https://example.invalid/v1',
        ], $settings)),
        'is_global' => true,
        'is_default' => true,
    ]);

    $agent = Agent::create([
        'principal_id' => createUserPrincipalPublic($userId),
        'name' => 'Agent Default Effort Agent',
        'llm_driver_config_id' => $config->id,
        'max_steps' => 10,
        'is_active' => true,
    ]);

    $agentService = new AgentService(
        null,
        null,
        new PrincipalResolver(),
        null,
        makeEffortService(),
    );

    return [
        new AgentController(
            $authService,
            $agentService,
            new AgentFavoriteService($agentService),
        ),
        (int) $agent->id,
    ];
}

/**
 * @param array<string, mixed> $body
 */
function agentDefaultEffortRequest(int $agentId, array $body): Request
{
    $request = Request::create(
        '/api/v1/agents/' . $agentId,
        'PATCH',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($body),
    );
    $request->attributes->set('id', $agentId);

    return $request;
}

test('the agent\'s default round-trips through the REST API', function (): void {
    [$controller, $agentId] = agentDefaultEffortController();

    $response = $controller->update(agentDefaultEffortRequest($agentId, ['default_effort' => 'high']));

    expect($response->getStatusCode())->toBe(200);

    $payload = json_decode($response->getContent(), true);
    expect($payload['data']['agent']['default_effort'])->toBe('high')
        ->and(Agent::findOrFail($agentId)->default_effort)->toBe('high');
});

test('a default the configuration does not offer is refused', function (): void {
    [$controller, $agentId] = agentDefaultEffortController(['effort_levels' => 'low, high']);

    $response = $controller->update(agentDefaultEffortRequest($agentId, ['default_effort' => 'medium']));

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('EFFORT_LEVEL_UNSUPPORTED')
        ->and($payload['error']['message'])->toContain('Supported levels: low, high')
        ->and(Agent::findOrFail($agentId)->default_effort)->toBeNull();
});

test('a null default clears it', function (): void {
    [$controller, $agentId] = agentDefaultEffortController();

    $controller->update(agentDefaultEffortRequest($agentId, ['default_effort' => 'high']));
    expect(Agent::findOrFail($agentId)->default_effort)->toBe('high');

    $response = $controller->update(agentDefaultEffortRequest($agentId, ['default_effort' => null]));

    expect($response->getStatusCode())->toBe(200)
        ->and(Agent::findOrFail($agentId)->default_effort)->toBeNull();
});

test('an empty default is refused, because it is not a state', function (): void {
    [$controller, $agentId] = agentDefaultEffortController();

    $response = $controller->update(agentDefaultEffortRequest($agentId, ['default_effort' => '']));

    expect($response->getStatusCode())->toBe(422);

    $payload = json_decode($response->getContent(), true);
    expect($payload['error']['code'])->toBe('INVALID_EFFORT')
        ->and(Agent::findOrFail($agentId)->default_effort)->toBeNull();
});

test('a level the configuration names is accepted even when the canonical vocabulary does not', function (): void {
    [$controller, $agentId] = agentDefaultEffortController(['effort_levels' => 'low, turbo']);

    $response = $controller->update(agentDefaultEffortRequest($agentId, ['default_effort' => 'turbo']));

    expect($response->getStatusCode())->toBe(200)
        ->and(Agent::findOrFail($agentId)->default_effort)->toBe('turbo');
});
