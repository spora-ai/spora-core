<?php

declare(strict_types=1);

use Spora\Drivers\OpenAICompatibleDriver;
use Spora\Http\UserPreferenceController;
use Spora\Services\LLMConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

const UPC_URI = '/api/v1/user-preferences/llm';

function makeUserPreferenceControllerUnit(): array
{
    $authService = bootAuthLayer();
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $security = new Spora\Core\SecurityManager($key);
    $llmConfigService = new LLMConfigService($security, [OpenAICompatibleDriver::class]);
    $controller = new UserPreferenceController($authService, $llmConfigService);
    return [$controller, $authService, $llmConfigService];
}

test('update() returns 400 on invalid JSON', function (): void {
    [$controller, $authService] = makeUserPreferenceControllerUnit();
    bootAuth($authService, 'badjson@example.com');

    $request = Request::create(
        UPC_URI,
        'PUT',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        'not valid json',
    );
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('INVALID_JSON');
});

test('update() returns 422 when config_id is a string', function (): void {
    [$controller, $authService] = makeUserPreferenceControllerUnit();
    bootAuth($authService, 'nonintvalue@example.com');

    $request = jsonRequest('PUT', UPC_URI, ['config_id' => 'not-an-int']);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});

test('update() returns 422 when config_id is a float', function (): void {
    [$controller, $authService] = makeUserPreferenceControllerUnit();
    bootAuth($authService, 'floatval@example.com');

    $request = jsonRequest('PUT', UPC_URI, ['config_id' => 1.5]);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('update() returns 422 when config_id is an array', function (): void {
    [$controller, $authService] = makeUserPreferenceControllerUnit();
    bootAuth($authService, 'arrval@example.com');

    $request = jsonRequest('PUT', UPC_URI, ['config_id' => [1, 2]]);
    $response = $controller->update($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});
