<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spora\Auth\ApiKeyIssuer;
use Spora\Auth\ApiKeyScope;
use Spora\Core\Config;
use Spora\Models\Agent;
use Spora\Models\ApiKey;
use Spora\Models\Group;
use Spora\Services\ApiKeys\ApiKeyContext;
use Spora\Services\DbRateLimiter;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an API request from its bearer key.
 *
 * The key is the only credential here: a valid session cookie is not a
 * fallback, because the surface is key-scoped and a session is not.
 *
 * A missing, malformed, unknown or wrong key all answer the same 401,
 * always. So does a key whose scope is not the one the surface accepts: a
 * global key is not a group key, and letting it through would widen the
 * surface it was minted for. The rate limiter is consulted only after the
 * secret verifies, so a wrong secret against a real key id cannot be told
 * apart from a nonexistent key by watching for a 429: only a key that
 * actually authenticates can be limited. Bucketing per authenticated key
 * also means a leaked key id — which every callback sends in
 * `X-Spora-Key-Id` — buys an attacker nothing, because without the secret
 * the attempt never reaches the limiter.
 *
 * Shared by the three scope middlewares, which differ only in the scope
 * they accept.
 */
final class ApiKeyAuthenticator
{
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /** Minute granularity: enough for the admin tab, cheap enough for the hot path. */
    private const LAST_USED_RESOLUTION_SECONDS = 60;

    public function __construct(
        private readonly ApiKeyIssuer $issuer,
        private readonly PrincipalResolver $principalResolver,
        private readonly DbRateLimiter $rateLimiter,
        private readonly Config $config,
    ) {}

    public function authenticate(Request $request, ApiKeyScope $expected, Closure $next): Response
    {
        $context = $this->context($request, $expected);

        if ($context === null) {
            return $this->unauthorized();
        }

        $limit = $this->config->getInt('SPORA_API_RATE_LIMIT_MAX') ?? 60;
        $bucket = 'api:' . $context->key->getKey();

        if (!$this->rateLimiter->attempt($bucket, max(1, $limit), self::RATE_LIMIT_WINDOW_SECONDS)) {
            return $this->rateLimited($this->rateLimiter->retryAfterSeconds($bucket, self::RATE_LIMIT_WINDOW_SECONDS));
        }

        $request->attributes->set('api_key_context', $context);

        $this->touchLastUsed($context->key);

        return $next($request);
    }

    /**
     * The authenticated context, or null.
     *
     * Every rejection here is the same answer with the same body: a caller
     * that could tell a malformed key from an unknown id from a wrong secret
     * would learn which key ids exist. Id is not secret — every callback
     * sends it in cleartext as `X-Spora-Key-Id` — but the *combination* is,
     * and a difference in timing would leak it just as well as a difference
     * in status. Hence one exit, and the rate limiter runs only after this
     * succeeds, so a wrong-secret flood cannot lock out the real key.
     */
    private function context(Request $request, ApiKeyScope $expected): ?ApiKeyContext
    {
        $key = $this->key($request);

        if ($key === null) {
            return null;
        }

        $scope = ApiKeyScope::fromValue($key->scope);

        if ($scope === null || $scope !== $expected) {
            return null;
        }

        try {
            $scope->assertConsistent($key->agent_id, $key->group_id);
        } catch (InvalidArgumentException) {
            // A row whose columns disagree with its scope is not a
            // credential this build wrote. It authenticates as nothing.
            return null;
        }

        return match ($scope) {
            ApiKeyScope::Agent => $this->agentContext($key),
            ApiKeyScope::Group => $this->groupContext($key),
            ApiKeyScope::Global => new ApiKeyContext($key, ApiKeyScope::Global),
        };
    }

    /**
     * The key a request presents, or null.
     */
    private function key(Request $request): ?ApiKey
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

        $key = ApiKey::find($id);

        return $key !== null && $this->issuer->verify($secret, $key)[0] ? $key : null;
    }

    /**
     * A null agent means the key outlived its agent — it cascades on
     * deletion, so this is a race rather than a reachable state, but a
     * half-deleted agent must not authenticate.
     */
    private function agentContext(ApiKey $key): ?ApiKeyContext
    {
        $agent = Agent::find($key->agent_id);

        if ($agent === null) {
            return null;
        }

        return ApiKeyContext::forAgent($key, $agent, $this->principalResolver);
    }

    /**
     * A null group means the key outlived its group — it cascades on
     * deletion, so this is a race rather than a reachable state.
     */
    private function groupContext(ApiKey $key): ?ApiKeyContext
    {
        $group = Group::find($key->group_id);

        if ($group === null) {
            return null;
        }

        return new ApiKeyContext($key, ApiKeyScope::Group, null, null, (int) $group->id);
    }

    /**
     * Stamp the key's last-used time, at most once a minute.
     *
     * The column is what tells an operator which of a group's keys are still
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
    private function touchLastUsed(ApiKey $key): void
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
            ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'A valid API key is required.']],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    private function rateLimited(int $retryAfter): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'TOO_MANY_REQUESTS', 'message' => 'API rate limit exceeded.']],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
