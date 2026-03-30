<?php

declare(strict_types=1);

namespace Spora\Core;

use FastRoute\Dispatcher;

use function FastRoute\simpleDispatcher;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Router
{
    private Dispatcher $dispatcher;

    public function __construct(
        private readonly ContainerInterface $container,
        callable $routeDefinitions,
    ) {
        $this->dispatcher = simpleDispatcher($routeDefinitions);
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path   = '/' . ltrim($request->getPathInfo(), '/');

        $routeInfo = $this->dispatcher->dispatch($method, $path);

        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND          => $this->notFound(),
            Dispatcher::METHOD_NOT_ALLOWED => $this->methodNotAllowed($routeInfo[1]),
            Dispatcher::FOUND              => $this->handleFound($request, $routeInfo[1], $routeInfo[2]),
            default                        => $this->notFound(),
        };
    }

    private function handleFound(Request $request, mixed $handler, array $vars): Response
    {
        [$controllerClass, $method] = is_array($handler) ? $handler : [$handler, '__invoke'];

        $request->attributes->add($vars);

        $controller = $this->container->get($controllerClass);

        return $controller->$method($request);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }

    private function methodNotAllowed(array $allowedMethods): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => [
                    'code'    => 'METHOD_NOT_ALLOWED',
                    'message' => 'Method not allowed. Accepted: ' . implode(', ', $allowedMethods),
                ],
            ],
            Response::HTTP_METHOD_NOT_ALLOWED,
        );
    }
}
