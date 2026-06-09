<?php

declare(strict_types=1);

use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Http\RecipeController;
use Spora\Recipes\RecipeScanner;

beforeEach(function (): void {
    Spora\Core\Database::resetBootState();
    (new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']))->boot();
});

afterEach(fn() => Spora\Core\Database::resetBootState());

function makeRecipeControllerUnit(?array $dirs = null): array
{
    $authService = bootAuthLayer();
    $scanner = new RecipeScanner($dirs ?? []);
    $controller = new RecipeController($authService, $scanner);

    return [$controller, $authService, $scanner];
}

test('index() throws UnauthenticatedException when no user is logged in', function (): void {
    [$controller] = makeRecipeControllerUnit();
    clearSession();

    expect(fn() => $controller->index())
        ->toThrow(UnauthenticatedException::class);
});

test('index() returns empty recipes when scanner directory is empty', function (): void {
    [$controller, $authService] = makeRecipeControllerUnit([]);
    bootAuth($authService);

    $response = $controller->index();

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['recipes'])->toBe([]);
});

test('index() returns recipes scanned from a temp directory containing valid YAML', function (): void {
    $tmpDir = sys_get_temp_dir() . '/spora-recipes-' . uniqid();
    mkdir($tmpDir);
    file_put_contents(
        $tmpDir . '/sample.yaml',
        "id: sample\nname: Sample Recipe\ndescription: A test recipe\n",
    );

    try {
        [$controller, $authService] = makeRecipeControllerUnit([$tmpDir]);
        bootAuth($authService);

        $response = $controller->index();

        expect($response->getStatusCode())->toBe(200);
        $body = json_decode($response->getContent(), true);
        expect($body['data']['recipes'])->toHaveCount(1);
        expect($body['data']['recipes'][0]['id'])->toBe('sample');
        expect($body['data']['recipes'][0]['name'])->toBe('Sample Recipe');
    } finally {
        @unlink($tmpDir . '/sample.yaml');
        @rmdir($tmpDir);
    }
});

test('index() skips files missing required keys', function (): void {
    $tmpDir = sys_get_temp_dir() . '/spora-recipes-' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/invalid.yaml', "name: only_name\n");
    file_put_contents(
        $tmpDir . '/valid.yaml',
        "id: valid\nname: Valid\ndescription: valid\n",
    );

    try {
        [$controller, $authService] = makeRecipeControllerUnit([$tmpDir]);
        bootAuth($authService);

        $response = $controller->index();

        $body = json_decode($response->getContent(), true);
        expect($body['data']['recipes'])->toHaveCount(1);
        expect($body['data']['recipes'][0]['id'])->toBe('valid');
    } finally {
        @unlink($tmpDir . '/invalid.yaml');
        @unlink($tmpDir . '/valid.yaml');
        @rmdir($tmpDir);
    }
});
