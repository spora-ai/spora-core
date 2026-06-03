<?php

declare(strict_types=1);

use Spora\Http\RecipeController;
use Spora\Recipes\RecipeScanner;

const VALID_LOGIN          = 'ValidPass1!';
const FIXTURES_RECIPES_DIR = '/tests/Fixtures/recipes';
const RECIPES_ENDPOINT     = '/api/v1/recipes';

function makeRecipeController(?RecipeScanner $scanner = null): RecipeController
{
    $service = bootAuthLayer();
    $scanner ??= new RecipeScanner([BASE_PATH . FIXTURES_RECIPES_DIR]);

    return new RecipeController($service, $scanner);
}

test('index returns 401 when not authenticated', function (): void {
    clearSession();
    $controller = makeRecipeController();

    expect(fn() => $controller->index(jsonRequest('GET', RECIPES_ENDPOINT)))
        ->toThrow(Spora\Http\Exceptions\UnauthenticatedException::class);
});

test('index returns 200 with recipes array when authenticated', function (): void {
    $service = bootAuthLayer();
    $service->register('chef@example.com', VALID_LOGIN, 'Chef');
    $service->login('chef@example.com', VALID_LOGIN);

    $scanner    = new RecipeScanner([BASE_PATH . FIXTURES_RECIPES_DIR]);
    $controller = new RecipeController($service, $scanner);

    $response = $controller->index(jsonRequest('GET', RECIPES_ENDPOINT));
    $body     = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($body)->toHaveKey('data');
    expect($body['data'])->toHaveKey('recipes');
    expect($body['data']['recipes'])->toBeArray();
});

test('index returns only valid recipes', function (): void {
    $service = bootAuthLayer();
    $service->register('chef2@example.com', VALID_LOGIN, 'Chef2');
    $service->login('chef2@example.com', VALID_LOGIN);

    $scanner    = new RecipeScanner([BASE_PATH . FIXTURES_RECIPES_DIR]);
    $controller = new RecipeController($service, $scanner);

    $body    = json_decode($controller->index(jsonRequest('GET', RECIPES_ENDPOINT))->getContent(), true);
    $recipes = $body['data']['recipes'];
    $ids     = array_column($recipes, 'id');

    expect($ids)->toContain('general_assistant');
    expect($ids)->toContain('research_agent');
    expect(count($recipes))->toBe(2);
});

test('index returns empty recipes array when scanner has no directories', function (): void {
    $service = bootAuthLayer();
    $service->register('chef3@example.com', VALID_LOGIN, 'Chef3');
    $service->login('chef3@example.com', VALID_LOGIN);

    $controller = new RecipeController($service, new RecipeScanner([]));

    $body = json_decode($controller->index(jsonRequest('GET', RECIPES_ENDPOINT))->getContent(), true);

    expect($body['data']['recipes'])->toBe([]);
});

test('each recipe has the required shape', function (): void {
    $service = bootAuthLayer();
    $service->register('chef4@example.com', VALID_LOGIN, 'Chef4');
    $service->login('chef4@example.com', VALID_LOGIN);

    $scanner    = new RecipeScanner([BASE_PATH . FIXTURES_RECIPES_DIR]);
    $controller = new RecipeController($service, $scanner);

    $body    = json_decode($controller->index(jsonRequest('GET', RECIPES_ENDPOINT))->getContent(), true);

    foreach ($body['data']['recipes'] as $recipe) {
        expect($recipe)->toHaveKeys(['id', 'name', 'description', 'filename']);
    }
});
