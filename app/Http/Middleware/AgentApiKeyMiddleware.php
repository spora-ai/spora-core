<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Spora\Auth\ApiKeyScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bearer surface for one agent: `/api/v1/agent-api/*`.
 *
 * A key minted for a group or for the whole install is not a credential
 * here — the surface is one agent, and only an agent-scoped key names one.
 */
final class AgentApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ApiKeyAuthenticator $authenticator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->authenticator->authenticate($request, ApiKeyScope::Agent, $next);
    }
}
