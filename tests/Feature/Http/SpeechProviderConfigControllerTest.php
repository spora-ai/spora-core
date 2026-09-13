<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Http\SpeechProviderConfigController;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\SpeechProviderConfigService;
use Spora\Services\ToolConfigService;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextRegistry;
use Symfony\Component\HttpFoundation\Request;

const SPC_TEST_PASSWORD = 'Password1!';

/**
 * Wire the controller graph against the real {@see ToolConfigService} so
 * the crypto + encryption round-trip matches what the production setup
 * does. The OpenAiCompatibleTranscriber provider is registered so the
 * registry has at least one known class.
 *
 * @return array{0: SpeechProviderConfigController, 1: AuthService, 2: ToolConfigService, 3: PrincipalService}
 */
function makeSpeechProviderConfigController(): array
{
    $auth = bootAuthLayer();
    $security = new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new ToolConfigService($security, new \Psr\Log\NullLogger(), []);
    $principalService = new PrincipalService(new PrincipalResolver());

    $registry = new SpeechToTextRegistry(
        [new OpenAiCompatibleTranscriber(new \Symfony\Component\HttpClient\MockHttpClient(), $toolConfig)],
        $toolConfig,
    );

    $service = new SpeechProviderConfigService(
        $toolConfig,
        $registry,
        $principalService,
        new \Spora\Services\SpeechProviderConfigValidator($registry),
    );

    $controller = new SpeechProviderConfigController($auth, $service);

    return [$controller, $auth, $toolConfig, $principalService];
}

function jsonSpcRequest(string $method, string $uri, array $body = []): Request
{
    $content = $body !== [] ? json_encode($body) : '';
    return Request::create(
        $uri,
        strtoupper($method),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $content,
    );
}

