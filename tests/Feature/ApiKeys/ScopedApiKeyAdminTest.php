<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\ScopedApi\ScopedApiKeyController;
use Spora\Models\ApiKey;
use Spora\Models\GroupMembership;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CreatesPrincipal;
use Tests\Feature\AgentApi\AgentApiTestSupport;

/**
 * The admin half of the scoped feature: minting, listing and revoking keys.
 *
 * Who may mint what:
 *
 *   - a group key needs *owner* or *admin* of the group;
 *   - a global key needs a global admin.
 *
 * A global key is the widest credential Spora issues — it reads every
 * group's chats — so minting one is deliberately not a group-level right.
 */
describe('scoped API key management', function (): void {
    uses(CreatesPrincipal::class);

    /**
     * Drive the controller through its real session middleware.
     *
     * @param array<string, mixed> $body
     */
    function callKeyAdmin(string $method, string $path, array $body = [], array $pathVars = []): Response
    {
        $request = jsonRequest($method, $path, $body);
        foreach ($pathVars as $name => $value) {
            $request->attributes->set($name, $value);
        }

        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $controller = $container->get(ScopedApiKeyController::class);
        $auth = $container->get(AuthService::class);

        return callController($controller, $method, $request, [new AuthMiddleware($auth)]);
    }

    /**
     * A signed-in user who owns a group. Leaves them signed in.
     *
     * @return array{0: int, 1: int} the user id and the group id
     */
    function ownedGroup(string $suffix): array
    {
        $auth = bootAuthLayer();
        $userId = $auth->register("scoped-keys-{$suffix}@example.com", 'Password1!', "Scoped Keys {$suffix}");
        simulateLoggedInSession($userId, "scoped-keys-{$suffix}@example.com");

        $group = AgentApiTestSupport::seedGroup('scoped-keys-' . $suffix, 1, $userId);

        return [$userId, $group['groupId']];
    }

    it('mints a group key for a group owner and returns the plaintext once', function (): void {
        [, $groupId] = ownedGroup('mint');

        $response = callKeyAdmin('groupStore', "/api/v1/groups/{$groupId}/api-keys", ['name' => 'n8n'], ['groupId' => $groupId]);
        $payload = AgentApiTestSupport::decode($response);

        expect($response->getStatusCode())->toBe(201);
        expect($payload['data']['secret'])->toStartWith('spk_');

        $key = ApiKey::findOrFail($payload['data']['key']['id']);
        expect($key->scope)->toBe('group');
        expect((int) $key->group_id)->toBe($groupId);
        expect($key->agent_id)->toBeNull();
    });

    it('lists the group\'s keys without their secrets', function (): void {
        [, $groupId] = ownedGroup('list');

        $minted = callKeyAdmin('groupStore', "/api/v1/groups/{$groupId}/api-keys", ['name' => 'n8n'], ['groupId' => $groupId]);
        $keyId = AgentApiTestSupport::decode($minted)['data']['key']['id'];

        $response = callKeyAdmin('groupIndex', "/api/v1/groups/{$groupId}/api-keys", [], ['groupId' => $groupId]);
        $payload = AgentApiTestSupport::decode($response);

        expect($response->getStatusCode())->toBe(200);
        expect($payload['data']['keys'])->toHaveCount(1);
        expect($payload['data']['keys'][0]['id'])->toBe($keyId);
        expect($payload['data']['keys'][0])->not->toHaveKey('secret');
    });

    it('refuses a group key for a plain member', function (): void {
        $auth = bootAuthLayer();
        $userId = $auth->register('scoped-keys-member@example.com', 'Password1!', 'Member');
        simulateLoggedInSession($userId, 'scoped-keys-member@example.com');

        $group = AgentApiTestSupport::seedGroup('scoped-keys-member-group');
        GroupMembership::create([
            'group_id' => $group['groupId'],
            'user_id' => $userId,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $response = callKeyAdmin(
            'groupStore',
            "/api/v1/groups/{$group['groupId']}/api-keys",
            ['name' => 'n8n'],
            ['groupId' => $group['groupId']],
        );

        expect($response->getStatusCode())->toBe(403);
    });

    it('revokes a group key', function (): void {
        [, $groupId] = ownedGroup('revoke');

        $minted = callKeyAdmin('groupStore', "/api/v1/groups/{$groupId}/api-keys", ['name' => 'n8n'], ['groupId' => $groupId]);
        $keyId = AgentApiTestSupport::decode($minted)['data']['key']['id'];

        $response = callKeyAdmin(
            'groupDestroy',
            "/api/v1/groups/{$groupId}/api-keys/{$keyId}",
            [],
            ['groupId' => $groupId, 'keyId' => $keyId],
        );

        expect($response->getStatusCode())->toBe(200);
        expect(ApiKey::find($keyId))->toBeNull();
    });

    it('mints a global key for a global admin', function (): void {
        $auth = bootAuthLayer();
        $userId = $auth->register('scoped-keys-admin@example.com', 'Password1!', 'Admin');
        simulateLoggedInSession($userId, 'scoped-keys-admin@example.com');

        // The global admin bit is a delight-im role, not a column.
        $container = AgentApiTestSupport::container(AgentApiTestSupport::llm());
        $container->get(AuthService::class)->grantRole($userId, \Delight\Auth\Role::ADMIN);

        $response = callKeyAdmin('globalStore', '/api/v1/global-api-keys', ['name' => 'automation']);
        $payload = AgentApiTestSupport::decode($response);

        expect($response->getStatusCode())->toBe(201);
        expect($payload['data']['secret'])->toStartWith('spk_');

        $key = ApiKey::findOrFail($payload['data']['key']['id']);
        expect($key->scope)->toBe('global');
        expect($key->agent_id)->toBeNull();
        expect($key->group_id)->toBeNull();
    });

    it('refuses a global key for a group owner', function (): void {
        ownedGroup('not-global');

        $response = callKeyAdmin('globalStore', '/api/v1/global-api-keys', ['name' => 'automation']);

        expect($response->getStatusCode())->toBe(403);
    });

    it('does not let a key id from another group be revoked through this one', function (): void {
        [, $groupId] = ownedGroup('scoped');
        $other = AgentApiTestSupport::seedGroup('scoped-other');

        [$otherKeyRow] = AgentApiTestSupport::mintGroupKey($other['groupId'], 'other');
        $otherKey = (string) $otherKeyRow->id;

        $response = callKeyAdmin(
            'groupDestroy',
            "/api/v1/groups/{$groupId}/api-keys/{$otherKey}",
            [],
            ['groupId' => $groupId, 'keyId' => $otherKey],
        );

        expect($response->getStatusCode())->toBe(200);
        expect(ApiKey::find($otherKey))->not->toBeNull();
    });
});
