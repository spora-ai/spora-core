<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Delight\Auth\Role;
use Spora\Core\SecurityManager;
use Spora\Drivers\AnthropicCompatibleDriver;
use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\LLMConfigController;
use Spora\Models\LLMDriverConfiguration;
use Spora\Services\LLMConfigService;
use Spora\Services\LlmConfigValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

function makeLLMConfigController(): array
{
    $authService = bootAuthLayer();
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new SecurityManager($key);
    $service = new LLMConfigService($security, [OpenAICompatibleDriver::class, AnthropicCompatibleDriver::class]);
    $validator = new LlmConfigValidator($service);
    $controller = new LLMConfigController($authService, $service, $validator);

    return [$controller, $authService, $service, $key];
}

describe('LLMConfigController::drivers', function (): void {
    test('returns the registered drivers list', function (): void {
        [$controller] = makeLLMConfigController();

        $response = $controller->drivers(new Request());

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['drivers'])->toBeArray();
        $names = array_column($body['data']['drivers'], 'name');
        expect($names)->toContain('openai_compatible');
    });
});

describe('LLMConfigController::index', function (): void {
    test('returns the configs list for the current user', function (): void {
        [$controller, $authService] = makeLLMConfigController();
        bootAuth($authService);

        $response = $controller->index(new Request());

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['configs'])->toBeArray();
    });
});

describe('LLMConfigController::globalConfigs', function (): void {
    test('returns the global configs list', function (): void {
        [$controller] = makeLLMConfigController();

        $response = $controller->globalConfigs(new Request());

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['configs'])->toBeArray();
    });
});

describe('LLMConfigController::show', function (): void {
    test('returns 200 with the config by id', function (): void {
        [$controller, $authService, $service] = makeLLMConfigController();
        $userId = bootAuth($authService);

        $config = new LLMDriverConfiguration();
        $config->user_id = $userId;
        $config->name = 'Showable Config';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode($service->encodeSettings(OpenAICompatibleDriver::class, ['api_key' => 'k', 'model' => 'm']));
        $config->save();

        $response = $controller->show(new Request(), (int) $config->id);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['config']['id'])->toBe($config->id);
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, $authService] = makeLLMConfigController();
        bootAuth($authService);

        $response = $controller->show(new Request(), 999999);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('LLMConfigController::store', function (): void {
    test('returns 201 with the created config on success', function (): void {
        [$controller, $authService] = makeLLMConfigController();
        bootAuth($authService);

        $body = [
            'name' => 'New Config',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings' => [
                'api_key' => 'sk-test',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o',
            ],
        ];
        $request = jsonRequest('POST', '/api/v1/llm-configs', $body);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['config']['name'])->toBe('New Config');
    });

    test('returns 422 when name is empty', function (): void {
        [$controller, $authService] = makeLLMConfigController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/llm-configs', [
            'name' => '',
            'driver_class' => OpenAICompatibleDriver::class,
            'settings' => ['api_key' => 'k'],
        ]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 422 when driver_class is invalid', function (): void {
        [$controller, $authService] = makeLLMConfigController();
        bootAuth($authService);

        $request = jsonRequest('POST', '/api/v1/llm-configs', [
            'name' => 'X',
            'driver_class' => 'Spora\\Drivers\\NonExistent',
            'settings' => [],
        ]);
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeLLMConfigController();
        bootAuth($authService);

        $request = Request::create('/api/v1/llm-configs', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $response = $controller->store($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });
});

describe('LLMConfigController::update', function (): void {
    test('returns 200 with the updated config on success', function (): void {
        [$controller, $authService, $service] = makeLLMConfigController();
        $userId = bootAuth($authService);

        $config = new LLMDriverConfiguration();
        $config->user_id = $userId;
        $config->name = 'Old Name';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode($service->encodeSettings(OpenAICompatibleDriver::class, ['api_key' => 'k', 'model' => 'gpt-4o']));
        $config->save();

        $request = jsonRequest('PUT', "/api/v1/llm-configs/{$config->id}", ['name' => 'New Name']);
        $response = $controller->update($request, (int) $config->id);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['config']['name'])->toBe('New Name');
    });

    test('returns 404 for unknown id', function (): void {
        [$controller, $authService] = makeLLMConfigController();
        bootAuth($authService);

        $request = jsonRequest('PUT', '/api/v1/llm-configs/999999', ['name' => 'X']);
        $response = $controller->update($request, 999999);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService, $service] = makeLLMConfigController();
        $userId = bootAuth($authService);

        $config = new LLMDriverConfiguration();
        $config->user_id = $userId;
        $config->name = 'For Update Bad JSON';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode($service->encodeSettings(OpenAICompatibleDriver::class, ['api_key' => 'k', 'model' => 'gpt-4o']));
        $config->save();

        $request = Request::create("/api/v1/llm-configs/{$config->id}", 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $response = $controller->update($request, (int) $config->id);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });
});

describe('LLMConfigController::destroy', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, $authService, $service] = makeLLMConfigController();
        $userId = bootAuth($authService);

        $config = new LLMDriverConfiguration();
        $config->user_id = $userId;
        $config->name = 'ToDelete';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode($service->encodeSettings(OpenAICompatibleDriver::class, ['api_key' => 'k', 'model' => 'gpt-4o']));
        $config->save();

        $response = $controller->destroy(new Request(), (int) $config->id);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBeTrue();
    });

    test('returns 403 for unknown id (no permission disclosure)', function (): void {
        [$controller, $authService] = makeLLMConfigController();
        bootAuth($authService);

        $response = $controller->destroy(new Request(), 999999);

        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    });
});

describe('LLMConfigController::setDefault', function (): void {
    test('returns 200 when admin sets a global config as default', function (): void {
        [$controller, $authService, $service] = makeLLMConfigController();
        $userId = bootAuth($authService, 'default-test@example.com');
        $authService->grantRole($userId, Role::ADMIN);

        $config = new LLMDriverConfiguration();
        $config->user_id = null;
        $config->is_global = true;
        $config->name = 'Default Global Config';
        $config->driver_class = OpenAICompatibleDriver::class;
        $config->settings = json_encode($service->encodeSettings(OpenAICompatibleDriver::class, ['api_key' => 'k', 'model' => 'gpt-4o']));
        $config->save();

        $response = $controller->setDefault(new Request(), (int) $config->id);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['config']['is_default'])->toBeTrue();
    });

    test('returns 403 for unknown id (no permission disclosure)', function (): void {
        [$controller, $authService] = makeLLMConfigController();
        bootAuth($authService, 'default-404@example.com');

        $response = $controller->setDefault(new Request(), 999999);

        expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
    });
});
