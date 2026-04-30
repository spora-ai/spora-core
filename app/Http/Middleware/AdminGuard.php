<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Spora\Auth\AuthService;
use Spora\Http\Exceptions\ForbiddenException;

/**
 * Admin guard.
 * Call {@see requireAdmin} at the top of any admin-only controller action.
 * Throws {@see ForbiddenException} if the user is not an admin — caught by the
 * Kernel and converted to a 403 JSON response automatically.
 */
final class AdminGuard
{
    /**
     * Ensure the current request is made by an admin user.
     *
     * @return int the authenticated admin user's ID
     * @throws ForbiddenException
     */
    public static function requireAdmin(AuthService $auth): int
    {
        $userId = $auth->currentUserId();

        if ($userId === null) {
            throw new ForbiddenException('Authentication required.');
        }

        $user = \Spora\Models\User::find($userId);

        if ($user === null || !$user->isAdmin()) {
            throw new ForbiddenException('Admin access required.');
        }

        return $userId;
    }
}
