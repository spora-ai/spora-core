<?php

declare(strict_types=1);

namespace Spora\Http;

use RuntimeException;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides SSE authentication endpoint for Mercure subscriber tokens.
 */
final class SseController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly ?string $hubUrl = null,
        private readonly ?string $jwtKey = null,
    ) {}

    /**
     * GET /api/v1/sse/status
     *
     * Returns whether SSE/Mercure is configured and active.
     */
    public function status(): JsonResponse
    {
        if ($this->hubUrl === null) {
            return new JsonResponse(['active' => false]);
        }

        return new JsonResponse([
            'active' => true,
            'hubUrl' => $this->hubUrl,
        ]);
    }

    /**
     * GET /api/v1/sse/auth
     *
     * Returns the Mercure hub URL and a subscriber-scoped JWT token.
     * The token is scoped to:
     *   - topic "task/*"
     *   - topic "user/{userId}/notifications"
     */
    public function auth(): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        if ($this->hubUrl === null || $this->jwtKey === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_CONFIGURED', 'message' => 'SSE not available']],
                404,
            );
        }

        $token = $this->generateSubscriberJwt($userId);

        return new JsonResponse([
            'hubUrl' => $this->hubUrl,
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
            throw new RuntimeException('Mercure JWT key is not configured. Set SPORA_MERCURE_JWT_KEY.');
        }

        $now     = time();
        $header  = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64url(json_encode([
            'iat'     => $now,
            'exp'     => $now + 3600, // 1-hour validity for SSE connections
            'mercure' => [
                'subscribe' => [
                    'task/*',
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
