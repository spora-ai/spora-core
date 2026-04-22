<?php

declare(strict_types=1);

namespace Spora\Http;

use Illuminate\Support\Carbon;
use Spora\Auth\AuthService;
use Spora\Http\Middleware\AuthGuard;
use Spora\Models\Notification;
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
        $userId = AuthGuard::requireAuth($this->authService);

        $perPage = min((int) ($request->query->get('per_page', 20)), 100);
        if ($perPage < 1) {
            $perPage = 20;
        }

        $query = Notification::where('user_id', $userId)->orderByDesc('created_at');

        if ($request->query->get('unread_only') === 'true') {
            $query->whereNull('read_at');
        }

        $paginator = $query->paginate($perPage);

        $data = $paginator->getCollection()->map(fn(Notification $n) => $this->resource($n))->all();

        return new JsonResponse([
            'data' => [
                'notifications' => $data,
                'pagination'   => [
                    'total'       => $paginator->total(),
                    'per_page'    => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page'   => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/notifications/{id}/read
     * Mark a single notification as read. Verifies ownership.
     */
    public function markRead(Request $request): JsonResponse
    {
        $userId         = AuthGuard::requireAuth($this->authService);
        $notificationId = (int) $request->attributes->get('id', 0);

        $notification = Notification::where('id', $notificationId)->where('user_id', $userId)->first();

        if ($notification === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Notification not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($notification->read_at === null) {
            $notification->read_at = Carbon::now();
            $notification->save();
        }

        return new JsonResponse(['data' => ['notification' => $this->resource($notification)]]);
    }

    /**
     * POST /api/v1/notifications/read-all
     * Mark all unread notifications as read for the authenticated user.
     */
    public function markAllRead(): JsonResponse
    {
        $userId = AuthGuard::requireAuth($this->authService);

        Notification::where('user_id', $userId)->whereNull('read_at')->update(['read_at' => Carbon::now()]);

        return new JsonResponse(['data' => ['marked' => true]]);
    }

    /**
     * DELETE /api/v1/notifications/{id}
     * Delete a notification. Verifies ownership.
     */
    public function destroy(Request $request): Response
    {
        $userId         = AuthGuard::requireAuth($this->authService);
        $notificationId = (int) $request->attributes->get('id', 0);

        $notification = Notification::where('id', $notificationId)->where('user_id', $userId)->first();

        if ($notification === null) {
            return new JsonResponse(
                ['error' => ['code' => 'NOT_FOUND', 'message' => 'Notification not found.']],
                Response::HTTP_NOT_FOUND,
            );
        }

        $notification->delete();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function resource(Notification $notification): array
    {
        return [
            'id'         => $notification->id,
            'type'       => $notification->type,
            'title'      => $notification->title,
            'body'       => $notification->body,
            'data'       => $notification->data,
            'read_at'    => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
