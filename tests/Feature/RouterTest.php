<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\Router;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test controller with methods that accept route variables as arguments.
 * Used to verify Router passes {id} params to controller methods.
 */
final class RouterTestController
{
    public mixed $receivedId = null;

    public function getResource(Request $request, int $id): Response
    {
        $this->receivedId = $id;
        return new Response(json_encode(['id' => $id]), Response::HTTP_OK);
    }

    public function updateResource(Request $request, int $id): Response
    {
        $this->receivedId = $id;
        return new Response(json_encode(['id' => $id]), Response::HTTP_OK);
    }

    public function deleteResource(Request $request, int $id): JsonResponse
    {
        $this->receivedId = $id;
        return new JsonResponse(['data' => ['deleted' => true]]);
    }
}

// Tests

test('Router passes {id} route variable as method argument to GET handler', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(RouterTestController::class, new RouterTestController());

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('GET', '/test/{id}', [RouterTestController::class, 'getResource']);
    });

    $request = Request::create('/test/42', 'GET');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['id'])->toBe(42);

    $controller = $container->get(RouterTestController::class);
    expect($controller->receivedId)->toBe(42);
});

test('Router passes {id} route variable as method argument to PUT handler', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(RouterTestController::class, new RouterTestController());

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('PUT', '/test/{id}', [RouterTestController::class, 'updateResource']);
    });

    $request = Request::create('/test/99', 'PUT');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['id'])->toBe(99);

    $controller = $container->get(RouterTestController::class);
    expect($controller->receivedId)->toBe(99);
});

test('Router passes {id} route variable as method argument to DELETE handler', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(RouterTestController::class, new RouterTestController());

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('DELETE', '/test/{id}', [RouterTestController::class, 'deleteResource']);
    });

    $request = Request::create('/test/7', 'DELETE');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['data']['deleted'])->toBe(true);

    $controller = $container->get(RouterTestController::class);
    expect($controller->receivedId)->toBe(7);
});

test('Router dispatches 404 for unknown route', function (): void {
    $container = (new ContainerBuilder())->build();

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('GET', '/test/{id}', [RouterTestController::class, 'getResource']);
    });

    $request = Request::create('/unknown', 'GET');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

test('Router passes {id} route variable as method argument to POST handler', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(RouterTestController::class, new RouterTestController());

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('POST', '/test/{id}/set-default', [RouterTestController::class, 'getResource']);
    });

    $request = Request::create('/test/42/set-default', 'POST');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['id'])->toBe(42);

    $controller = $container->get(RouterTestController::class);
    expect($controller->receivedId)->toBe(42);
});

test('Router dispatches 405 for wrong method', function (): void {
    $container = (new ContainerBuilder())->build();

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('GET', '/test/{id}', [RouterTestController::class, 'getResource']);
    });

    $request = Request::create('/test/1', 'POST');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_METHOD_NOT_ALLOWED);
});

// Fix: null byte in path param is rejected with 400

final class NullByteTestController
{
    public function handle(Request $request): Response
    {
        return new Response('ok', Response::HTTP_OK);
    }
}

test('Router returns 400 when a path variable contains a null byte', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(NullByteTestController::class, new NullByteTestController());

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('GET', '/resources/{toolId}', [NullByteTestController::class, 'handle']);
    });

    // %00 in the URL is the percent-encoded null byte. FastRoute captures the raw
    // segment (test%00evil), and urldecode() converts %00 to the actual null byte character.
    $request = Request::create('/resources/test%00evil', 'GET');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);

    $body = json_decode($response->getContent(), true);
    expect($body['error']['code'])->toBe('BAD_REQUEST');
});

test('Router allows normal percent-encoded path variables (no null byte)', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(NullByteTestController::class, new NullByteTestController());

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('GET', '/resources/{toolId}', [NullByteTestController::class, 'handle']);
    });

    // %5C is a backslash — a legitimate case for PHP class names in URLs
    $request = Request::create('/resources/Spora%5CTools%5CMyTool', 'GET');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
});

// Route without path variables — optional params use their defaults

final class NoVarsController
{
    public function login(Request $request, array $vars = []): Response
    {
        return new Response(json_encode(['vars' => $vars]), Response::HTTP_OK);
    }
}

test('Router calls method without path variables and lets optional params use defaults', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(NoVarsController::class, new NoVarsController());

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('POST', '/auth/login', [NoVarsController::class, 'login']);
    });

    $request = Request::create('/auth/login', 'POST');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode($response->getContent(), true);
    expect($body['vars'])->toBe([]);
});

// Guard: non-optional parameter with no matching route variable throws

final class RequiredParamController
{
    // $id is required (no default), but the route may not supply it
    public function handle(Request $request, int $id): Response
    {
        return new Response(json_encode(['id' => $id]), Response::HTTP_OK);
    }

    // $id is optional — should be fine even without a route variable
    public function handleOptional(Request $request, int $id = 0): Response
    {
        return new Response(json_encode(['id' => $id]), Response::HTTP_OK);
    }
}

test('Router throws LogicException when required parameter has no matching route variable', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(RequiredParamController::class, new RequiredParamController());

    // Route does NOT declare {id}, but the controller method requires it
    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('GET', '/broken', [RequiredParamController::class, 'handle']);
    });

    $request = Request::create('/broken', 'GET');

    expect(fn() => $router->dispatch($request))->toThrow(LogicException::class, 'Required parameter "id"');
});

test('Router does not throw when optional parameter has no matching route variable', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(RequiredParamController::class, new RequiredParamController());

    // Route does NOT declare {id}, but the controller method has a default
    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('GET', '/optional', [RequiredParamController::class, 'handleOptional']);
    });

    $request = Request::create('/optional', 'GET');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($response->getContent(), true);
    expect($body['id'])->toBe(0);
});

test('Router error message names both the parameter and the controller method', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set(RequiredParamController::class, new RequiredParamController());

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('GET', '/broken', [RequiredParamController::class, 'handle']);
    });

    $request = Request::create('/broken', 'GET');

    try {
        $router->dispatch($request);
        $this->fail('Expected LogicException was not thrown');
    } catch (LogicException $e) {
        expect($e->getMessage())
            ->toContain('id')
            ->toContain('handle');
    }
});