describe('SpeechProviderConfigController', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
        Capsule::table('tool_user_settings')->delete();
        Capsule::table('tool_configurations')->delete();
    });

    it('returns 200 on schema for an authenticated caller', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-schema@example.com', SPC_TEST_PASSWORD);

        $response = $controller->schema();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['providers'])->toBeArray();
        expect($body['data']['providers'][0]['class'])->toBe(OpenAiCompatibleTranscriber::class);
    });

    it('returns 200 on schema for an anonymous caller', function (): void {
        [$controller] = makeSpeechProviderConfigController();
        $response = $controller->schema();
        expect($response->getStatusCode())->toBe(200);
    });

    it('200 admin happy path: create a global config, list it, update it, delete it', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-admin@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $userId);

        // CREATE — admin scope=global
        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'global',
            'settings' => [
                'api_key' => 'sk-mistral',
                'display_name' => 'Mistral Voxtral',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
            ],
        ]));
        expect($createResp->getStatusCode())->toBe(200);
        $createBody = json_decode($createResp->getContent(), true);
        expect($createBody['data']['config']['scope'])->toBe('global');
        expect($createBody['data']['config']['provider_class'])->toBe(OpenAiCompatibleTranscriber::class);
        expect($createBody['data']['config']['settings']['api_key'])->toBe('***');
        $configId = $createBody['data']['config']['id'];

        // LIST — admin sees globals
        $listResp = $controller->index(jsonSpcRequest("GET", "/api/v1/speech/provider-configs"));
        expect($listResp->getStatusCode())->toBe(200);
        $listBody = json_decode($listResp->getContent(), true);
        expect($listBody['data']['configs'])->toHaveCount(1);
        expect($listBody['data']['configs'][0]['id'])->toBe($configId);

        // UPDATE — change display_name + base_url
        $updateResp = $controller->update($configId, jsonSpcRequest('PUT', "/api/v1/speech/provider-configs/{$configId}", [
            'settings' => [
                'display_name' => 'Mistral Voxtral (prod)',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
                'api_key' => 'sk-mistral', // unchanged — sends same value
            ],
        ]));
        expect($updateResp->getStatusCode())->toBe(200);
        $updateBody = json_decode($updateResp->getContent(), true);
        expect($updateBody['data']['config']['settings']['display_name'])->toBe('Mistral Voxtral (prod)');

        // DELETE
        $deleteResp = $controller->destroy($configId);
        expect($deleteResp->getStatusCode())->toBe(200);
        $deleteBody = json_decode($deleteResp->getContent(), true);
        expect($deleteBody['data']['deleted'])->toBeTrue();
    });

    it('200 user happy path: a non-admin can create + list their own per-user config', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-user@example.com', SPC_TEST_PASSWORD);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'user',
            'settings' => [
                'api_key' => 'sk-personal',
                'display_name' => 'Personal Mistral',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
            ],
        ]));
        expect($createResp->getStatusCode())->toBe(200);
        $createBody = json_decode($createResp->getContent(), true);
        expect($createBody['data']['config']['scope'])->toBe('user');
        expect($createBody['data']['config']['principal_id'])->toBeGreaterThan(0);
        $configId = $createBody['data']['config']['id'];

        // LIST — non-admin sees own user-scope config
        $listResp = $controller->index(jsonSpcRequest("GET", "/api/v1/speech/provider-configs"));
        expect($listResp->getStatusCode())->toBe(200);
        $listBody = json_decode($listResp->getContent(), true);
        expect($listBody['data']['configs'])->toHaveCount(1);
        expect($listBody['data']['configs'][0]['id'])->toBe($configId);
    });

    it('returns 403 when a non-admin POSTs scope=global', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-nonadmin@example.com', SPC_TEST_PASSWORD);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'global',
            'settings' => [
                'api_key' => 'sk-x',
                'display_name' => 'X',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ]));
        expect($createResp->getStatusCode())->toBe(403);
        $body = json_decode($createResp->getContent(), true);
        expect($body['error']['code'])->toBe('SPEECH_PROVIDER_CONFIG_FORBIDDEN');
    });

    it('returns 403 when a user tries to update another user config (use 2-user fixture)', function (): void {
        [$controller, $auth, $toolConfig, $principalService] = makeSpeechProviderConfigController();

        // User A creates a personal config.
        $userA = bootAuth($auth, 'spc-a@example.com', SPC_TEST_PASSWORD);
        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'user',
            'settings' => [
                'api_key' => 'sk-a',
                'display_name' => 'A',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ]));
        expect($createResp->getStatusCode())->toBe(200);
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        // Switch to user B — clear the prior session and login.
        clearSession();
        $userB = bootAuth($auth, 'spc-b@example.com', SPC_TEST_PASSWORD);
        expect($userB)->not->toBe($userA);

        $updateResp = $controller->update($configId, jsonSpcRequest('PUT', "/api/v1/speech/provider-configs/{$configId}", [
            'settings' => [
                'api_key' => 'sk-b',
                'display_name' => 'B',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'whisper-1',
            ],
        ]));
        expect($updateResp->getStatusCode())->toBe(403);
        $body = json_decode($updateResp->getContent(), true);
        expect($body['error']['code'])->toBe('SPEECH_PROVIDER_CONFIG_FORBIDDEN');
    });

    it('returns 404 when POST provider_class is not a registered speech provider', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        bootAuth($auth, 'spc-bad@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $auth->currentUserId() ?? 0);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => 'NotAReal\\Class',
            'scope' => 'global',
            'settings' => [],
        ]));
        expect($createResp->getStatusCode())->toBe(404);
        $body = json_decode($createResp->getContent(), true);
        expect($body['error']['code'])->toBe('SPEECH_PROVIDER_CONFIG_NOT_FOUND');
    });

    it('returns 422 when POST body fails the declared regex validation', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        bootAuth($auth, 'spc-regex@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $auth->currentUserId() ?? 0);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'global',
            'settings' => [
                'api_key' => 'sk-x',
                'display_name' => 'X',
                'base_url' => 'not-a-url',
                'model' => 'whisper-1',
            ],
        ]));
        expect($createResp->getStatusCode())->toBe(422);
        $body = json_decode($createResp->getContent(), true);
        expect($body['error']['code'])->toBe('SPEECH_PROVIDER_CONFIG_INVALID');
    });

    it('round-trips api_key as "***" on read; sending "***" keeps the existing value', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-mask@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $userId);

        // Initial create with a real key
        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'global',
            'settings' => [
                'api_key' => 'sk-real-1',
                'display_name' => 'M',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
            ],
        ]));
        expect($createResp->getStatusCode())->toBe(200);
        $createBody = json_decode($createResp->getContent(), true);
        expect($createBody['data']['config']['settings']['api_key'])->toBe('***');
        $configId = $createBody['data']['config']['id'];

        // Send "***" as the api_key — value should not change.
        $updateResp = $controller->update($configId, jsonSpcRequest('PUT', "/api/v1/speech/provider-configs/{$configId}", [
            'settings' => [
                'api_key' => '***',
                'display_name' => 'M (updated)',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
            ],
        ]));
        expect($updateResp->getStatusCode())->toBe(200);

        // The stored key (read by ToolConfigService::getGlobalSettings) must
        // still be the original — confirm via direct call to the service.
        $global = $toolConfig = (new ToolConfigService(
            new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            new \Psr\Log\NullLogger(),
            [],
        ));
        $stored = $global->getGlobalSettings(OpenAiCompatibleTranscriber::class);
        expect($stored['api_key'])->toBe('sk-real-1');
    });

    // The frontend PUTs only the fields the operator changed (`api_key`
    // is omitted whenever the operator intended to keep the existing
    // secret; see `SpeechProviderConfigForm.vue::buildSettingsToSend`).
    // The schema marks `api_key` as `required`, so a naive validator
    // rejects the omitted-and-kept case with 422. The service merges
    // existing storage into the request before validating, so the
    // schema sees the full set, the password is preserved on disk,
    // and the changed fields land in their new values.
    it('PUT with api_key omitted keeps the existing key (server merges storage before validating)', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-partial@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $userId);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'global',
            'settings' => [
                'api_key' => 'sk-original',
                'display_name' => 'Mistral Voxtral',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
            ],
        ]));
        expect($createResp->getStatusCode())->toBe(200);
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        // Operator changed display_name only — omit api_key entirely.
        $updateResp = $controller->update($configId, jsonSpcRequest('PUT', "/api/v1/speech/provider-configs/{$configId}", [
            'settings' => [
                'display_name' => 'Mistral Voxtral (renamed)',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
            ],
        ]));
        expect($updateResp->getStatusCode())->toBe(200);
        $body = json_decode($updateResp->getContent(), true);
        expect($body['data']['config']['settings']['api_key'])->toBe('***');
        expect($body['data']['config']['settings']['display_name'])->toBe('Mistral Voxtral (renamed)');

        // The stored key must be unchanged — confirm via direct service read.
        $global = new ToolConfigService(
            new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            new \Psr\Log\NullLogger(),
            [],
        );
        $stored = $global->getGlobalSettings(OpenAiCompatibleTranscriber::class);
        expect($stored['api_key'])->toBe('sk-original');
        expect($stored['display_name'])->toBe('Mistral Voxtral (renamed)');
    });

    it('returns 403 (forbidden) for anonymous index requests', function (): void {
        [$controller] = makeSpeechProviderConfigController();
        // No session — currentUserId() returns null → requireUserId throws.
        $resp = $controller->index(jsonSpcRequest("GET", "/api/v1/speech/provider-configs"));
        expect($resp->getStatusCode())->toBe(403);
    });

    // Regression: an admin who creates a user-scope override (no auth
    // check in upsertUserConfig) used to be unable to see it on the
    // list endpoint — listConfigs short-circuited on the admin branch
    // and returned only globals. Admins may want a personal override
    // separate from the global default, so the endpoint now returns
    // both for admins. Non-admins still see only their user-scope.
    it('admin sees both globals and their own user-scope configs in the list', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-admin-mixed@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $userId);

        $globalResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'global',
            'settings' => [
                'api_key' => 'sk-global',
                'display_name' => 'Global Mistral',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
            ],
        ]));
        expect($globalResp->getStatusCode())->toBe(200);

        $userResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'user',
            'settings' => [
                'api_key' => 'sk-personal',
                'display_name' => 'Personal Mistral',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
            ],
        ]));
        expect($userResp->getStatusCode())->toBe(200);
        $userConfigId = json_decode($userResp->getContent(), true)['data']['config']['id'];

        $listResp = $controller->index(jsonSpcRequest('GET', '/api/v1/speech/provider-configs'));
        expect($listResp->getStatusCode())->toBe(200);
        $list = json_decode($listResp->getContent(), true)['data']['configs'];

        $scopes = array_column($list, 'scope');
        expect($scopes)->toContain('global');
        expect($scopes)->toContain('user');
        expect(array_filter($list, static fn($c) => $c['id'] === $userConfigId))->not->toBeEmpty();
    });

    it('non-admin still does NOT see globals in the list', function (): void {
        // Defensive: this describe block's afterEach clears tool_configurations
        // and tool_user_settings, but a global could leak from a different
        // test if execution order ever changed. Wipe both before asserting.
        Capsule::table('tool_configurations')->delete();
        Capsule::table('tool_user_settings')->delete();

        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-nonadmin-still@example.com', SPC_TEST_PASSWORD);

        $userResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'user',
            'settings' => [
                'api_key' => 'sk-personal',
                'display_name' => 'Personal Mistral',
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'voxtral-mini-latest',
            ],
        ]));
        expect($userResp->getStatusCode())->toBe(200);

        $listResp = $controller->index(jsonSpcRequest('GET', '/api/v1/speech/provider-configs'));
        $list = json_decode($listResp->getContent(), true)['data']['configs'];

        $scopes = array_column($list, 'scope');
        expect($scopes)->not->toContain('global');
        expect($scopes)->toContain('user');
    });
});

