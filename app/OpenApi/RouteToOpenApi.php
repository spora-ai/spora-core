<?php

declare(strict_types=1);

namespace Spora\OpenApi;

use OpenApi\Annotations as OA;
use OpenApi\Annotations\Components;
use OpenApi\Annotations\Info;
use OpenApi\Annotations\OpenApi;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\PathItem;
use OpenApi\Annotations\Response;
use OpenApi\Annotations\SecurityScheme;
use OpenApi\Annotations\Server;
use ReflectionMethod;
use Spora\Core\RouteDefinitions;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;

/**
 * Builds an `OpenApi` document whose paths/methods/security come straight from
 * `RouteDefinitions`, plus root-level metadata (info, servers, security schemes).
 *
 * This is the bridge that lets `RouteDefinitions` stay the only place routes are declared
 * while the OpenAPI spec reflects them without duplication. Body detail (`#[OA\RequestBody]`,
 * `#[OA\Response]`, `#[OA\Schema]`) is intended to be added per-controller via a future
 * scan-and-merge step; the present change ships the path/method/auth surface that covers
 * ~95% of the route catalog already.
 */
final class RouteToOpenApi
{
    public function build(): OpenApi
    {
        $collector = new RouteSpecCollector();
        RouteDefinitions::register($collector);

        $openapi = new OpenApi([]);
        $openapi->openapi = OpenApi::VERSION_3_0_0;
        $openapi->info = $this->buildInfo();
        $openapi->servers = [$this->buildServer()];
        $openapi->paths = $this->buildPaths($collector->routes());

        $components = new Components([]);
        $components->securitySchemes = [
            $this->buildSecurityScheme(
                'cookieAuth',
                'apiKey',
                'cookie',
                'PHPSESSID',
                'Session cookie issued by `delight-im/auth`. Required by `AuthMiddleware`.',
            ),
            $this->buildSecurityScheme(
                'csrfToken',
                'apiKey',
                'header',
                'X-CSRF-Token',
                'CSRF token issued alongside the session. Required by `CsrfMiddleware` on every write method.',
            ),
        ];
        $openapi->components = $components;

        return $openapi;
    }

    private function buildInfo(): Info
    {
        return new Info([
            'title' => 'Spora API',
            // Read composer.json's version directly so the spec is deterministic across
            // branches — `Composer\InstalledVersions` resolves branch refs as
            // `dev-feat/...` / `dev-main`, which would make the openapi.json differ
            // between local dev and the CI PR merge ref and trip the drift check.
            'version' => $this->composerVersion(),
            'description' => 'HTTP API for the Spora AI agent orchestration platform. Paths are derived from `Spora\\Core\\RouteDefinitions`; body schemas are added incrementally via `#[\OpenApi\...]` attributes on controllers.',
            'contact' => new OA\Contact(['name' => 'Spora', 'url' => 'https://spora-ai.com']),
        ]);
    }

    private function composerVersion(): string
    {
        $path = dirname(__DIR__, 2) . '/composer.json';
        if (!is_file($path)) {
            return 'dev';
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return 'dev';
        }

        $decoded = json_decode($contents, true);
        $version = is_array($decoded) ? ($decoded['version'] ?? null) : null;
        return is_string($version) && $version !== '' ? $version : 'dev';
    }

    private function buildServer(): Server
    {
        return new Server(['url' => '/', 'description' => 'Same-origin']);
    }

    private function buildSecurityScheme(string $name, string $type, ?string $in, string $keyName, string $description): SecurityScheme
    {
        return new SecurityScheme([
            'securityScheme' => $name,
            'type' => $type,
            'in' => $in,
            'name' => $keyName,
            'description' => $description,
        ]);
    }

    /**
     * @param list<array{method:string, route:string, handler:array{0:string,1:string}, middleware:list<string>}> $routes
     * @return array<string, PathItem>
     */
    private function buildPaths(array $routes): array
    {
        $byPath = [];
        foreach ($routes as $entry) {
            $openApiPath = $this->normalisePath($entry['route']);

            $pathItem = $byPath[$openApiPath] ?? new PathItem(['path' => $openApiPath]);

            $this->buildAndAttach($pathItem, $entry);
            $byPath[$openApiPath] = $pathItem;
        }

        return $byPath;
    }

    /**
     * Converts FastRoute patterns like `/api/v1/agent-templates/{id:.+}` to OpenAPI's
     * `{id}` form. The regex constraint stays a server-side concern; OpenAPI's curly-brace
     * templating doesn't carry regex.
     */
    private function normalisePath(string $route): string
    {
        return preg_replace('/\{([a-zA-Z_]\w*):[^\}]+\}/', '{$1}', $route) ?? $route;
    }

