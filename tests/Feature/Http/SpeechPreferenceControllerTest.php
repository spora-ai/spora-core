<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Http\SpeechPreferenceController;
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

const SPREF_TEST_PASSWORD = 'Password1!';

/**
 * @return array{0: SpeechPreferenceController, 1: AuthService}
 */
function makeSpeechPreferenceController(): array
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

    return [new SpeechPreferenceController($auth, $service, $principalService), $auth];
}

function jsonSprefRequest(string $method, string $uri, array $body = []): Request
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

function sprefFullSettings(string $apiKey = 'sk-test'): array
{
    return [
        'api_key' => $apiKey,
        'display_name' => 'Mistral Voxtral',
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'whisper-1',
    ];
}

/**
 * Seed a global speech-provider config row directly via the
 * service so the preference tests don't need to rewire the
 * provider-config controller. Returns the new row id.
 *
 * @param array<string, mixed> $body
 */
function seedGlobalSpeechConfig(AuthService $auth, array $body): int
{
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig = new ToolConfigService($security, new \Psr\Log\NullLogger(), []);
    $principalService = new PrincipalService(new PrincipalResolver());
    $registry = new SpeechToTextRegistry([
        new OpenAiCompatibleTranscriber(new \Symfony\Component\HttpClient\MockHttpClient(), $toolConfig),
    ], $principalService);
    $validator = new SpeechProviderConfigValidator($registry);
    $persistence = new SpeechProviderConfigPersistence($security, $validator, static fn(): SpeechToTextRegistry => $registry);
    $preferences = new SpeechProviderConfigPreferences($principalService);
    $service = new SpeechProviderConfigService(
        $validator,
        $persistence,
        $preferences,
        $principalService,
    );

    $config = $service->createConfiguration((int) $auth->currentUserId(), $body, true);
    expect($config)->not->toBeNull();

    return (int) $config->id;
}