describe('SpeechProviderConfigController — scope=group', function (): void {
    beforeEach(function (): void {
        clearSession();
    });

    afterEach(function (): void {
        clearSession();
        Capsule::table('tool_user_settings')->delete();
        Capsule::table('tool_configurations')->delete();
        Capsule::table('group_memberships')->delete();
        Capsule::table('groups')->delete();
        Capsule::table('principals')->where('type', 'group')->delete();
    });

    it('200: group admin creates a group-scoped config; principal_id points at the group-principal', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-group-owner@example.com', SPC_TEST_PASSWORD);

        $groupService = new \Spora\Services\GroupService(new PrincipalService(new PrincipalResolver()));
        $group = $groupService->createGroup($ownerId, 'SpCGrpA');
        $groupPrincipalId = (int) Capsule::table('principals')
            ->where('type', \Spora\Models\Principal::TYPE_GROUP)
            ->where('group_id', $group->id)
            ->value('id');

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'group',
            'group_id'       => (int) $group->id,
            'settings'       => [
                'api_key'      => 'sk-grp',
                'display_name' => 'Group Mistral',
                'base_url'     => 'https://api.mistral.ai/v1',
                'model'        => 'voxtral-mini-latest',
            ],
        ]));
        expect($resp->getStatusCode())->toBe(200);
        $body = json_decode($resp->getContent(), true);
        expect($body['data']['config']['scope'])->toBe('group');
        expect($body['data']['config']['principal_id'])->toBe($groupPrincipalId);
        expect($body['data']['config']['settings']['api_key'])->toBe('***');

        // Row actually landed in tool_user_settings keyed by the group-principal id.
        $rowCount = Capsule::table('tool_user_settings')
            ->where('principal_id', $groupPrincipalId)
            ->count();
        expect($rowCount)->toBe(1);
    });

    it('403: a member (non-admin) of the group cannot create a group-scoped config', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-grp-memb-owner@example.com', SPC_TEST_PASSWORD);
        $groupService = new \Spora\Services\GroupService(new PrincipalService(new PrincipalResolver()));
        $group = $groupService->createGroup($ownerId, 'SpCGrpMember');

        // Add the caller as a member (not admin) and switch the session.
        $memberId = bootAuth($auth, 'spc-grp-memb@example.com', SPC_TEST_PASSWORD);
        $groupService->addMember((int) $group->id, $memberId, \Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);
        clearSession();
        simulateLoggedInSession($memberId, 'spc-grp-memb@example.com');

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'group',
            'group_id'       => (int) $group->id,
            'settings'       => [
                'api_key'      => 'sk-grp',
                'display_name' => 'X',
                'base_url'     => 'https://api.openai.com/v1',
                'model'        => 'whisper-1',
            ],
        ]));
        expect($resp->getStatusCode())->toBe(403);
        $body = json_decode($resp->getContent(), true);
        expect($body['error']['code'])->toBe('SPEECH_PROVIDER_CONFIG_FORBIDDEN');
    });

    it('200: global admin can create a group-scoped config on any group', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-grp-admin-owner@example.com', SPC_TEST_PASSWORD);
        $groupService = new \Spora\Services\GroupService(new PrincipalService(new PrincipalResolver()));
        $group = $groupService->createGroup($ownerId, 'SpCGrpAdmin');

        $adminId = bootAuth($auth, 'spc-grp-admin@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $adminId);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'group',
            'group_id'       => (int) $group->id,
            'settings'       => [
                'api_key'      => 'sk-admin-grp',
                'display_name' => 'Admin-set',
                'base_url'     => 'https://api.openai.com/v1',
                'model'        => 'whisper-1',
            ],
        ]));
        expect($resp->getStatusCode())->toBe(200);
        expect(json_decode($resp->getContent(), true)['data']['config']['scope'])->toBe('group');
    });

    it('422: scope=group without group_id is rejected', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-grp-noid-owner@example.com', SPC_TEST_PASSWORD);
        $groupService = new \Spora\Services\GroupService(new PrincipalService(new PrincipalResolver()));
        $groupService->createGroup($ownerId, 'SpCGrpNoId');

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'group',
            'settings'       => [
                'api_key'      => 'sk-x',
                'display_name' => 'X',
                'base_url'     => 'https://api.openai.com/v1',
                'model'        => 'whisper-1',
            ],
        ]));
        expect($resp->getStatusCode())->toBe(422);
        $body = json_decode($resp->getContent(), true);
        expect($body['error']['code'])->toBe('SPEECH_PROVIDER_CONFIG_INVALID');
    });

    it('422: scope=user with a stray group_id is rejected', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        bootAuth($auth, 'spc-stray-gid@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'user',
            'group_id'       => 7, // stray
            'settings'       => [
                'api_key'      => 'sk-x',
                'display_name' => 'X',
                'base_url'     => 'https://api.openai.com/v1',
                'model'        => 'whisper-1',
            ],
        ]));
        expect($resp->getStatusCode())->toBe(422);
    });

    it('GET ?group_id=N returns only that group\'s configs to a member', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-list-owner@example.com', SPC_TEST_PASSWORD);
        $groupService = new \Spora\Services\GroupService(new PrincipalService(new PrincipalResolver()));
        $groupA = $groupService->createGroup($ownerId, 'SpCListA');
        $groupB = $groupService->createGroup($ownerId, 'SpCListB');

        // Owner pre-populates a config on each group.
        $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'group', 'group_id' => (int) $groupA->id,
            'settings'       => [
                'api_key' => 'sk-a', 'display_name' => 'A',
                'base_url' => 'https://api.mistral.ai/v1', 'model' => 'voxtral-mini-latest',
            ],
        ]));
        $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'group', 'group_id' => (int) $groupB->id,
            'settings'       => [
                'api_key' => 'sk-b', 'display_name' => 'B',
                'base_url' => 'https://api.mistral.ai/v1', 'model' => 'voxtral-mini-latest',
            ],
        ]));

        // Add a member and ask for only group A.
        $memberId = bootAuth($auth, 'spc-list-memb@example.com', SPC_TEST_PASSWORD);
        $groupService->addMember((int) $groupA->id, $memberId, \Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);
        $groupService->addMember((int) $groupB->id, $memberId, \Spora\Models\GroupMembership::ROLE_MEMBER, $ownerId);
        clearSession();
        simulateLoggedInSession($memberId, 'spc-list-memb@example.com');

        $resp = $controller->index(Request::create(
            '/api/v1/speech/provider-configs?group_id=' . $groupA->id,
            'GET',
        ));
        expect($resp->getStatusCode())->toBe(200);
        $body = json_decode($resp->getContent(), true);
        expect($body['data']['configs'])->toHaveCount(1);
        expect($body['data']['configs'][0]['scope'])->toBe('group');
        expect($body['data']['configs'][0]['settings']['display_name'])->toBe('A');
    });

    it('GET ?group_id=N returns empty list to a non-member (existence-hide)', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-list-nonmem-owner@example.com', SPC_TEST_PASSWORD);
        $groupService = new \Spora\Services\GroupService(new PrincipalService(new PrincipalResolver()));
        $group = $groupService->createGroup($ownerId, 'SpCListNonMem');

        // Owner writes a config; stranger does NOT join the group.
        $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'group', 'group_id' => (int) $group->id,
            'settings'       => [
                'api_key' => 'sk-a', 'display_name' => 'A',
                'base_url' => 'https://api.mistral.ai/v1', 'model' => 'voxtral-mini-latest',
            ],
        ]));

        $strangerId = bootAuth($auth, 'spc-list-stranger@example.com', SPC_TEST_PASSWORD);
        clearSession();
        simulateLoggedInSession($strangerId, 'spc-list-stranger@example.com');

        $resp = $controller->index(Request::create(
            '/api/v1/speech/provider-configs?group_id=' . $group->id,
            'GET',
        ));
        expect($resp->getStatusCode())->toBe(200);
        $body = json_decode($resp->getContent(), true);
        expect($body['data']['configs'])->toBe([]);
    });

    it('GET without ?group_id still returns the legacy scopes (no behaviour change)', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-list-legacy@example.com', SPC_TEST_PASSWORD);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'user',
            'settings'       => [
                'api_key' => 'sk-pers', 'display_name' => 'Personal',
                'base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1',
            ],
        ]));
        expect($createResp->getStatusCode())->toBe(200);

        $listResp = $controller->index(jsonSpcRequest('GET', '/api/v1/speech/provider-configs'));
        $body = json_decode($listResp->getContent(), true);
        // Old behaviour: non-admin sees own user-scoped configs, not groups.
        expect($body['data']['configs'])->toHaveCount(1);
        expect($body['data']['configs'][0]['scope'])->toBe('user');
    });

    it('PUT on a group-scoped id by the group admin updates settings and keeps scope=group', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-grp-put-owner@example.com', SPC_TEST_PASSWORD);
        $groupService = new \Spora\Services\GroupService(new PrincipalService(new PrincipalResolver()));
        $group = $groupService->createGroup($ownerId, 'SpCGrpPut');

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'group', 'group_id' => (int) $group->id,
            'settings'       => [
                'api_key' => 'sk-1', 'display_name' => 'v1',
                'base_url' => 'https://api.mistral.ai/v1', 'model' => 'voxtral-mini-latest',
            ],
        ]));
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        $putResp = $controller->update($configId, jsonSpcRequest('PUT', "/api/v1/speech/provider-configs/{$configId}", [
            'settings' => [
                'display_name' => 'v2',
                'base_url'     => 'https://api.mistral.ai/v1',
                'model'        => 'voxtral-mini-latest',
                'api_key'      => 'sk-1',
            ],
        ]));
        expect($putResp->getStatusCode())->toBe(200);
        $body = json_decode($putResp->getContent(), true);
        expect($body['data']['config']['scope'])->toBe('group');
        expect($body['data']['config']['settings']['display_name'])->toBe('v2');
    });

    it('DELETE on a group-scoped id by the group admin removes the row', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-grp-del-owner@example.com', SPC_TEST_PASSWORD);
        $groupService = new \Spora\Services\GroupService(new PrincipalService(new PrincipalResolver()));
        $group = $groupService->createGroup($ownerId, 'SpCGrpDel');

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope'          => 'group', 'group_id' => (int) $group->id,
            'settings'       => [
                'api_key' => 'sk-1', 'display_name' => 'X',
                'base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1',
            ],
        ]));
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        $delResp = $controller->destroy($configId);
        expect($delResp->getStatusCode())->toBe(200);
        expect(Capsule::table('tool_user_settings')
            ->where('id', $configId)
            ->exists())->toBeFalse();
    });
});
