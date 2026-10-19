<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Illuminate\Support\Carbon;
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
 * fallback, because the surface is agent-scoped and a session is not.
 *
 * A missing, malformed, unknown or wrong key all answer the same 401,
 * always. The rate limiter is consulted only after the secret verifies, so
 * a wrong secret against a real key id cannot be told apart from a
 * nonexistent key by watching for a 429: only a key that actually
 * authenticates can be limited. Bucketing per authenticated key also means
 * a leaked key id — which every callback sends in `X-Spora-Key-Id` — buys
 * an attacker nothing, because without the secret the attempt never
 * reaches the limiter.
 */
final class AgentApiKeyMiddleware implements MiddlewareInterface
{
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /** Minute granularity: enough for the admin tab, cheap enough for the hot path. */
    private const LAST_USED_RESOLUTION_SECONDS = 60;

    public function __construct(
        private readonly AgentApiKeyIssuer $issuer,
        private readonly PrincipalResolver $principalResolver,
        private readonly DbRateLimiter $rateLimiter,
        private readonly Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->authenticate($request);
        $agent = $key === null ? null : Agent::find($key->agent_id);

        if ($key === null || $agent === null) {
            // A null agent means the key outlived its agent — it cascades on
            // deletion, so this is a race rather than a reachable state, but a
            // half-deleted agent must not authenticate.
            return $this->unauthorized();
        }

        $limit = $this->config->getInt('SPORA_AGENT_API_RATE_LIMIT_MAX') ?? 60;
        $bucket = 'agentapi:' . $key->getKey();

        if (!$this->rateLimiter->attempt($bucket, max(1, $limit), self::RATE_LIMIT_WINDOW_SECONDS)) {
            return $this->rateLimited($this->rateLimiter->retryAfterSeconds($bucket, self::RATE_LIMIT_WINDOW_SECONDS));
        }

        $request->attributes->set(
            'agent_api_context',
            AgentApiContext::build($key, $agent, $this->principalResolver),
        );

        $this->touchLastUsed($key);

        return $next($request);
    }

    /**
     * The key a request presents, or null.
     *
     * Every rejection here is the same answer with the same body: a caller
     * that could tell a malformed key from an unknown id from a wrong secret
     * would learn which key ids exist. Id is not secret — every callback
     * sends it in cleartext as `X-Spora-Key-Id` — but the *combination* is,
     * and a difference in timing would leak it just as well as a difference
     * in status. Hence one exit, and the rate limiter runs only after this
     * succeeds, so a wrong-secret flood cannot lock out the real key.
     */
    private function authenticate(Request $request): ?AgentApiKey
    {
        $presented = $this->bearerToken($request);

        if ($presented === null) {
            return null;
        }

        $id = $this->issuer->idFrom($presented);
        $secret = $this->issuer->secretFrom($presented);

        if ($id === null || $secret === null) {
            return null;
        }

        $key = AgentApiKey::find($id);

        return $key !== null && $this->issuer->verify($secret, $key)[0] ? $key : null;
    }

    /**
     * Stamp the key's last-used time, at most once a minute.
     *
     * The column is what tells an operator which of an agent's keys are still
     * live before revoking one, so it has to be written — but on every
     * request it would put a second write on the hot path of a polling
     * integrator. The UI renders a relative time, so minute granularity costs
     * it nothing.
     *
     * Parsed as UTC rather than through the cast, which reads it back in the
     * process default timezone. The two disagree on any host whose php.ini
     * sets a local zone — `Kernel` never calls `date_default_timezone_set()` —
     * and the delta then reads as hours in one direction, which either
     * freezes the column at mint time or makes every request write.
     */
    private function touchLastUsed(AgentApiKey $key): void
    {
        $raw = $key->getRawOriginal('last_used_at');

        $last = is_string($raw)
            ? Carbon::createFromFormat('Y-m-d H:i:s', $raw, 'UTC')->getTimestamp()
            : 0;

        if (time() - $last < self::LAST_USED_RESOLUTION_SECONDS) {
            return;
        }

        $key->forceFill(['last_used_at' => Carbon::now('UTC')])->save();
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

    private function rateLimited(int $retryAfter): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'TOO_MANY_REQUESTS', 'message' => 'Agent API rate limit exceeded.']],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
