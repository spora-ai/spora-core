<?php

declare(strict_types=1);

namespace Spora\Http;

use InvalidArgumentException;
use Spora\Auth\AuthService;
use Spora\Models\NotificationSubscription;
use Spora\Models\Principal;
use Spora\Services\NotificationSubscriptionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-user notification subscription management.
 *
 * Endpoints:
 *   GET    /api/v1/notifications/subscriptions
 *   POST   /api/v1/notifications/subscriptions
 *   DELETE /api/v1/notifications/subscriptions
 *
 * The dispatch path is {@see \Spora\Services\NotificationService::sendEmailForScheduledRun()},
 * which reads the same table to resolve recipients.
 */
final class NotificationSubscriptionController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly NotificationSubscriptionService $subscriptions,
        private readonly array $config = [],
    ) {}

    /**
     * GET /api/v1/notifications/subscriptions
     * Returns the caller's subscriptions plus `user_principal_id` (for
     * the "My personal agents" row) and `email_enabled` (for the
     * server-disabled banner).
     */
    public function index(): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        $userPrincipalId = $this->resolveUserPrincipalId($userId);

        $rows = $this->subscriptions->getSubscriptionsForUser($userId);

        $data = array_map(
            static fn(NotificationSubscription $s): array => [
                'id'          => (int) $s->id,
                'user_id'     => (int) $s->user_id,
                'target_type' => (string) $s->target_type,
                'target_id'   => (int) $s->target_id,
                'created_at'  => $s->created_at->toIso8601String(),
            ],
            $rows->all(),
        );

        return new JsonResponse([
            'data' => [
                'email_enabled'      => $this->resolveEmailEnabled(),
                'user_principal_id' => $userPrincipalId,
                'subscriptions'      => $data,
            ],
        ]);
    }

    private function resolveUserPrincipalId(int $userId): ?int
    {
        $principal = Principal::query()
            ->where('type', Principal::TYPE_USER)
            ->where('user_id', $userId)
            ->first();

        return $principal !== null ? (int) $principal->id : null;
    }

    /**
     * Server-wide kill switch for scheduled-run emails. Must agree
     * with NotificationService's lookup or the UI banner will lie.
     */
    private function resolveEmailEnabled(): bool
    {
        $config = $this->config;

        return (bool) ($config['notifications']['email_enabled'] ?? true);
    }

    /**
     * POST /api/v1/notifications/subscriptions
     * Subscribe the authenticated user to a target. Idempotent.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        try {
            [$targetType, $targetId] = $this->parseTarget($request);
        } catch (InvalidArgumentException $e) {
            return $this->badRequest($e->getMessage());
        }

        $this->subscriptions->subscribeUserToTarget($userId, $targetType, $targetId);

        return new JsonResponse([
            'data' => [
                'subscribed' => true,
                'target_type' => $targetType,
                'target_id'   => $targetId,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/notifications/subscriptions
     * Unsubscribe the authenticated user from a target. Idempotent.
     * Accepts the target as either a JSON body or query-string params —
     * the query form is the safe interop surface since some HTTP stacks
     * strip the body from DELETE.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        try {
            [$targetType, $targetId] = $this->parseTarget($request);
        } catch (InvalidArgumentException $e) {
            return $this->badRequest($e->getMessage());
        }

        $this->subscriptions->unsubscribeUserFromTarget($userId, $targetType, $targetId);

        return new JsonResponse([
            'data' => [
                'unsubscribed' => true,
                'target_type'  => $targetType,
                'target_id'    => $targetId,
            ],
        ]);
    }

    /**
     * @return array{0: string, 1: int}
     *
     * @throws InvalidArgumentException
     */
    private function parseTarget(Request $request): array
    {
        $payload = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($payload)) {
            $payload = [];
        }

        // Body first (POST), then query / form params (DELETE).
        $targetType = $payload['target_type']
            ?? $request->query->get('target_type')
            ?? $request->request->get('target_type');
        $targetId = $payload['target_id']
            ?? $request->query->get('target_id')
            ?? $request->request->get('target_id');

        if (!is_string($targetType)
            || !in_array($targetType, [
                NotificationSubscription::TARGET_AGENT,
                NotificationSubscription::TARGET_PRINCIPAL,
            ], true)
        ) {
            throw new InvalidArgumentException(
                "target_type must be '" . NotificationSubscription::TARGET_AGENT
                . "' or '" . NotificationSubscription::TARGET_PRINCIPAL . "'.",
            );
        }

        $targetId = is_numeric($targetId) ? (int) $targetId : 0;
        if ($targetId < 1) {
            throw new InvalidArgumentException('target_id must be a positive integer.');
        }

        return [$targetType, $targetId];
    }

    private function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'INVALID_REQUEST', 'message' => $message]],
            Response::HTTP_BAD_REQUEST,
        );
    }
}