    /**
     * Builds the operation for this entry and attaches it onto the matching `PathItem`
     * property. Doing both in one method keeps the per-method-operation subclass narrow
     * throughout (PHPStan assigns the right concrete type per match arm), so each
     * `PathItem::{get|post|...}` setter accepts the value.
     *
     * @param array{method:string, route:string, handler:array{0:string,1:string}, middleware:list<string>} $entry
     */
    private function buildAndAttach(PathItem $pathItem, array $entry): void
    {
        $summary = $this->humaniseHandler($entry['handler']);
        $tags = $this->tagsFromPath($entry['route']);
        $parameters = $this->parametersFromPath($entry);
        $security = $this->securityFromMiddleware($entry['middleware']);
        $responses = $this->defaultResponses();

        match ($entry['method']) {
            'GET' => $pathItem->get = new OA\Get([
                'method' => 'get',
                'summary' => $summary,
                'tags' => $tags,
                'parameters' => $parameters,
                'security' => $security,
                'responses' => $responses,
            ]),
            'POST' => $pathItem->post = new OA\Post([
                'method' => 'post',
                'summary' => $summary,
                'tags' => $tags,
                'parameters' => $parameters,
                'security' => $security,
                'responses' => $responses,
            ]),
            'PUT' => $pathItem->put = new OA\Put([
                'method' => 'put',
                'summary' => $summary,
                'tags' => $tags,
                'parameters' => $parameters,
                'security' => $security,
                'responses' => $responses,
            ]),
            'PATCH' => $pathItem->patch = new OA\Patch([
                'method' => 'patch',
                'summary' => $summary,
                'tags' => $tags,
                'parameters' => $parameters,
                'security' => $security,
                'responses' => $responses,
            ]),
            'DELETE' => $pathItem->delete = new OA\Delete([
                'method' => 'delete',
                'summary' => $summary,
                'tags' => $tags,
                'parameters' => $parameters,
                'security' => $security,
                'responses' => $responses,
            ]),
            'HEAD' => $pathItem->head = new OA\Head([
                'method' => 'head',
                'summary' => $summary,
                'tags' => $tags,
                'parameters' => $parameters,
                'security' => $security,
                'responses' => $responses,
            ]),
            'OPTIONS' => $pathItem->options = new OA\Options([
                'method' => 'options',
                'summary' => $summary,
                'tags' => $tags,
                'parameters' => $parameters,
                'security' => $security,
                'responses' => $responses,
            ]),
            default => null,
        };
    }

    /**
     * @param array{0:string, 1:string} $handler
     */
    private function humaniseHandler(array $handler): string
    {
        $controller = $handler[0];
        $action = $handler[1];

        $base = substr($controller, strrpos($controller, '\\') + 1);
        $base = preg_replace('/Controller$/', '', $base) ?? $base;

        return ucfirst($action) . ' ' . $base;
    }

    /**
     * @return list<string>
     */
    private function tagsFromPath(string $route): array
    {
        if (!str_starts_with($route, '/api/v1/')) {
            return [];
        }

        $segment = substr($route, strlen('/api/v1/'));
        $segment = explode('/', $segment)[0];

        return $segment !== '' ? [ucfirst($segment)] : [];
    }

    /**
     * @param array{method:string, route:string, handler:array{0:string,1:string}, middleware:list<string>} $entry
     * @return list<Parameter>
     */
    private function parametersFromPath(array $entry): array
    {
        preg_match_all('/\{([a-zA-Z_]\w*)(?::[^\}]+)?\}/', $entry['route'], $matches);

        $names = $matches[1];
        $types = $this->resolveParamTypes($entry['handler']);

        $out = [];
        foreach ($names as $name) {
            $out[] = new Parameter([
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => $types[$name] ?? 'string'],
            ]);
        }

        return $out;
    }

    /**
     * @param array{0:string, 1:string} $handler
     * @return array<string, string>
     */
    private function resolveParamTypes(array $handler): array
    {
        [$class, $method] = $handler;
        if (!class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionMethod($class, $method);
        $out = [];
        foreach ($reflection->getParameters() as $param) {
            $type = (string) ($param->getType()?->__toString() ?: '');
            $out[$param->getName()] = match ($type) {
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                default => 'string',
            };
        }

        return $out;
    }

    /**
     * @param list<string> $middleware
     * @return list<array<string, list<string>>>
     */
    private function securityFromMiddleware(array $middleware): array
    {
        $schemes = [];

        foreach ($middleware as $class) {
            $name = match ($class) {
                AuthMiddleware::class => 'cookieAuth',
                CsrfMiddleware::class => 'csrfToken',
                AdminMiddleware::class => 'cookieAuth',
                default => null,
            };
            if ($name !== null) {
                $schemes[$name] = [];
            }
        }

        $out = [];
        foreach ($schemes as $name => $scopes) {
            $out[] = [$name => $scopes];
        }

        return $out;
    }

    /**
     * @return array<string, Response>
     */
    private function defaultResponses(): array
    {
        return [
            'default' => new Response([
                'response' => 'default',
                'description' => 'JSON envelope: `{data: ...}` on success, `{error: {code, message}}` on error.',
            ]),
        ];
    }
}
