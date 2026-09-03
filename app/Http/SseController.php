<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Http\Exceptions\MercureConfigurationMissingException;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * SSE authentication endpoints for Mercure subscriber tokens. The browser
 * EventSource API cannot attach a Bearer header, so the subscriber token rides
 * in an HttpOnly cookie scoped to the hub path; `authorize()` is the path
 * browsers use (called once before opening the EventSource with
 * `withCredentials: true`), and `auth()` returns a JSON token for non-browser
 * clients.
 */
final class SseController
{
    private const HUB_PATH = '/.well-known/mercure';
    private const SUBSCRIBER_COOKIE_NAME = 'mercure_access_token';
    private const SUBSCRIBER_COOKIE_SECURE_NAME = '__Secure-mercure_access_token';
    private const SUBSCRIBER_COOKIE_PATH = self::HUB_PATH;
    private const SUBSCRIBER_TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private readonly AuthService $authService,
        private readonly PrincipalResolver $principalResolver,
        private readonly ?string $hubUrl = null,
        private readonly ?string $jwtKey = null,
        private readonly ?string $publicUrl = null,
        private readonly ?string $appUrl = null,
    ) {}

    public function status(): JsonResponse
    {
        if ($this->hubUrl === null) {
            return new JsonResponse(['active' => false]);
        }

        return new JsonResponse([
            'active' => true,
            'hubUrl' => $this->publicUrl ?? self::HUB_PATH,
        ]);
    }

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
            'hubUrl'  => $this->publicUrl ?? self::HUB_PATH,
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
            'hubUrl' => $this->publicUrl ?? self::HUB_PATH,
            'token'  => $token,
        ]);
    }

    private function generateSubscriberJwt(int $userId): string
    {
        if ($this->jwtKey === null) {
            throw new MercureConfigurationMissingException('Mercure JWT key is not configured. Set SPORA_MERCURE_JWT_KEY.');
        }

        // Task events are principal-keyed (so group peers receive them);
        // notifications stay user-keyed (the notifications table is per-user).
        $principalIds = $this->principalResolver->visiblePrincipalIds($userId);
        $taskTopics = array_map(
            static fn(int $pid): string => "principal/{$pid}/tasks",
            $principalIds,
        );

        $subscribeTopics = array_merge(
            $taskTopics,
            ["user/{$userId}/notifications"],
        );

        $now     = time();
        $header  = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64url(json_encode([
            'iat'     => $now,
            'exp'     => $now + self::SUBSCRIBER_TOKEN_TTL_SECONDS,
            'mercure' => [
                'subscribe' => $subscribeTopics,
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
