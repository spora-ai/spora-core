<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Services\NotificationServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notification management endpoints.
 */
final class NotificationController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    /**
     * GET /api/v1/notifications
     * Returns paginated notifications for the authenticated user (newest first).
     * Optional query params:
     *   - unread_only=true  to filter only unread
     *   - per_page=N        items per page (default 20, max 100)
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $perPage = min((int) ($request->query->get('per_page', 20)), 100);
        if ($perPage < 1) {
            $perPage = 20;
        }

        $unreadOnly = $request->query->get('unread_only') === 'true';
        $result = $this->notificationService->getNotifications($userId, $perPage, $unreadOnly);

        return new JsonResponse([
            'data' => [
                'notifications' => $result['data'],
                'pagination'    => $result['pagination'],
            ],
        ]);
    }

    /**
     * POST /api/v1/notifications/{id}/read
     * Mark a single notification as read. Verifies ownership.
     */
    public function markRead(Request $request): JsonResponse
    {
        $userId         = $this->authService->currentUserId();
        $notificationId = (int) $request->attributes->get('id', 0);

        $notification = $this->notificationService->markAsRead($notificationId, $userId);

        if ($notification === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Notification not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(['data' => ['notification' => $notification]]);
    }

    /**
     * POST /api/v1/notifications/read-all
     * Mark all unread notifications as read for the authenticated user.
     */
    public function markAllRead(): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $this->notificationService->markAllAsRead($userId);

        return new JsonResponse(['data' => ['marked' => true]]);
    }

    /**
     * DELETE /api/v1/notifications/{id}
     * Delete a notification. Verifies ownership.
     */
    public function destroy(Request $request): Response
    {
        $userId         = $this->authService->currentUserId();
        $notificationId = (int) $request->attributes->get('id', 0);

        $deleted = $this->notificationService->deleteNotification($notificationId, $userId);

        if (!$deleted) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Notification not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * DELETE /api/v1/notifications
     * Delete all notifications for the authenticated user.
     */
    public function destroyAll(): JsonResponse
    {
        $userId = $this->authService->currentUserId();

        $this->notificationService->deleteAllForUser($userId);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }
}
