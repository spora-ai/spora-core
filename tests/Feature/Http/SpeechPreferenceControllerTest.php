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
    ]);

    $validator = new SpeechProviderConfigValidator($registry);
    $persistence = new SpeechProviderConfigPersistence($security, $validator);
    $preferences = new SpeechProviderConfigPreferences($principalService);
    $service = new SpeechProviderConfigService($validator, $persistence, $preferences, $principalService);

    return [new SpeechPreferenceController($auth, $service), $auth];
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
    $registry = new SpeechToTextRegistry([
        new OpenAiCompatibleTranscriber(new \Symfony\Component\HttpClient\MockHttpClient(), $toolConfig),
    ]);
    $validator = new SpeechProviderConfigValidator($registry);
    $persistence = new SpeechProviderConfigPersistence($security, $validator);
    $preferences = new SpeechProviderConfigPreferences(new PrincipalService(new PrincipalResolver()));
    $service = new SpeechProviderConfigService(
        $validator,
        $persistence,
        $preferences,
        new PrincipalService(new PrincipalResolver()),
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
    });

    afterEach(function (): void {
        clearSession();
        Capsule::table('speech_provider_configurations')->delete();
        Capsule::table('principal_preferences')->delete();
        Capsule::table('agents')->delete();
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
});
