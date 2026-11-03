<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Spora\Auth\ApiKeyScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bearer surface for one group: `/api/v1/group-api/*`.
 *
 * A key minted for one agent or for the whole install is not a credential
 * here — the surface is one group, and only a group-scoped key names one.
 */
final class GroupApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ApiKeyAuthenticator $authenticator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->authenticator->authenticate($request, ApiKeyScope::Group, $next);
    }
}
