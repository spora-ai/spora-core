<?php

declare(strict_types=1);

use Spora\Http\ConfigController;
use Symfony\Component\HttpFoundation\Request;

test('index() returns allow_registration true when config is empty', function (): void {
    $controller = new ConfigController([]);

    $response = $controller->index(new Request());

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
});

test('index() returns allow_registration false when config sets it to false', function (): void {
    $controller = new ConfigController(['allow_registration' => false]);

    $response = $controller->index(new Request());

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeFalse();
});

test('index() returns allow_registration true when config sets it to true', function (): void {
    $controller = new ConfigController(['allow_registration' => true]);

    $response = $controller->index(new Request());

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
});

test('index() coerces truthy non-bool config values', function (): void {
    $controller = new ConfigController(['allow_registration' => 1]);

    $response = $controller->index(new Request());

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeTrue();
});

test('index() coerces falsy non-bool config values', function (): void {
    $controller = new ConfigController(['allow_registration' => 0]);

    $response = $controller->index(new Request());

    $body = json_decode($response->getContent(), true);
    expect($body['allow_registration'])->toBeFalse();
});
