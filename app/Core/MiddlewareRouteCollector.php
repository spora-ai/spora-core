<?php

declare(strict_types=1);

namespace Spora\Core;

use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as RouteParserStd;

/**
 * Extended RouteCollector that captures a 4th middleware argument per route.
 * Middleware arrays are stored in $routeMiddleware keyed by method|pattern.
 */
final class MiddlewareRouteCollector extends RouteCollector
{
    /** @var array<string, array<int, class-string>> Method|Pattern => middleware list */
    private static array $routeMiddleware = [];

    /** @var array<int, string> List of method|pattern keys for iteration */
    private static array $methodAndPatterns = [];

    public function __construct(
        \FastRoute\RouteParser $parser,
        \FastRoute\DataGenerator $dataGenerator,
    ) {
        parent::__construct($parser, $dataGenerator);
    }

    public function addRoute($httpMethod, $route, $handler, array $middleware = []): void
    {
        parent::addRoute($httpMethod, $route, $handler);

        $fullPattern = $this->currentGroupPrefix . $route;
        $key = "{$httpMethod}|{$fullPattern}";

        if ($middleware !== []) {
            self::$routeMiddleware[$key] = $middleware;
            self::$methodAndPatterns[] = $key;
        }
    }

    /**
     * Returns all captured middleware indexed by method|pattern.
     *
     * @return array<string, array<int, class-string>>
     */
    public static function getRouteMiddleware(): array
    {
        return self::$routeMiddleware;
    }

    /**
     * Finds middleware for a given HTTP method and concrete path.
     * Matches the path against registered route patterns using FastRoute's parser.
     *
     * @return array<int, class-string>
     */
    public static function findMiddleware(string $method, string $path): array
    {
        $routeParser = new RouteParserStd();

        foreach (self::$methodAndPatterns as $key) {
            [$routeMethod, $pattern] = explode('|', $key, 2);

            // Method must match
            if ($routeMethod !== $method) {
                continue;
            }

            // Try to match path against pattern
            if (self::pathMatchesPattern($path, $pattern, $routeParser)) {
                return self::$routeMiddleware[$key] ?? [];
            }
        }

        return [];
    }

    /**
     * Checks if a concrete path matches a route pattern.
     *
     * @param RouteParserStd $routeParser
     */
    private static function pathMatchesPattern(string $path, string $pattern, RouteParserStd $routeParser): bool
    {
        // Parse the pattern into segments
        $parsed = $routeParser->parse($pattern);

        // Build a regex from each alternative (FastRoute supports multiple per route)
        foreach ($parsed as $alternative) {
            $regex = self::segmentsToRegex($alternative);
            if (preg_match('#^' . $regex . '$#', $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Converts parsed route segments into a regex pattern.
     *
     * @param list<string|array{0: string, 1: string}> $segments
     */
    private static function segmentsToRegex(array $segments): string
    {
        $regex = '';

        foreach ($segments as $segment) {
            // Dynamic segment: [paramName, regexPart]
            if (is_array($segment)) {
                [$name, $regexPart] = $segment;
                $regex .= '(?<' . $name . '>' . $regexPart . ')';
            } else {
                // Static segment - escape special regex characters
                $regex .= preg_quote($segment, '#');
            }
        }

        return $regex;
    }

    /**
     * Clears the middleware map. Useful between test runs.
     */
    public static function clear(): void
    {
        self::$routeMiddleware = [];
        self::$methodAndPatterns = [];
    }
}
