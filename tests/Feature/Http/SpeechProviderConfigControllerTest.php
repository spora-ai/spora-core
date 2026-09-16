<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Http\SpeechProviderConfigController;
use Spora\Models\GroupMembership;
use Spora\Models\SpeechProviderConfiguration;
use Spora\Services\AgentService;
use Spora\Services\GroupService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\SpeechProviderConfigPersistence;
use Spora\Services\SpeechProviderConfigPreferences;
use Spora\Services\SpeechProviderConfigService;
use Spora\Services\SpeechProviderConfigValidator;
use Spora\Services\ToolConfigService;
use Spora\Speech\OpenAiCompatibleTranscriber;
use Spora\Speech\SpeechToTextRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

const SPC_TEST_PASSWORD = 'Password1!';

/**
 * Wire the controller graph against the real SecurityManager +
 * ToolConfigService so the per-field encryption round-trip matches
 * production. Returns the controller + AuthService so callers can
 * drive the auth-aware endpoints.
 *
 * @return array{0: SpeechProviderConfigController, 1: AuthService}
 */
function makeSpeechProviderConfigController(): array
{
    $auth = bootAuthLayer();
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new ToolConfigService($security, new \Psr\Log\NullLogger(), []);
    $principalService = new PrincipalService(new PrincipalResolver());

    $registry = new SpeechToTextRegistry([
        new OpenAiCompatibleTranscriber(new \Symfony\Component\HttpClient\MockHttpClient(), $toolConfig),
    ], $principalService);

    $validator = new SpeechProviderConfigValidator($registry);
    $persistence = new SpeechProviderConfigPersistence($security, $validator, static fn(): SpeechToTextRegistry => $registry);
    $preferences = new SpeechProviderConfigPreferences($principalService);
    $service = new SpeechProviderConfigService($validator, $persistence, $preferences, $principalService);
    $agentService = new AgentService();

    return [new SpeechProviderConfigController($auth, $service, $registry, $agentService), $auth];
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

function indexSpcRequest(string $query = ''): Request
{
    $uri = $query === '' ? '/api/v1/speech/provider-configs' : '/api/v1/speech/provider-configs?' . $query;
    return Request::create($uri, 'GET');
}

function fullSettings(string $apiKey = 'sk-test'): array
{
    return [
        'api_key' => $apiKey,
        'display_name' => 'Mistral Voxtral',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'whisper-1',
    ];
}

describe('SpeechProviderConfigController', function (): void {
    beforeEach(function () {
        clearSession();
        Capsule::table('speech_provider_configurations')->delete();
        Capsule::table('principal_preferences')->delete();
        Capsule::table('agents')->delete();
        Capsule::table('group_memberships')->delete();
        Capsule::table('principals')->where('type', 'group')->delete();
        Capsule::table('groups')->delete();
    });

    afterEach(function () {
        clearSession();
        Capsule::table('speech_provider_configurations')->delete();
        Capsule::table('principal_preferences')->delete();
        Capsule::table('agents')->delete();
        Capsule::table('group_memberships')->delete();
        Capsule::table('principals')->where('type', 'group')->delete();
        Capsule::table('groups')->delete();
    });

    it('returns 401 for an unauthenticated caller on index', function (): void {
        [$controller] = makeSpeechProviderConfigController();
        $resp = $controller->index(indexSpcRequest());
        expect($resp->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });

    it('list returns 200 with an empty list for a fresh user', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-empty@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->index(indexSpcRequest());
        expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
        expect(json_decode($resp->getContent(), true)['data']['configs'])->toBe([]);
    });

    it('admin: create a global config returns 201 with masked api_key', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-admin@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $userId);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'display_name' => 'OAI Global',
            'settings' => fullSettings('sk-mistral'),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($resp->getContent(), true);
        expect($body['data']['config']['provider_class'])->toBe(OpenAiCompatibleTranscriber::class);
        expect($body['data']['config']['is_global'])->toBeTrue();
        expect($body['data']['config']['settings']['api_key'])->toBe('***');
    });

    it('non-admin: POST with is_global=true returns 403', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-nonadmin@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => fullSettings(),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    });

    it('non-admin: creates a per-user config under their user-principal', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-user@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'settings' => fullSettings('sk-personal'),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($resp->getContent(), true);
        expect($body['data']['config']['is_global'])->toBeFalse();
        expect($body['data']['config']['principal_id'])->toBeGreaterThan(0);
    });

    it('update merges existing settings so omitted-and-kept values survive', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-update@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $userId);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'display_name' => 'Original',
            'settings' => fullSettings('sk-kept'),
        ]));
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        $updateResp = $controller->update($configId, jsonSpcRequest('PUT', '/api/v1/speech/provider-configs/x', [
            'display_name' => 'Renamed',
            'settings' => fullSettings('sk-kept'),
        ]));
        expect($updateResp->getStatusCode())->toBe(Response::HTTP_OK);

        $config = SpeechProviderConfiguration::find($configId);
        expect($config->display_name)->toBe('Renamed');
    });

    it('delete returns 200 and removes the row', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-delete@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $userId);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => fullSettings('sk-x'),
        ]));
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        $delResp = $controller->destroy($configId);
        expect($delResp->getStatusCode())->toBe(Response::HTTP_OK);
        expect(SpeechProviderConfiguration::find($configId))->toBeNull();
    });

    it('non-admin cannot update or delete another user\'s config (404 — invisible to caller)', function (): void {
        // Existence-hide: user B can't see user A's per-user config
        // at all (different user-principal), so the controller must
        // surface 404 not 403. Mirrors the LLM-config visibility rule.
        [$controller, $auth] = makeSpeechProviderConfigController();

        // user A creates a config
        $userA = bootAuth($auth, 'spc-a@example.com', SPC_TEST_PASSWORD);
        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'settings' => fullSettings('sk-a'),
        ]));
        expect($createResp->getStatusCode())->toBe(Response::HTTP_CREATED);
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        // Switch to user B
        clearSession();
        $userB = bootAuth($auth, 'spc-b@example.com', SPC_TEST_PASSWORD);

        $updateResp = $controller->update($configId, jsonSpcRequest('PUT', '/api/v1/speech/provider-configs/x', [
            'settings' => fullSettings('sk-b'),
        ]));
        expect($updateResp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);

        $delResp = $controller->destroy($configId);
        expect($delResp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    it('non-admin: 403 for visible-but-not-owned (admin-only global config)', function (): void {
        // An admin creates a global config. A non-admin can SEE the
        // row (globals are visible to everyone) but cannot edit it
        // → 403, not 404. Pins the visibility-vs-editability split
        // introduced by C3.
        [$controller, $auth] = makeSpeechProviderConfigController();
        $adminId = bootAuth($auth, 'spc-admin-global@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $adminId);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => fullSettings('sk-global'),
        ]));
        expect($createResp->getStatusCode())->toBe(Response::HTTP_CREATED);
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        clearSession();
        $nonAdminId = bootAuth($auth, 'spc-nonadmin-global@example.com', SPC_TEST_PASSWORD);

        $updateResp = $controller->update($configId, jsonSpcRequest('PUT', '/api/v1/speech/provider-configs/x', [
            'settings' => fullSettings('sk-x'),
        ]));
        expect($updateResp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);

        $delResp = $controller->destroy($configId);
        expect($delResp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    });

    it('store with unknown provider_class returns 404 (provider not registered)', function (): void {
        // Regression for C4: an unknown `provider_class` must throw
        // `notFound()` (HTTP 404) — the controller maps it to a
        // 404 envelope with `SPEECH_PROVIDER_CONFIG_NOT_FOUND`.
        [$controller, $auth] = makeSpeechProviderConfigController();
        bootAuth($auth, 'spc-unknown-class@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => 'Spora\\Speech\\NotARealProvider',
            'settings' => fullSettings(),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
        $body = json_decode($resp->getContent(), true);
        expect($body['error']['code'])->toBe('SPEECH_PROVIDER_CONFIG_NOT_FOUND');
    });

    it('store with missing required setting returns 422 (validation)', function (): void {
        // Regression for C4: a registered provider class without
        // a required `api_key` throws `validation()` → 422.
        [$controller, $auth] = makeSpeechProviderConfigController();
        bootAuth($auth, 'spc-validation@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'settings' => [], // api_key required
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = json_decode($resp->getContent(), true);
        expect($body['error']['code'])->toBe('SPEECH_PROVIDER_CONFIG_INVALID');
    });

    it('set-default promotes one global config (admin only)', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-default@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $userId);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => fullSettings('sk-default'),
        ]));
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        $resp = $controller->setDefault($configId);
        expect($resp->getStatusCode())->toBe(Response::HTTP_OK);

        $config = SpeechProviderConfiguration::find($configId);
        expect($config->is_default)->toBeTrue();
    });

    it('non-admin cannot set the default (403)', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $adminId = bootAuth($auth, 'spc-admin2@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $adminId);

        $createResp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => fullSettings('sk-def'),
        ]));
        $configId = json_decode($createResp->getContent(), true)['data']['config']['id'];

        clearSession();
        $userB = bootAuth($auth, 'spc-nonadmin2@example.com', SPC_TEST_PASSWORD);
        $resp = $controller->setDefault($configId);
        expect($resp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    });

    // ---------------------------------------------------------------
    // SPA wire-shape: scope / group_id / display_name (LLM-parity)
    // ---------------------------------------------------------------

    it('admin: scope=global in the body translates to is_global=true with null principal_id', function (): void {
        // Regression for the wire-shape mismatch: SPA used to send
        // `{ scope: 'global' }` but the new backend ignored `scope`
        // and defaulted principal_id to the caller's user-principal —
        // the row landed as user-scope, not global.
        [$controller, $auth] = makeSpeechProviderConfigController();
        $adminId = bootAuth($auth, 'spc-wire-global@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $adminId);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'global',
            'display_name' => 'OAI Global (wired)',
            'settings' => fullSettings('sk-global'),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($resp->getContent(), true);
        expect($body['data']['config']['is_global'])->toBeTrue();
        expect($body['data']['config']['principal_id'])->toBeNull();
        expect($body['data']['config']['display_name'])->toBe('OAI Global (wired)');

        $row = SpeechProviderConfiguration::find($body['data']['config']['id']);
        expect($row->is_global)->toBeTrue();
        expect($row->principal_id)->toBeNull();
        expect($row->display_name)->toBe('OAI Global (wired)');
    });

    it('non-admin: scope=global returns 403 (admin-only gate)', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        bootAuth($auth, 'spc-wire-global-nonadmin@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'global',
            'settings' => fullSettings(),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    });

    it('group owner: scope=group + group_id lands on the group-principal', function (): void {
        // The SPA passes `groups.id` in `group_id`; the controller must
        // resolve it to the matching `principals.id` before persistence
        // and reject when the caller cannot manage the group.
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-group-owner@example.com', SPC_TEST_PASSWORD);
        $principalService = new PrincipalService(new PrincipalResolver());
        // Materialise the user-principal so `PrincipalResolver::visiblePrincipalIds`
        // returns the caller's group-principals. (Without this the resolver
        // short-circuits to [] — the user-principal row is lazy and only
        // gets created by `ensureUserPrincipal` on a user-scope write.)
        $principalService->ensureUserPrincipal($ownerId);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'SpcGroup');
        $expectedPrincipalId = (int) $principalService->principalForGroup($group->id)->id;

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'group',
            'group_id' => $group->id,
            'settings' => fullSettings('sk-team'),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($resp->getContent(), true);
        expect($body['data']['config']['is_global'])->toBeFalse();
        expect((int) $body['data']['config']['principal_id'])->toBe($expectedPrincipalId);

        // The scope / group_id keys must NOT leak into the persisted
        // settings blob — only the schema-allowed keys should round-trip.
        $row = SpeechProviderConfiguration::find($body['data']['config']['id']);
        expect($row->principal_id)->toBe($expectedPrincipalId);
        $decoded = json_decode($row->getRawOriginal('settings'), true);
        expect($decoded)->not->toHaveKey('scope');
        expect($decoded)->not->toHaveKey('group_id');
    });

    it('group non-member: scope=group + group_id returns 403', function (): void {
        // Owner creates the group; an unrelated caller tries to write
        // a config scoped to it. The controller must refuse.
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-group-owner-2@example.com', SPC_TEST_PASSWORD);
        $principalService = new PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $group = (new GroupService($principalService))->createGroup($ownerId, 'SpcGroupPrivate');

        clearSession();
        $outsiderId = bootAuth($auth, 'spc-group-outsider@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'group',
            'group_id' => $group->id,
            'settings' => fullSettings('sk-x'),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        expect(SpeechProviderConfiguration::count())->toBe(0);
    });

    it('group admin: scope=group + group_id is accepted', function (): void {
        // Mirrors the LLM flow: `owner` AND `admin` roles can manage
        // a group's settings; `member` cannot. This covers the admin
        // branch of `GroupService::callerCanManage`.
        [$controller, $auth] = makeSpeechProviderConfigController();
        $ownerId = bootAuth($auth, 'spc-group-owner-3@example.com', SPC_TEST_PASSWORD);
        $principalService = new PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $groupService = new GroupService($principalService);
        $group = $groupService->createGroup($ownerId, 'SpcGroupAdmin');

        clearSession();
        $adminId = bootAuth($auth, 'spc-group-admin@example.com', SPC_TEST_PASSWORD);
        $principalService->ensureUserPrincipal($adminId);
        $groupService->addMember((int) $group->id, $adminId, GroupMembership::ROLE_ADMIN, $ownerId);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'group',
            'group_id' => $group->id,
            'settings' => fullSettings('sk-admin'),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($resp->getContent(), true);
        expect($body['data']['config']['is_global'])->toBeFalse();
        expect((int) $body['data']['config']['principal_id'])->toBeGreaterThan(0);
    });

    it('scope=group without group_id returns 403 (no implicit principal to resolve)', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        bootAuth($auth, 'spc-group-noid@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'group',
            'settings' => fullSettings('sk-x'),
        ]));

        expect($resp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
        expect(SpeechProviderConfiguration::count())->toBe(0);
    });

    it('schema() lists every registered STT class with its settings_schema', function (): void {
        [$controller] = makeSpeechProviderConfigController();

        $resp = $controller->schema();
        expect($resp->getStatusCode())->toBe(Response::HTTP_OK);

        $providers = json_decode($resp->getContent(), true)['data']['providers'];
        $classes = array_column($providers, 'class');
        expect($classes)->toContain(OpenAiCompatibleTranscriber::class);

        $oai = array_values(array_filter(
            $providers,
            static fn(array $p): bool => $p['class'] === OpenAiCompatibleTranscriber::class,
        ))[0];
        $keys = array_column($oai['settings_schema'], 'key');
        expect($keys)->toContain('api_key');
    });

    it('schema marks base_url and model as optional when they have defaults (regression)', function (): void {
        // base_url and model carry `default:` values, so they should
        // not be marked required — the SPA pre-fills the default and
        // an operator who only sets the API key shouldn't be blocked
        // by a missing-field error.
        [$controller] = makeSpeechProviderConfigController();
        $providers = json_decode($controller->schema()->getContent(), true)['data']['providers'];
        $oai = array_values(array_filter(
            $providers,
            static fn(array $p): bool => $p['class'] === OpenAiCompatibleTranscriber::class,
        ))[0];
        $byKey = [];
        foreach ($oai['settings_schema'] as $field) {
            $byKey[$field['key']] = $field;
        }
        expect($byKey['base_url']['required'])->toBeFalse();
        expect($byKey['base_url']['default'])->toBe('https://api.openai.com/v1');
        expect($byKey['model']['required'])->toBeFalse();
        expect($byKey['model']['default'])->toBe('whisper-1');
        // display_name is still required — operators must label their config.
        expect($byKey['display_name']['required'])->toBeTrue();
    });

    // ---------------------------------------------------------------
    // SPA wire-shape response: scope + provider_name + provider_display_name
    //
    // Regression for the second wire-shape mismatch: the SPA's
    // `stores/speechProviderConfigs.ts` filters the list by
    // `c.scope === 'user'|'global'|'group'` and the SPA's
    // `SpeechProviderConfig` type requires `provider_display_name`. The
    // previous request-side fix (PR #144) only updated POST/PUT bodies,
    // so the GET response was still missing both fields and every list
    // entry fell into the empty-store bucket.
    // ---------------------------------------------------------------

    it('list response includes scope and provider_display_name for every config', function (): void {
        // Seed one of each scope (global, user, group) and confirm the
        // GET response now exposes `scope`, `provider_name`, and
        // `provider_display_name` on every entry.
        [$controller, $auth] = makeSpeechProviderConfigController();
        $adminId = bootAuth($auth, 'spc-shape-admin@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $adminId);

        $principalService = new PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($adminId);
        $group = (new GroupService($principalService))->createGroup($adminId, 'SpcShapeGroup');
        $groupPrincipalId = (int) $principalService->principalForGroup($group->id)->id;

        // Global row.
        $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'display_name' => 'Shape Global',
            'settings' => fullSettings('sk-shape-global'),
        ]));

        // User row.
        $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'display_name' => 'Shape Personal',
            'settings' => fullSettings('sk-shape-personal'),
        ]));

        // Group row.
        $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'group',
            'group_id' => $group->id,
            'display_name' => 'Shape Team',
            'settings' => fullSettings('sk-shape-team'),
        ]));

        $resp = $controller->index(indexSpcRequest());
        expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
        $configs = json_decode($resp->getContent(), true)['data']['configs'];
        expect($configs)->toHaveCount(3);

        foreach ($configs as $config) {
            expect($config)->toHaveKey('scope');
            expect($config['scope'])->toBeIn(['global', 'user', 'group']);
            expect($config)->toHaveKey('provider_name');
            expect($config['provider_name'])->toBeString()->not()->toBeEmpty();
            expect($config)->toHaveKey('provider_display_name');
            expect($config['provider_display_name'])->toBeString()->not()->toBeEmpty();
            // provider_name/display_name pair from the registry must
            // align with the row's provider_class.
            expect($config['provider_class'])->toBe(OpenAiCompatibleTranscriber::class);
        }
    });

    it('list response: global configs report scope=global', function (): void {
        // Pins the derived `scope` value so the SPA's global-list filter
        // populates from the API response.
        [$controller, $auth] = makeSpeechProviderConfigController();
        $adminId = bootAuth($auth, 'spc-shape-global@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $adminId);

        $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => fullSettings('sk-sg'),
        ]));

        $resp = $controller->index(indexSpcRequest());
        $configs = json_decode($resp->getContent(), true)['data']['configs'];
        expect($configs[0]['scope'])->toBe('global');
        expect($configs[0]['is_global'])->toBeTrue();
        expect($configs[0]['principal_id'])->toBeNull();
    });

    it('list response: user-scoped configs report scope=user', function (): void {
        // The non-admin branch writes under the caller's user-principal,
        // so the derived scope must be 'user' (not 'group', not 'global').
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-shape-user@example.com', SPC_TEST_PASSWORD);

        $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'settings' => fullSettings('sk-su'),
        ]));

        $resp = $controller->index(indexSpcRequest());
        $configs = json_decode($resp->getContent(), true)['data']['configs'];
        expect($configs[0]['scope'])->toBe('user');
        expect($configs[0]['is_global'])->toBeFalse();
        expect($configs[0]['principal_id'])->toBeGreaterThan(0);
    });

    it('list response: group-scoped configs report scope=group', function (): void {
        // Seed a group + group-principal + a group-scope config; the
        // serializer must derive scope=group from the principal's type,
        // not from the raw `is_global` + `principal_id` pair.
        [$controller, $auth] = makeSpeechProviderConfigController();
        $adminId = bootAuth($auth, 'spc-shape-group@example.com', SPC_TEST_PASSWORD);
        makeAdmin($auth, $adminId);

        $principalService = new PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($adminId);
        $group = (new GroupService($principalService))->createGroup($adminId, 'SpcShapeTeam');
        $groupPrincipalId = (int) $principalService->principalForGroup($group->id)->id;

        $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'scope' => 'group',
            'group_id' => $group->id,
            'settings' => fullSettings('sk-st'),
        ]));

        $resp = $controller->index(indexSpcRequest());
        $configs = json_decode($resp->getContent(), true)['data']['configs'];
        expect($configs[0]['scope'])->toBe('group');
        expect($configs[0]['is_global'])->toBeFalse();
        expect((int) $configs[0]['principal_id'])->toBe($groupPrincipalId);
    });

    describe('GET /api/v1/speech/provider-configs?agent_id=N', function (): void {
        it('narrows to the agent-principal scope for a user-owned agent', function (): void {
            [$controller, $auth] = makeSpeechProviderConfigController();
            $callerId = bootAuth($auth, 'spc-scope-caller@example.com', SPC_TEST_PASSWORD);
            makeAdmin($auth, $callerId);

            // Global config visible to every agent.
            $global = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'is_global' => true,
                'settings' => fullSettings('sk-scope-global'),
            ]));
            $globalId = (int) json_decode($global->getContent(), true)['data']['config']['id'];

            // User-scoped config under caller's user-principal.
            $userConfig = $controller->store(jsonSpcRequest('POST', '/api/v1/speech/provider-configs', [
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'settings' => fullSettings('sk-scope-user'),
            ]));
            $userConfigId = (int) json_decode($userConfig->getContent(), true)['data']['config']['id'];

            // Seed an unrelated user's config — must NOT leak.
            $strangerId = bootAuth($auth, 'spc-scope-stranger@example.com', SPC_TEST_PASSWORD);
            $strangerPrincipalId = (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($strangerId)->id;
            $strangerConfigId = (int) Capsule::table('speech_provider_configurations')->insertGetId([
                'principal_id' => $strangerPrincipalId,
                'provider_class' => OpenAiCompatibleTranscriber::class,
                'display_name' => 'Stranger',
                'is_default' => false,
                'is_global' => false,
                'settings' => json_encode(['api_key' => 'sk-stranger', 'display_name' => 'Stranger', 'base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1']),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Re-authenticate as the caller — the helper above switched the
            // session to the stranger user.
            simulateLoggedInSession($callerId, 'spc-scope-caller@example.com');

            // Agent owned by the caller.
            $agentId = (int) Capsule::table('agents')->insertGetId([
                'principal_id' => (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($callerId)->id,
                'name' => 'Caller Agent',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $resp = $controller->index(indexSpcRequest("agent_id={$agentId}"));
            expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
            $ids = array_map(static fn(array $r): int => (int) $r['id'], json_decode($resp->getContent(), true)['data']['configs']);
            sort($ids);

            expect($ids)->toBe([min($globalId, $userConfigId), max($globalId, $userConfigId)])
                ->and($ids)->not->toContain($strangerConfigId);
        });

        it('returns an empty list when the agent row does not exist', function (): void {
            [$controller, $auth] = makeSpeechProviderConfigController();
            $userId = bootAuth($auth, 'spc-scope-missing-agent@example.com', SPC_TEST_PASSWORD);

            $resp = $controller->index(indexSpcRequest('agent_id=999999999'));
            expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
            expect(json_decode($resp->getContent(), true)['data']['configs'])->toBe([]);
        });

        it('returns an empty list when the caller cannot see the agent and is not admin', function (): void {
            // Two users; A owns the agent, B queries ?agent_id=A's agent.
            // Without admin status, the visibility check returns null and
            // the response is empty (existence-hide).
            [$controller, $auth] = makeSpeechProviderConfigController();
            $ownerId = bootAuth($auth, 'spc-scope-owner@example.com', SPC_TEST_PASSWORD);
            $agentId = (int) Capsule::table('agents')->insertGetId([
                'principal_id' => (new PrincipalService(new PrincipalResolver()))->ensureUserPrincipal($ownerId)->id,
                'name' => 'Owner Agent',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Authenticate as a different caller.
            bootAuth($auth, 'spc-scope-other@example.com', SPC_TEST_PASSWORD);

            $resp = $controller->index(indexSpcRequest("agent_id={$agentId}"));
            expect(json_decode($resp->getContent(), true)['data']['configs'])->toBe([]);
        });
    });
});
