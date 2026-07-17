<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use ReflectionClass;
use ReflectionMethod;
use Spora\Http\AgentController;
use Spora\Http\AgentOverrideController;
use Spora\Http\AgentToolController;

/**
 * Smoke test for the AgentController split (php:S1448).
 *
 * Asserts the three new classes exist with the expected public method
 * surface and that `AgentController` only retains the CRUD methods.
 */
test('AgentController exposes only CRUD methods after the split', function (): void {
    $crudReflection = new ReflectionClass(AgentController::class);

    expect($crudReflection->getMethod('index'))->toBeInstanceOf(ReflectionMethod::class);
    expect($crudReflection->getMethod('store'))->toBeInstanceOf(ReflectionMethod::class);
    expect($crudReflection->getMethod('show'))->toBeInstanceOf(ReflectionMethod::class);
    expect($crudReflection->getMethod('update'))->toBeInstanceOf(ReflectionMethod::class);
    expect($crudReflection->getMethod('destroy'))->toBeInstanceOf(ReflectionMethod::class);

    $publicMethods = array_map(
        static fn(ReflectionMethod $m) => $m->getName(),
        $crudReflection->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    // Tool/override methods must no longer be on the CRUD controller.
    foreach (['enableTool', 'disableTool', 'getToolStatus', 'getToolsStatus', 'getToolsOperations', 'getOverride', 'putOverride', 'deleteOverride', 'getOperationOverride', 'patchOperationOverride'] as $moved) {
        expect($publicMethods)->not()->toContain($moved);
    }
});

test('AgentToolController exposes only tool methods', function (): void {
    $reflection = new ReflectionClass(AgentToolController::class);

    foreach (['enableTool', 'disableTool', 'getToolStatus', 'getToolsStatus', 'getToolsOperations'] as $method) {
        expect($reflection->getMethod($method))->toBeInstanceOf(ReflectionMethod::class);
    }

    // Override methods must not be on the tool controller.
    foreach (['getOverride', 'putOverride', 'deleteOverride', 'getOperationOverride', 'patchOperationOverride'] as $moved) {
        expect($reflection->hasMethod($moved))->toBeFalse();
    }

    // CRUD methods must not be on the tool controller.
    foreach (['index', 'store', 'show', 'update', 'destroy'] as $crud) {
        expect($reflection->hasMethod($crud))->toBeFalse();
    }
});

test('AgentOverrideController exposes only override methods', function (): void {
    $reflection = new ReflectionClass(AgentOverrideController::class);

    foreach (['getOverride', 'putOverride', 'deleteOverride', 'getOperationOverride', 'patchOperationOverride'] as $method) {
        expect($reflection->getMethod($method))->toBeInstanceOf(ReflectionMethod::class);
    }

    // Tool methods must not be on the override controller.
    foreach (['enableTool', 'disableTool', 'getToolStatus', 'getToolsStatus', 'getToolsOperations'] as $moved) {
        expect($reflection->hasMethod($moved))->toBeFalse();
    }

    // CRUD methods must not be on the override controller.
    foreach (['index', 'store', 'show', 'update', 'destroy'] as $crud) {
        expect($reflection->hasMethod($crud))->toBeFalse();
    }
});

test('All three controllers share a consistent constructor surface', function (): void {
    $crud = new ReflectionClass(AgentController::class)->getConstructor();
    $tool = new ReflectionClass(AgentToolController::class)->getConstructor();
    $override = new ReflectionClass(AgentOverrideController::class)->getConstructor();

    expect($crud)->toBeInstanceOf(ReflectionMethod::class);
    expect($tool)->toBeInstanceOf(ReflectionMethod::class);
    expect($override)->toBeInstanceOf(ReflectionMethod::class);

    // CRUD controller takes auth, agentService, DriverFactory, and (since
    // the per-tool icon chain was added) ToolIconResolver. The tool and
    // override controllers take 3 (auth + their service + a config helper).
    expect($crud->getNumberOfParameters())->toBe(4);
    expect($tool->getNumberOfParameters())->toBe(3);
    expect($override->getNumberOfParameters())->toBe(3);
});

test('Route registration wires agent tool + override routes to the new controllers', function (): void {
    $routeFile = (new ReflectionClass(\Spora\Core\RouteDefinitions::class))->getFileName();
    expect(is_file($routeFile))->toBeTrue();
    $contents = (string) file_get_contents($routeFile);

    expect($contents)->toContain('[AgentToolController::class, \'enableTool\']');
    expect($contents)->toContain('[AgentToolController::class, \'disableTool\']');
    expect($contents)->toContain('[AgentToolController::class, \'getToolStatus\']');
    expect($contents)->toContain('[AgentToolController::class, \'getToolsStatus\']');
    expect($contents)->toContain('[AgentToolController::class, \'getToolsOperations\']');

    expect($contents)->toContain('[AgentOverrideController::class, \'getOverride\']');
    expect($contents)->toContain('[AgentOverrideController::class, \'putOverride\']');
    expect($contents)->toContain('[AgentOverrideController::class, \'deleteOverride\']');
    expect($contents)->toContain('[AgentOverrideController::class, \'getOperationOverride\']');
    expect($contents)->toContain('[AgentOverrideController::class, \'patchOperationOverride\']');

    foreach (['enableTool', 'disableTool', 'getToolStatus', 'getToolsStatus', 'getToolsOperations', 'getOverride', 'putOverride', 'deleteOverride', 'getOperationOverride', 'patchOperationOverride'] as $moved) {
        expect($contents)
            ->not->toContain('[AgentController::class, \'' . $moved . '\']');
    }
});
