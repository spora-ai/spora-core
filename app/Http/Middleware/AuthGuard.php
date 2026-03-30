<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Spora\Auth\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Simple authentication guard.
 * Call {@see requireAuth} at the top of any protected controller action.
 * If the user is not authenticated the guard sends a 401 response and halts execution.
 */
final class AuthGuard
{
    /**
     * Ensure the current request is authenticated.
     *
     * @return int the authenticated user's ID
     */
    public static function requireAuth(AuthService $auth): int
    {
        $userId = $auth->currentUserId();

        if ($userId === null) {
            $response = new JsonResponse(
                ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.']],
                401,
            );
            $response->send();
            exit;
        }

        return $userId;
    }
}
