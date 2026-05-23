<?php

declare(strict_types=1);

namespace Spora\Services;

use Psr\Log\LoggerInterface;
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
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Publish a task state change to the Mercure hub.
     * Topic: task/{userId}/{taskId} — user-scoped so only the task owner receives updates.
     */
    public function publish(int $taskId, int $userId, array $taskData): bool
    {
        $this->logger?->debug('MercurePublisher: publish called', [
            'task_id' => $taskId,
            'user_id' => $userId,
            'hub_url' => $this->hubUrl,
            'has_jwt' => $this->jwtKey !== null,
        ]);

        if ($this->hubUrl === null || $this->jwtKey === null) {
            $this->logger?->warning('MercurePublisher: publish skipped - hubUrl or jwtKey is null');
            return false;
        }

        $topic = "user/{$userId}/tasks";
        try {
            $response = $this->client->request('POST', $this->hubUrl, [
                'auth_bearer' => $this->generateJwt($userId),
                'body'        => [
                    'topic' => $topic,
                    'data'  => json_encode(['topic' => $topic, 'data' => $taskData], JSON_THROW_ON_ERROR),
                ],
            ]);
            $this->logger?->info('MercurePublisher: published task event', [
                'task_id' => $taskId,
                'user_id' => $userId,
                'http_status' => $response->getStatusCode(),
            ]);
            return true;
        } catch (Throwable $e) {
            $this->logger?->error('MercurePublisher: publish failed', [
                'task_id' => $taskId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Publish a user-scoped notification to the Mercure hub.
     * Topic: user/{userId}/notifications
     */
    public function publishToUser(int $userId, array $data): bool
    {
        $this->logger?->debug('MercurePublisher: publishToUser called', [
            'user_id' => $userId,
            'hub_url' => $this->hubUrl,
            'has_jwt' => $this->jwtKey !== null,
        ]);

        if ($this->hubUrl === null || $this->jwtKey === null) {
            $this->logger?->warning('MercurePublisher: publishToUser skipped - hubUrl or jwtKey is null');
            return false;
        }

        try {
            $response = $this->client->request('POST', $this->hubUrl, [
                'auth_bearer' => $this->generateJwt($userId),
                'body'        => [
                    'topic' => "user/{$userId}/notifications",
                    'data'  => json_encode(['topic' => "user/{$userId}/notifications", 'data' => $data], JSON_THROW_ON_ERROR),
                ],
            ]);
            $this->logger?->info('MercurePublisher: published user notification', [
                'user_id' => $userId,
                'http_status' => $response->getStatusCode(),
            ]);
            return true;
        } catch (Throwable $e) {
            $this->logger?->error('MercurePublisher: publishToUser failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Generate a minimal HS256 JWT for the Mercure publisher role.
     * Uses base64url encoding (RFC 7515) and a single timestamp to avoid clock-skew bugs.
     */
    private function generateJwt(int $userId): string
    {
        $now     = time();
        $header  = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64url(json_encode([
            'iat'     => $now,
            'exp'     => $now + 60,
            'mercure' => ['publish' => ["user/{$userId}/tasks", "user/{$userId}/notifications"]],
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
