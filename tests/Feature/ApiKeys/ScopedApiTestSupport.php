<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys;

use Psr\Container\ContainerInterface;
use Spora\Http\Middleware\GlobalApiKeyMiddleware;
use Spora\Http\Middleware\GroupApiKeyMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared scaffolding for the scoped API tests.
 *
 * A class rather than functions in a test file: Pest loads one file per
 * worker, so a function defined in one test file is invisible to the next.
 * The class itself is autoloadable through the `Tests\` PSR-4 prefix.
 */
final class ScopedApiTestSupport
{
    public const GROUP_PREFIX = '/api/v1/group-api';

    public const GLOBAL_PREFIX = '/api/v1/global-api';

    /**
     * Send a request through a scoped controller with the real bearer
     * middleware.
     *
     * The middleware is what the test is exercising — it is what decides
     * whether a key of the wrong scope authenticates at all — so it runs
     * here rather than being bypassed.
     */
    public static function callGroup(
        ContainerInterface $container,
        string $controllerClass,
        string $method,
        Request $request,
        array $args = [],
    ): Response {
        return self::call($container, $controllerClass, $method, $request, $args, GroupApiKeyMiddleware::class);
    }

    public static function callGlobal(
        ContainerInterface $container,
        string $controllerClass,
        string $method,
        Request $request,
        array $args = [],
    ): Response {
        return self::call($container, $controllerClass, $method, $request, $args, GlobalApiKeyMiddleware::class);
    }

    /**
     * @param class-string $middleware
     */
    private static function call(
        ContainerInterface $container,
        string $controllerClass,
        string $method,
        Request $request,
        array $args,
        string $middleware,
    ): Response {
        /** @var Response $response */
        $response = $container->get($middleware)->handle(
            $request,
            static fn(Request $incoming): Response => $container->get($controllerClass)->{$method}($incoming, ...$args),
        );

        return $response;
    }

    /**
     * A JSON request carrying a bearer key.
     *
     * @param array<string, mixed> $body
     */
    public static function bearer(
        string $key,
        string $path,
        array $body = [],
        string $method = 'POST',
    ): Request {
        return jsonRequest($method, $path, $body, ['Authorization' => 'Bearer ' . $key]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(Response $response): array
    {
        $body = $response->getContent();

        return $body === '' ? [] : (json_decode($body, true) ?: []);
    }

    /**
     * The error code of a response, or null when it carries none.
     */
    public static function errorCode(Response $response): ?string
    {
        $code = self::decode($response)['error']['code'] ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * The `data` payload of a response.
     *
     * @return array<string, mixed>
     */
    public static function data(Response $response): array
    {
        $data = self::decode($response)['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * A response that is not a JsonResponse — the media endpoints answer
     * with bytes.
     */
    public static function isJson(Response $response): bool
    {
        return $response instanceof JsonResponse;
    }
}
