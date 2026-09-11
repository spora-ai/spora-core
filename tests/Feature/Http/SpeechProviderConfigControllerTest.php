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
        $listResp = $controller->index();
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
        $listResp = $controller->index();
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

    it('returns 403 (forbidden) for anonymous index requests', function (): void {
        [$controller] = makeSpeechProviderConfigController();
        // No session — currentUserId() returns null → requireUserId throws.
        $resp = $controller->index();
        expect($resp->getStatusCode())->toBe(403);
    });
});
