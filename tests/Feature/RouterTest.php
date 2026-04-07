<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test controller with methods that accept route variables as arguments.
 * Used to verify Router passes {id} params to controller methods.
 */
final class RouterTestController
{
    public ?int $receivedId = null;

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

    public function deleteResource(Request $request, int $id): Response
    {
        $this->receivedId = $id;
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

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

    expect($response->getStatusCode())->toBe(Response::HTTP_NO_CONTENT);

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

test('Router dispatches 405 for wrong method', function (): void {
    $container = (new ContainerBuilder())->build();

    $router = new Router($container, function (FastRoute\RouteCollector $r): void {
        $r->addRoute('GET', '/test/{id}', [RouterTestController::class, 'getResource']);
    });

    $request = Request::create('/test/1', 'POST');
    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_METHOD_NOT_ALLOWED);
});
