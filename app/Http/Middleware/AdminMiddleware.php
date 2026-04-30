<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Spora\Auth\AuthService;
use Spora\Models\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminMiddleware
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $this->authService->currentUserId();

        if ($userId === null) {
            return new JsonResponse(
                ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.']],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $user = User::find($userId);

        if ($user === null || !$user->isAdmin()) {
            return new JsonResponse(
                ['error' => ['code' => 'FORBIDDEN', 'message' => 'Admin access required.']],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
