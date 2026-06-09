<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Psr\Log\NullLogger;
use Spora\Core\SecurityManager;
use Spora\Http\ToolController;
use Spora\Services\ToolConfigService;
use Spora\Tools\CalculatorTool;
use Spora\Tools\CurrentTimeTool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

function makeToolController(): array
{
    $authService = bootAuthLayer();
    $security    = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $toolConfig  = new ToolConfigService($security, new NullLogger(), [CalculatorTool::class, CurrentTimeTool::class]);
    $controller  = new ToolController($authService, $toolConfig, [CalculatorTool::class, CurrentTimeTool::class]);

    return [$controller, $authService, $toolConfig];
}

describe('ToolController::index', function (): void {
    test('returns the schema of each registered tool class', function (): void {
        [$controller] = makeToolController();

        $response = $controller->index();

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['tools'])->toBeArray();
        $names = array_column($body['data']['tools'], 'tool_class');
        expect($names)->toContain(CalculatorTool::class);
        expect($names)->toContain(CurrentTimeTool::class);
    });

    test('returns empty tools list when no tool classes are registered', function (): void {
        $authService = bootAuthLayer();
        $security    = new SecurityManager(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $toolConfig  = new ToolConfigService($security, new NullLogger(), []);
        $controller  = new ToolController($authService, $toolConfig, []);

        $response = $controller->index();

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['tools'])->toBe([]);
    });
});

describe('ToolController::getSettings', function (): void {
    test('returns 200 with settings for a known tool', function (): void {
        [$controller] = makeToolController();

        $request = new Request();
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->getSettings($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data'])->toHaveKey('settings');
    });

    test('returns 404 for an unknown toolId', function (): void {
        [$controller] = makeToolController();

        $request = new Request();
        $request->attributes->set('toolId', 'unknown_tool_xyz');
        $response = $controller->getSettings($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('ToolController::putSettings', function (): void {
    test('returns 200 with updated settings on success', function (): void {
        [$controller, , $toolConfig] = makeToolController();

        $request = jsonRequest('PUT', '/api/v1/tools/calculator/settings', ['settings' => ['precision' => 2]]);
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->putSettings($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data'])->toHaveKey('settings');
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller] = makeToolController();

        $request = Request::create('/api/v1/tools/calculator/settings', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->putSettings($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 404 for an unknown toolId', function (): void {
        [$controller] = makeToolController();

        $request = jsonRequest('PUT', '/api/v1/tools/none/settings', []);
        $request->attributes->set('toolId', 'none');
        $response = $controller->putSettings($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('ToolController::deleteSettings', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, , $toolConfig] = makeToolController();

        // First add some settings
        $toolConfig->putGlobalSettings(CalculatorTool::class, ['precision' => 4]);

        $request = new Request();
        $request->attributes->set('toolId', 'calculator');
        $response = $controller->deleteSettings($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBeTrue();
    });

    test('returns 404 for an unknown toolId', function (): void {
        [$controller] = makeToolController();

        $request = new Request();
        $request->attributes->set('toolId', 'unknown');
        $response = $controller->deleteSettings($request);

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('ToolController::getUserSettings', function (): void {
    test('returns 200 with user settings for a known tool', function (): void {
        [$controller, $authService] = makeToolController();
        bootAuth($authService, 'tooluser@example.com');

        $response = $controller->getUserSettings('calculator');

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data'])->toHaveKey('settings');
    });

    test('returns 404 for an unknown toolId', function (): void {
        [$controller, $authService] = makeToolController();
        bootAuth($authService, 'tooluser2@example.com');

        $response = $controller->getUserSettings('unknown');

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('ToolController::putUserSettings', function (): void {
    test('returns 200 with saved user settings on success', function (): void {
        [$controller, $authService] = makeToolController();
        $userId = bootAuth($authService, 'putuser@example.com');

        $request = jsonRequest('PUT', '/api/v1/user-tools/calculator/settings', ['settings' => ['precision' => 2]]);
        $response = $controller->putUserSettings($request, 'calculator');

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data'])->toHaveKey('settings');
    });

    test('returns 400 on invalid JSON', function (): void {
        [$controller, $authService] = makeToolController();
        bootAuth($authService, 'putuser400@example.com');

        $request = Request::create('/api/v1/user-tools/calculator/settings', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $response = $controller->putUserSettings($request, 'calculator');

        expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    });

    test('returns 404 for an unknown toolId', function (): void {
        [$controller, $authService] = makeToolController();
        bootAuth($authService, 'putuser404@example.com');

        $request = jsonRequest('PUT', '/api/v1/user-tools/unknown/settings', []);
        $response = $controller->putUserSettings($request, 'unknown');

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});

describe('ToolController::deleteUserSettings', function (): void {
    test('returns 200 with deleted: true on success', function (): void {
        [$controller, $authService] = makeToolController();
        $userId = bootAuth($authService, 'deluser@example.com');

        $response = $controller->deleteUserSettings('calculator');

        expect($response->getStatusCode())->toBe(Response::HTTP_OK);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['deleted'])->toBeTrue();
    });

    test('returns 404 for an unknown toolId', function (): void {
        [$controller, $authService] = makeToolController();
        bootAuth($authService, 'deluser404@example.com');

        $response = $controller->deleteUserSettings('unknown');

        expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
    });
});
