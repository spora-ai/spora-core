<?php

declare(strict_types=1);

namespace Spora\Services;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Publishes task state change events to a Mercure hub for real-time SSE delivery.
 *
 * Configuration (env vars):
 *   SPORA_MERCURE_URL      Full hub URL, e.g. https://example.com/.well-known/mercure
 *   SPORA_MERCURE_JWT_KEY  JWT key that Mercure uses to validate publisher tokens
 *
 * When SPORA_MERCURE_URL is not set, publish() is a no-op (Mercure is optional).
 */
final class MercurePublisher implements MercurePublisherInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ?string $hubUrl = null,
        private readonly ?string $jwtKey = null,
    ) {}

    /**
     * Publish a task state change to the Mercure hub.
     * Topics are namespaced per task so subscribers receive only relevant updates.
     */
    public function publish(int $taskId, array $taskData): bool
    {
        if ($this->hubUrl === null || $this->jwtKey === null) {
            return false;
        }

        try {
            $this->client->request('POST', $this->hubUrl, [
                'auth_bearer' => $this->generateJwt(),
                'body'        => [
                    'topic' => "task/{$taskId}",
                    'data'  => json_encode(['topic' => "task/{$taskId}", 'data' => $taskData], JSON_THROW_ON_ERROR),
                ],
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Publish a user-scoped notification to the Mercure hub.
     * Topic: user/{userId}/notifications
     */
    public function publishToUser(int $userId, array $data): bool
    {
        if ($this->hubUrl === null || $this->jwtKey === null) {
            return false;
        }

        try {
            $this->client->request('POST', $this->hubUrl, [
                'auth_bearer' => $this->generateJwt(),
                'body'        => [
                    'topic' => "user/{$userId}/notifications",
                    'data'  => json_encode(['topic' => "user/{$userId}/notifications", 'data' => $data], JSON_THROW_ON_ERROR),
                ],
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Generate a minimal HS256 JWT for the Mercure publisher role.
     * Uses base64url encoding (RFC 7515) and a single timestamp to avoid clock-skew bugs.
     */
    private function generateJwt(): string
    {
        $now     = time();
        $header  = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64url(json_encode([
            'iat'     => $now,
            'exp'     => $now + 60,
            'mercure' => ['publish' => ['task/*', 'user/*/notifications']],
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
