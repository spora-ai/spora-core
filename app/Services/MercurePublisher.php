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
 *   SPORA_MERCURE_URL               Full hub URL, e.g. https://example.com/.well-known/mercure
 *   SPORA_MERCURE_JWT_KEY           JWT key that Mercure uses to validate publisher tokens
 *   SPORA_MERCURE_LEGACY_USER_TOPIC Set to "true" to also publish to the legacy
 *                                   user-keyed topic for one release during the
 *                                   fan-out rollout. Default: false (new topic only).
 *
 * When SPORA_MERCURE_URL is not set, publish*() is a no-op (Mercure is optional).
 */
final class MercurePublisher implements MercurePublisherInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ?string $hubUrl = null,
        private readonly ?string $jwtKey = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $legacyUserTopicEnabled = false,
        private readonly ?PrincipalResolver $principalResolver = null,
    ) {}

    /**
     * Publish a task state change keyed by principal owner.
     * Topic: principal/{principalId}/tasks — every principal viewer
     * (group members included) with `subscribe` rights receives the event.
     */
    public function publishForPrincipal(int $taskId, int $principalId, array $taskData): bool
    {
        if ($this->hubUrl === null || $this->jwtKey === null) {
            $this->logger?->debug('MercurePublisher: publishForPrincipal skipped - Mercure not configured');
            return false;
        }

        $topic = "principal/{$principalId}/tasks";
        $ok = $this->doPublish($taskId, $principalId, $topic, $taskData);

        // Rollout fallback: also publish to user-keyed topics for every
        // visible user so currently-deployed frontends keep receiving
        // events until they migrate to the subscription fan-out. Removed
        // in the release after the frontend cutover.
        if ($ok && $this->legacyUserTopicEnabled && $this->principalResolver !== null) {
            $userIds = $this->principalResolver->visibleUserIds($principalId);
            foreach ($userIds as $userId) {
                $this->doPublish($taskId, $userId, "user/{$userId}/tasks", $taskData);
            }
        }

        return $ok;
    }

    /**
     * @deprecated Use {@see publishForPrincipal()} — group peers do not
     *             receive events on this user-keyed topic. Retained
     *             during the Plan B migration window for any callers
     *             that have not yet been swept.
     */
    public function publish(int $taskId, int $userId, array $taskData): bool
    {
        if ($this->hubUrl === null || $this->jwtKey === null) {
            $this->logger?->debug('MercurePublisher: publish skipped - Mercure not configured');
            return false;
        }

        return $this->doPublish($taskId, $userId, "user/{$userId}/tasks", $taskData);
    }

    /**
     * Publish a user-scoped notification to the Mercure hub.
     * Topic: user/{userId}/notifications
     */
    public function publishToUser(int $userId, array $data): bool
    {
        if ($this->hubUrl === null || $this->jwtKey === null) {
            $this->logger?->debug('MercurePublisher: publishToUser skipped - Mercure not configured');
            return false;
        }

        return $this->doPublish(null, $userId, "user/{$userId}/notifications", $data);
    }

    private function doPublish(?int $taskId, int $subjectId, string $topic, array $payload): bool
    {
        $this->logger?->debug('MercurePublisher: publish called', [
            'task_id'    => $taskId,
            'subject_id' => $subjectId,
            'topic'      => $topic,
            'hub_url'    => $this->hubUrl,
        ]);

        try {
            $response = $this->client->request('POST', $this->hubUrl, [
                'auth_bearer' => $this->generatePublisherJwt($subjectId, $topic),
                // Hard timeouts on connect and the full request — a wedged
                // Mercure hub must NOT block the agent loop.
                'timeout'      => 3.0,
                'max_duration' => 5.0,
                'body'         => [
                    'topic' => $topic,
                    'data'  => json_encode(['topic' => $topic, 'data' => $payload], JSON_THROW_ON_ERROR),
                ],
            ]);
            $kind = str_ends_with($topic, '/notifications') ? 'user notification' : 'task event';
            $this->logger?->info("MercurePublisher: published {$kind}", [
                'task_id'     => $taskId,
                'subject_id'  => $subjectId,
                'topic'       => $topic,
                'http_status' => $response->getStatusCode(),
            ]);
            return true;
        } catch (Throwable $e) {
            $this->logger?->error('MercurePublisher: publish failed', [
                'task_id'    => $taskId,
                'subject_id' => $subjectId,
                'topic'      => $topic,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Generate a minimal HS256 JWT for the Mercure publisher role.
     * Uses base64url encoding (RFC 7515) and a single timestamp to avoid clock-skew bugs.
     */
    private function generatePublisherJwt(int $userId, string $topic): string
    {
        $now     = time();
        $header  = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64url(json_encode([
            'iat'     => $now,
            'exp'     => $now + 60,
            'mercure' => ['publish' => [$topic]],
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
