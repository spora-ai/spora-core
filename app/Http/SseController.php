<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Http\Exceptions\MercureConfigurationMissingException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides SSE authentication endpoints for Mercure subscriber tokens.
 *
 * The browser EventSource API cannot attach an Authorization header, so the
 * subscriber token rides in an HttpOnly cookie scoped to the hub path. The
 * frontend opens the EventSource with `withCredentials: true` and the cookie
 * rides the same-origin request to `/.well-known/mercure`.
 */
final class SseController
{
    private const SUBSCRIBER_COOKIE_NAME = 'mercure_access_token';
    private const SUBSCRIBER_COOKIE_SECURE_NAME = '__Secure-mercure_access_token';
    private const SUBSCRIBER_COOKIE_PATH = '/.well-known/mercure';
    private const SUBSCRIBER_TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly AuthService $authService,
        private readonly ?string $hubUrl = null,
        private readonly ?string $jwtKey = null,
        private readonly ?string $publicUrl = null,
        private readonly ?string $appUrl = null,
    ) {}

    /**
     * GET /api/v1/sse/status
     *
     * Returns whether SSE/Mercure is configured and active.
     * Returns a relative path for hubUrl so the browser resolves it against window.location.origin.
     */
    public function status(): JsonResponse
    {
        if ($this->hubUrl === null) {
            return new JsonResponse(['active' => false]);
        }

        return new JsonResponse([
            'active' => true,
            'hubUrl' => $this->publicUrl ?? '/.well-known/mercure',
        ]);
    }

    /**
     * GET /api/v1/sse/authorize
     *
     * Sets the subscriber JWT as an HttpOnly cookie scoped to the hub path
     * and returns the cookie TTL so the frontend can refresh before expiry.
     * Mirrors `auth()` so non-browser clients still get a JSON token, but
     * the cookie is the path browsers can carry into `EventSource`.
     */
    public function authorize(): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        if ($this->hubUrl === null || $this->jwtKey === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_CONFIGURED', 'message' => 'SSE not available']],
                404,
            );
        }

        $token = $this->generateSubscriberJwt($userId);
        $isSecure = $this->appUrl !== null && str_starts_with($this->appUrl, 'https://');

        $response = new JsonResponse([
            'hubUrl'  => $this->publicUrl ?? '/.well-known/mercure',
            'expires' => time() + self::SUBSCRIBER_TOKEN_TTL_SECONDS,
        ]);

        $cookie = Cookie::create(
            $isSecure ? self::SUBSCRIBER_COOKIE_SECURE_NAME : self::SUBSCRIBER_COOKIE_NAME,
            $token,
            time() + self::SUBSCRIBER_TOKEN_TTL_SECONDS,
            self::SUBSCRIBER_COOKIE_PATH,
            null,
            $isSecure,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
        $response->headers->setCookie($cookie);

        return $response;
    }

    /**
     * GET /api/v1/sse/auth
     *
     * Returns the Mercure hub URL and a subscriber-scoped JWT token.
     * The token is scoped to:
     *   - topic "user/{userId}/tasks"
     *   - topic "user/{userId}/notifications"
     */
    public function auth(): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        if ($this->hubUrl === null || $this->jwtKey === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_CONFIGURED', 'message' => 'SSE not available']],
                404,
            );
        }

        $token = $this->generateSubscriberJwt($userId);

        return new JsonResponse([
            'hubUrl' => $this->publicUrl ?? '/.well-known/mercure',
            'token'  => $token,
        ]);
    }

    /**
     * Generate an HS256 subscriber JWT scoped to task/* and user/{userId}/notifications.
     * Subscriber role (read-only), not publisher.
     */
    private function generateSubscriberJwt(int $userId): string
    {
        if ($this->jwtKey === null) {
            throw new MercureConfigurationMissingException('Mercure JWT key is not configured. Set SPORA_MERCURE_JWT_KEY.');
        }

        $now     = time();
        $header  = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64url(json_encode([
            'iat'     => $now,
            'exp'     => $now + self::SUBSCRIBER_TOKEN_TTL_SECONDS,
            'mercure' => [
                'subscribe' => [
                    "user/{$userId}/tasks",
                    "user/{$userId}/notifications",
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $input = "{$header}.{$payload}";
        $hash  = hash_hmac('sha256', $input, $this->jwtKey, true);

        return $input . '.' . $this->base64url($hash);
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