describe('SpeechPreferenceController', function (): void {
    beforeEach(function (): void {
        clearSession();
        Capsule::table('speech_provider_configurations')->delete();
        Capsule::table('principal_preferences')->delete();
        Capsule::table('agents')->delete();
        Capsule::table('group_memberships')->delete();
        Capsule::table('principals')->where('type', 'group')->delete();
        Capsule::table('groups')->delete();
    });

    afterEach(function (): void {
        clearSession();
        Capsule::table('speech_provider_configurations')->delete();
        Capsule::table('principal_preferences')->delete();
        Capsule::table('agents')->delete();
        Capsule::table('group_memberships')->delete();
        Capsule::table('principals')->where('type', 'group')->delete();
        Capsule::table('groups')->delete();
    });

    it('preferred config: PUT /api/v1/speech/preference sets, GET reads', function (): void {
        [$pref, $auth] = makeSpeechPreferenceController();

        // Seed a config to point the preference at. The admin path is
        // exercised by SpeechProviderConfigController tests; here we
        // only assert the preference surface end-to-end.
        $adminId = bootAuth($auth, 'spref-admin@example.com', SPREF_TEST_PASSWORD);
        makeAdmin($auth, $adminId);

        $configId = seedGlobalSpeechConfig($auth, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => sprefFullSettings('sk-pref'),
        ]);

        clearSession();
        bootAuth($auth, 'spref@example.com', SPREF_TEST_PASSWORD);

        $putResp = $pref->setPreferred(jsonSprefRequest('PUT', '/api/v1/speech/preference', [
            'config_id' => $configId,
            'scope' => 'user',
        ]));
        expect($putResp->getStatusCode())->toBe(Response::HTTP_OK);

        $getResp = $pref->getPreference(Request::create('/api/v1/speech/preference', 'GET', [
            'scope' => 'user',
        ]));
        expect($getResp->getStatusCode())->toBe(Response::HTTP_OK);
        expect(json_decode($getResp->getContent(), true)['data']['preference']['config_id'])->toBe($configId);
    });

    it('preference GET returns null when no preference is set', function (): void {
        [$pref, $auth] = makeSpeechPreferenceController();
        bootAuth($auth, 'spref-null@example.com', SPREF_TEST_PASSWORD);

        $resp = $pref->getPreference(Request::create('/api/v1/speech/preference', 'GET', [
            'scope' => 'user',
        ]));
        expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
        expect(json_decode($resp->getContent(), true)['data']['preference']['config_id'])->toBeNull();
    });

    it('non-member cannot clear a group\'s STT preference via PUT scope=group (403, row unchanged)', function (): void {
        // Regression for the group-scope auth bypass: any authenticated user
        // who knew a `group_id` could previously PUT
        //   { scope: "group", group_id: X, config_id: null }
        // and `unsetPrincipalPreferredConfig` would null the group's
        // `principal_preferences.preferred_speech_config_id`. The LLM-side
        // `GroupPreferencesController::update()` enforces
        // `callerCanManageGroup()`; the speech side must mirror that.
        [$pref, $auth] = makeSpeechPreferenceController();

        // Owner creates the group + a global config + the owner-managed
        // preference for that group.
        $ownerId = bootAuth($auth, 'spref-group-owner@example.com', SPREF_TEST_PASSWORD);
        makeAdmin($auth, $ownerId);
        $principalService = new PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $group = (new \Spora\Services\GroupService($principalService))->createGroup($ownerId, 'SprefGroupPrivate');

        $configId = seedGlobalSpeechConfig($auth, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => sprefFullSettings('sk-team'),
        ]);

        // Owner sets the group preference so the row exists for the
        // regression to assert "unchanged" against.
        $groupPrincipalId = (int) Capsule::table('principals')
            ->where('type', 'group')
            ->where('group_id', $group->id)
            ->value('id');
        Capsule::table('principal_preferences')->insert([
            'principal_id' => $groupPrincipalId,
            'preferred_speech_config_id' => $configId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Switch to a non-member, non-admin caller.
        clearSession();
        $outsiderId = bootAuth($auth, 'spref-group-outsider@example.com', SPREF_TEST_PASSWORD);

        $resp = $pref->setPreferred(jsonSprefRequest('PUT', '/api/v1/speech/preference', [
            'scope' => 'group',
            'group_id' => (int) $group->id,
            'config_id' => null,
        ]));
        expect($resp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);

        // The group's preference row must be untouched.
        $row = Capsule::table('principal_preferences')->where('principal_id', $groupPrincipalId)->first();
        expect((int) $row->preferred_speech_config_id)->toBe($configId);
    });

    it('group admin can clear their group\'s STT preference via PUT scope=group', function (): void {
        // Positive control: the gate must not over-deny. A group admin
        // (role=admin) can manage the group's preference.
        [$pref, $auth] = makeSpeechPreferenceController();

        $ownerId = bootAuth($auth, 'spref-group-admin2@example.com', SPREF_TEST_PASSWORD);
        makeAdmin($auth, $ownerId);
        $principalService = new PrincipalService(new PrincipalResolver());
        $principalService->ensureUserPrincipal($ownerId);
        $group = (new \Spora\Services\GroupService($principalService))->createGroup($ownerId, 'SprefGroupAdmin');

        $configId = seedGlobalSpeechConfig($auth, [
            'provider_class' => OpenAiCompatibleTranscriber::class,
            'is_global' => true,
            'settings' => sprefFullSettings('sk-team-2'),
        ]);

        $groupPrincipalId = (int) Capsule::table('principals')
            ->where('type', 'group')
            ->where('group_id', $group->id)
            ->value('id');
        Capsule::table('principal_preferences')->insert([
            'principal_id' => $groupPrincipalId,
            'preferred_speech_config_id' => $configId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Owner IS the principal row's caller in this test (role=owner),
        // so the gate must let the clear land.
        $resp = $pref->setPreferred(jsonSprefRequest('PUT', '/api/v1/speech/preference', [
            'scope' => 'group',
            'group_id' => (int) $group->id,
            'config_id' => null,
        ]));
        expect($resp->getStatusCode())->toBe(Response::HTTP_OK);

        $row = Capsule::table('principal_preferences')->where('principal_id', $groupPrincipalId)->first();
        // After unset the row stays (it's the principal's slot) but the
        // FK is nulled — `SpeechProviderConfigPreferences::unsetPrincipalPreferredConfig`
        // runs `->update(['preferred_speech_config_id' => null])`.
        expect($row)->not->toBeNull();
        expect($row->preferred_speech_config_id)->toBeNull();
    });
});
