<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Spora\Auth\AuthService;
use Spora\Http\Exceptions\UnauthenticatedException;

/**
 * Authentication guard.
 * Call {@see requireAuth} at the top of any protected controller action.
 * Throws {@see UnauthenticatedException} if unauthenticated — caught by the
 * Kernel and converted to a 401 JSON response automatically.
 */
final class AuthGuard
{
    /**
     * Ensure the current request is authenticated.
     *
     * @return int the authenticated user's ID
     * @throws UnauthenticatedException
     */
    public static function requireAuth(AuthService $auth): int
    {
        $userId = $auth->currentUserId();

        if ($userId === null) {
            throw new UnauthenticatedException('Authentication required.');
        }

        return $userId;
    }
}
