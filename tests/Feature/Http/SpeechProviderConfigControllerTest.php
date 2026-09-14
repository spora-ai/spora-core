<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Http\SpeechProviderConfigController;
use Spora\Models\SpeechProviderConfiguration;
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
    ]);

    $validator = new SpeechProviderConfigValidator($registry);
    $persistence = new SpeechProviderConfigPersistence($security, $validator);
    $preferences = new SpeechProviderConfigPreferences($principalService);
    $service = new SpeechProviderConfigService($validator, $persistence, $preferences, $principalService);

    return [new SpeechProviderConfigController($auth, $service, $registry), $auth];
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
    });

    afterEach(function () {
        clearSession();
        Capsule::table('speech_provider_configurations')->delete();
        Capsule::table('principal_preferences')->delete();
        Capsule::table('agents')->delete();
    });

    it('returns 401 for an unauthenticated caller on index', function (): void {
        [$controller] = makeSpeechProviderConfigController();
        $resp = $controller->index();
        expect($resp->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);
    });

    it('list returns 200 with an empty list for a fresh user', function (): void {
        [$controller, $auth] = makeSpeechProviderConfigController();
        $userId = bootAuth($auth, 'spc-empty@example.com', SPC_TEST_PASSWORD);

        $resp = $controller->index();
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

    it('non-admin cannot update or delete another user\'s config', function (): void {
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
        expect($updateResp->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
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
});
