<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use Delight\Auth\Role;
use Spora\Auth\AuthService;
use Spora\Http\AgentApi\AgentApiKeyController;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Models\Agent;
use Spora\Models\AgentApiKey;
use Spora\Models\GroupMembership;
use Spora\Models\Principal;
use Tests\Concerns\CreatesPrincipal;

/**
 * The admin half of the feature: minting, listing and revoking keys.
 *
 * Session-authenticated and CSRF-protected, unlike the Agent API itself,
 * because this is where an operator works. The gate is the manage role on
 * the agent's principal — seeing an agent is not enough to hand out a
 * credential that drives it.
 */
describe('Agent API key management', function (): void {
    uses(CreatesPrincipal::class);

    /**
     * Drive the controller through its real session middleware.
     *
     * The path carries no `{agentId}` placeholder, so the route variable is
     * bound as a request attribute — which is what `Router::handleFound()`
     * does for a real `{agentId}` segment.
     *
     * @param array<string, mixed> $body
     */
    function callKeyAdmin(
        string $method,
        int $agentId,
        array $body = [],
        array $pathVars = [],
    ): \Symfony\Component\HttpFoundation\Response {
        $request = jsonRequest($method, "/api/v1/agents/{$agentId}/api-keys", $body);
        $request->attributes->set('agentId', $agentId);
        foreach ($pathVars as $name => $value) {
            $request->attributes->set($name, $value);
        }

        $container = AgentApiTestSupport::container(
            new ScriptedLlmDriver(
                new \Spora\Drivers\ValueObjects\LLMResponse(
                    content: 'x',
                    toolCalls: [],
                    inputTokens: 1,
                    outputTokens: 1,
                    completionId: 'k',
                ),
            ),
        );
        $controller = $container->get(AgentApiKeyController::class);
        $auth = $container->get(AuthService::class);

        return callController($controller, $method, $request, [new AuthMiddleware($auth)]);
    }

    /** A signed-in user who owns a personal agent. Leaves them signed in. */
    function ownedAgent(string $suffix): array
    {
        $auth = bootAuthLayer();
        $userId = $auth->register("keys-{$suffix}@example.com", 'Password1!', "Keys {$suffix}");
        simulateLoggedInSession($userId, "keys-{$suffix}@example.com");

        $principalId = createUserPrincipalPublic($userId);
        $agent = Agent::create([
            'principal_id' => $principalId,
            'name' => "Keys Agent {$suffix}",
            'max_steps' => 10,
            'is_active' => true,
        ]);

        return [$userId, (int) $agent->id];
    }

    it('mints a key and returns the plaintext exactly once', function (): void {
        [, $agentId] = ownedAgent('mint');

        $response = callKeyAdmin('store', $agentId, ['name' => 'n8n']);
        $payload = AgentApiTestSupport::decode($response);

        expect($response->getStatusCode())->toBe(201);
        expect($payload['data']['secret'])->toStartWith('spk_' . $payload['data']['key']['id'] . '_');
        expect($payload['data']['key']['name'])->toBe('n8n');

        // Nothing in the response but the create carries the secret, and the
        // row holds only a digest of it.
        $key = AgentApiKey::findOrFail($payload['data']['key']['id']);
        expect($key->secret_hash)->not->toContain($payload['data']['secret']);

        $listed = AgentApiTestSupport::decode(callKeyAdmin('index', $agentId));
        expect($listed['data']['keys'][0])->not->toHaveKey('secret');
        expect(json_encode($listed))->not->toContain($payload['data']['secret']);
    });

    it('lists an agent\'s keys', function (): void {
        [, $agentId] = ownedAgent('list');
        AgentApiTestSupport::mintKey($agentId, 'one');
        AgentApiTestSupport::mintKey($agentId, 'two');

        $keys = AgentApiTestSupport::decode(callKeyAdmin('index', $agentId))['data']['keys'];

        expect($keys)->toHaveCount(2);
        expect(array_column($keys, 'name'))->toEqualCanonicalizing(['one', 'two']);
    });

    it('lists only the requested agent\'s keys', function (): void {
        [$mineUser, $mine] = ownedAgent('scope-a');
        [, $theirs] = ownedAgent('scope-b');
        AgentApiTestSupport::mintKey($theirs, 'not-mine');

        // Back to the first user; `ownedAgent` leaves the last one signed in.
        simulateLoggedInSession($mineUser, 'keys-scope-a@example.com');

        expect(AgentApiTestSupport::decode(callKeyAdmin('index', $mine))['data']['keys'])->toBe([]);
    });

    it('requires a name', function (): void {
        [, $agentId] = ownedAgent('noname');

        $response = callKeyAdmin('store', $agentId, []);

        expect($response->getStatusCode())->toBe(422);
        expect(AgentApiKey::where('agent_id', $agentId)->count())->toBe(0);
    });

    it('rejects a name over the column limit rather than truncating it', function (): void {
        [, $agentId] = ownedAgent('longname');

        $response = callKeyAdmin('store', $agentId, ['name' => str_repeat('n', 101)]);

        expect($response->getStatusCode())->toBe(422);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('VALIDATION_ERROR');
    });

    it('revokes a key', function (): void {
        [, $agentId] = ownedAgent('revoke');
        [$key] = AgentApiTestSupport::mintKey($agentId, 'doomed');

        $response = callKeyAdmin('destroy', $agentId, [], ['keyId' => $key->id]);

        expect($response->getStatusCode())->toBe(200);
        expect(AgentApiKey::find($key->id))->toBeNull();
    });

    it('answers with a body rather than 204 No Content', function (): void {
        [, $agentId] = ownedAgent('body');
        [$key] = AgentApiTestSupport::mintKey($agentId, 'body');

        $response = callKeyAdmin('destroy', $agentId, [], ['keyId' => $key->id]);

        // A 204 goes out with no body and no Content-Length, and Vite's dev
        // proxy turns exactly that into `502 No Content` on the way through —
        // which is how this was found. A bodied 200 proxies cleanly.
        expect($response->getStatusCode())->toBe(200);
        expect($response->headers->get('Content-Type'))->toBe('application/json');
        expect($response->getContent())->not->toBe('');

        $body = AgentApiTestSupport::decode($response);
        expect($body['data']['revoked'])->toBeTrue();
        expect($body['data']['key_id'])->toBe($key->id);
    });

    it('revokes idempotently', function (): void {
        [, $agentId] = ownedAgent('idem');

        $response = callKeyAdmin('destroy', $agentId, [], ['keyId' => 'does-not-exist']);

        expect($response->getStatusCode())->toBe(200);
        expect(AgentApiTestSupport::decode($response)['data']['revoked'])->toBeTrue();
    });

    it('cannot revoke another agent\'s key', function (): void {
        [$mineUser, $mine] = ownedAgent('xagent-a');
        [, $theirs] = ownedAgent('xagent-b');
        [$theirKey] = AgentApiTestSupport::mintKey($theirs, 'theirs');

        // Back to the first user, so the caller legitimately owns `$mine`.
        simulateLoggedInSession($mineUser, 'keys-xagent-a@example.com');

        $response = callKeyAdmin('destroy', $mine, [], ['keyId' => $theirKey->id]);

        // The same 200 body either way, and the row survives: the delete is
        // scoped by agent, so a key id is not a way to reach across agents.
        expect($response->getStatusCode())->toBe(200);
        expect(AgentApiTestSupport::decode($response)['data']['revoked'])->toBeTrue();
        expect(AgentApiKey::find($theirKey->id))->not->toBeNull();
    });

    it('refuses an unauthenticated caller', function (): void {
        [, $agentId] = ownedAgent('anon');
        clearSession();

        // `AuthMiddleware` throws rather than returning; `Kernel` is what maps
        // it to a 401, so the throw is the assertion.
        expect(fn() => callKeyAdmin('index', $agentId))
            ->toThrow(UnauthenticatedException::class);
    });

    it('refuses a caller who cannot manage the agent', function (): void {
        [, $agentId] = ownedAgent('notmine');

        $auth = bootAuthLayer();
        $strangerId = $auth->register('stranger@example.com', 'Password1!', 'Stranger');
        simulateLoggedInSession($strangerId, 'stranger@example.com');

        $response = callKeyAdmin('store', $agentId, ['name' => 'nope']);

        expect($response->getStatusCode())->toBe(403);
        expect(AgentApiTestSupport::decode($response)['error']['code'])->toBe('FORBIDDEN');
        expect(AgentApiKey::where('agent_id', $agentId)->count())->toBe(0);
    });

    it('404s for an agent that does not exist', function (): void {
        ownedAgent('ghost');

        $response = callKeyAdmin('index', 999999);

        expect($response->getStatusCode())->toBe(404);
    });

    it('lets a global admin manage any agent\'s keys', function (): void {
        [, $agentId] = ownedAgent('admintest');

        $auth = bootAuthLayer();
        $adminId = $auth->register('root@example.com', 'Password1!', 'Root');
        $auth->grantRole($adminId, Role::ADMIN);
        simulateLoggedInSession($adminId, 'root@example.com');

        expect(callKeyAdmin('store', $agentId, ['name' => 'by-admin'])->getStatusCode())->toBe(201);
    });

    it('lets a group admin manage the group\'s agent keys', function (): void {
        $auth = bootAuthLayer();
        $ownerId = $auth->register('gowner@example.com', 'Password1!', 'G Owner');
        simulateLoggedInSession($ownerId, 'gowner@example.com');

        $principalId = $this->makeGroupPrincipal($ownerId, 'Key Group');
        $agentId = (int) Agent::create([
            'principal_id' => $principalId,
            'name' => 'Group Agent',
            'max_steps' => 10,
            'is_active' => true,
        ])->id;

        // Promote the owner from plain member to admin of the group.
        GroupMembership::where('group_id', (int) Principal::findOrFail($principalId)->group_id)
            ->where('user_id', $ownerId)
            ->update(['role' => GroupMembership::ROLE_ADMIN]);

        expect(callKeyAdmin('store', $agentId, ['name' => 'group-admin'])->getStatusCode())->toBe(201);
    });
});
