<?php

declare(strict_types=1);

use Spora\Http\ConfigController;

test('index() returns allow_registration true when config is empty', function (): void {
    $controller = new ConfigController([]);

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
});

test('index() returns allow_registration false when config sets it to false', function (): void {
    $controller = new ConfigController(['allow_registration' => false]);

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeFalse();
});

test('index() returns allow_registration true when config sets it to true', function (): void {
    $controller = new ConfigController(['allow_registration' => true]);

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
});

test('index() coerces truthy non-bool config values', function (): void {
    $controller = new ConfigController(['allow_registration' => 1]);

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
});

test('index() coerces falsy non-bool config values', function (): void {
    $controller = new ConfigController(['allow_registration' => 0]);

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeFalse();
});

test('index() defaults plugin_install_enabled to false when config omits it', function (): void {
    $controller = new ConfigController([]);

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_install_enabled'])->toBeFalse();
});

test('index() returns plugin_install_enabled true when config sets it true', function (): void {
    $controller = new ConfigController(['plugin_install_enabled' => true]);

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_install_enabled'])->toBeTrue();
});

test('index() coerces plugin_install_enabled truthy non-bool values', function (): void {
    $controller = new ConfigController(['plugin_install_enabled' => 1]);

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_install_enabled'])->toBeTrue();
});

test('index() coerces plugin_install_enabled falsy non-bool values', function (): void {
    $controller = new ConfigController(['plugin_install_enabled' => 0]);

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_install_enabled'])->toBeFalse();
});

test('index() defaults plugin_catalog_enabled to true when config omits it', function (): void {
    $controller = new ConfigController([]);

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_catalog_enabled'])->toBeTrue();
});

test('index() returns plugin_catalog_enabled false when config sets it false', function (): void {
    $controller = new ConfigController(['plugin_catalog_enabled' => false]);

    $response = $controller->index();

    $body = json_decode($response->getContent(), true);
    expect($body['plugin_catalog_enabled'])->toBeFalse();
});

test('index() returns all three runtime feature flag keys together', function (): void {
    $controller = new ConfigController([
        'allow_registration'     => true,
        'plugin_install_enabled' => false,
        'plugin_catalog_enabled' => true,
    ]);

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKeys(['allow_registration', 'plugin_install_enabled', 'plugin_catalog_enabled']);
});
