<?php

declare(strict_types=1);

namespace Spora\Core;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased as DispatcherImpl;
use FastRoute\RouteParser\Std;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionNamedType;
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
        $collector = new MiddlewareRouteCollector(new Std(), new GroupCountBased());
        $routeDefinitions($collector);
        $this->dispatcher = new DispatcherImpl($collector->getData());
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path   = '/' . ltrim($request->getPathInfo(), '/');

        $routeInfo = $this->dispatcher->dispatch($method, $path);

        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND          => $this->notFound(),
            Dispatcher::METHOD_NOT_ALLOWED => $this->methodNotAllowed($routeInfo[1]),
            Dispatcher::FOUND              => $this->handleFound(
                $request,
                $routeInfo[1],
                $routeInfo[2],
            ),
            default                        => $this->notFound(),
        };
    }

    private function handleFound(Request $request, mixed $routeHandler, array $vars): Response
    {
        $handler = is_array($routeHandler) && isset($routeHandler['handler']) ? $routeHandler['handler'] : $routeHandler;
        $middleware = is_array($routeHandler) && isset($routeHandler['middleware']) ? $routeHandler['middleware'] : [];

        [$controllerClass, $method] = is_array($handler) ? $handler : [$handler, '__invoke'];

        // URL-decode path variables since getPathInfo() does not decode them.
        // Handles %5C → \ conversion so ReflectionClass sees a valid class name.
        foreach ($vars as $key => $value) {
            $decoded = urldecode((string) $value);
            if (str_contains($decoded, "\0")) {
                return new JsonResponse(
                    ['error' => ['code' => 'BAD_REQUEST', 'message' => 'Invalid path parameter.']],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            if ($decoded !== $value) {
                $vars[$key] = $decoded;
            }
        }

        $request->attributes->add($vars);

        // Build the final controller invocation as a closure
        $next = function (Request $req) use ($controllerClass, $method, $vars): Response {
            return $this->invokeController($controllerClass, $method, $vars, $req);
        };

        // Wrap middleware around the controller call (LIFO — last middleware wraps innermost)
        foreach (array_reverse($middleware) as $middlewareClass) {
            /** @var \Spora\Http\Middleware\MiddlewareInterface $mw */
            $mw = $this->container->get($middlewareClass);
            $currentNext = $next;
            $next = function (Request $req) use ($mw, $currentNext): Response {
                return $mw->handle($req, $currentNext);
            };
        }

        return $next($request);
    }

    /**
     * Invoke a controller method with reflected parameters.
     */
    private function invokeController(string $controllerClass, string $method, array $vars, Request $request): Response
    {
        $controller = $this->container->get($controllerClass);

        // Resolve method parameters using reflection so scalars are coerced to their
        // declared types (e.g. string "42" → int 42 for (Request $request, int $id)).
        // A parameter typed as Request receives the incoming request; everything else
        // is matched by name against path variables (or falls back to its default).
        $params = (new ReflectionMethod($controllerClass, $method))->getParameters();
        $args = [];
        foreach ($params as $param) {
            $type = $param->getType();

            // Request-typed parameters get the live request; this lets controllers
            // omit Request entirely when they don't read from it.
            if ($type instanceof ReflectionNamedType && $type->getName() === Request::class) {
                $args[] = $request;
                continue;
            }

            // Anything else is a path variable matched by name.
            if (!array_key_exists($param->getName(), $vars)) {
                if (!$param->isOptional()) {
                    throw new LogicException(
                        sprintf(
                            'Required parameter "%s" in %s::%s() has no matching route variable.',
                            $param->getName(),
                            $controllerClass,
                            $method,
                        ),
                    );
                }
                continue; // let the default value be used
            }
            $value = $vars[$param->getName()];
            if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
                $args[] = (int) $value;
            } else {
                $args[] = $value;
            }
        }

        return $controller->$method(...$args);
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
