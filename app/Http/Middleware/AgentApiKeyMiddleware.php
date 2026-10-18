<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Spora\Auth\AgentApiKeyIssuer;
use Spora\Core\Config;
use Spora\Models\Agent;
use Spora\Models\AgentApiKey;
use Spora\Services\AgentApi\AgentApiContext;
use Spora\Services\DbRateLimiter;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an Agent API request from its bearer key.
 *
 * The key is the only credential here: a valid session cookie is not a
 * fallback, because the surface is agent-scoped and a session is not. A
 * missing, malformed, unknown or wrong key all answer the same 401, so a
 * caller cannot distinguish a bad id from a bad secret.
 */
final class AgentApiKeyMiddleware implements MiddlewareInterface
{
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly AgentApiKeyIssuer $issuer,
        private readonly PrincipalResolver $principalResolver,
        private readonly DbRateLimiter $rateLimiter,
        private readonly Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $presented = $this->bearerToken($request);

        if ($presented === null) {
            return $this->unauthorized();
        }

        $id = $this->issuer->idFrom($presented);
        $secret = $this->issuer->secretFrom($presented);

        if ($id === null || $secret === null) {
            return $this->unauthorized();
        }

        $key = AgentApiKey::find($id);

        if ($key === null) {
            return $this->unauthorized();
        }

        $limit = $this->config->getInt('SPORA_AGENT_API_RATE_LIMIT_MAX') ?? 60;
        $bucket = 'agentapi:' . $id;

        if (!$this->rateLimiter->attempt($bucket, max(1, $limit), self::RATE_LIMIT_WINDOW_SECONDS)) {
            return $this->rateLimited();
        }

        [$valid] = $this->issuer->verify($secret, $key);

        if (!$valid) {
            return $this->unauthorized();
        }

        $agent = Agent::find($key->agent_id);

        if ($agent === null) {
            // The key cascades on agent deletion, so this is a race rather
            // than a reachable state — but a half-deleted agent must not
            // authenticate.
            return $this->unauthorized();
        }

        $request->attributes->set(
            'agent_api_context',
            AgentApiContext::build($key, $agent, $this->principalResolver),
        );

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if (!is_string($header) || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'A valid Agent API key is required.']],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    private function rateLimited(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'TOO_MANY_REQUESTS', 'message' => 'Agent API rate limit exceeded.']],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) self::RATE_LIMIT_WINDOW_SECONDS],
        );
    }
}
