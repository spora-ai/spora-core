<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Service interface for notification CRUD operations.
 */
interface NotificationServiceInterface
{
    /**
     * Get paginated notifications for a user.
     *
     * @return array{data: list<array>, pagination: array{total: int, per_page: int, current_page: int, last_page: int}}
     */
    public function getNotifications(int $userId, int $perPage, bool $unreadOnly): array;

    /**
     * Mark a notification as read.
     *
     * @return array<string, mixed>|null
     */
    public function markAsRead(int $notificationId, int $userId): ?array;

    /**
     * Mark all unread notifications as read for a user.
     */
    public function markAllAsRead(int $userId): void;

    /**
     * Delete a notification.
     */
    public function deleteNotification(int $notificationId, int $userId): bool;

    /**
     * Delete all notifications for a user.
     */
    public function deleteAllForUser(int $userId): void;
}
