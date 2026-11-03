<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Spora\Auth\ApiKeyScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bearer surface for the whole install: `/api/v1/global-api/*`.
 *
 * Only a global-scoped key authenticates here. A group key would otherwise
 * inherit the global reach, which is the one way the two scopes could
 * overlap.
 */
final class GlobalApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ApiKeyAuthenticator $authenticator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->authenticator->authenticate($request, ApiKeyScope::Global, $next);
    }
}
