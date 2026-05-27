<?php

declare(strict_types=1);

namespace Spora\Core;

final class RequestOrigin
{
    /**
     * Detect the current request origin from $_SERVER globals.
     * Falls back to http://localhost when running in a CLI context (e.g. worker, console).
     */
    public static function detect(): string
    {
        if (\PHP_SAPI === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
            return 'http://localhost';
        }

        $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
        $host   = $_SERVER['HTTP_HOST'];
        $port   = (int) ($_SERVER['SERVER_PORT'] ?? 80);

        // Include non-standard port only when not the default for the scheme
        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
            return "{$scheme}://{$host}:{$port}";
        }

        return "{$scheme}://{$host}";
    }
}
