<?php

declare(strict_types=1);

namespace Spora\Http\Middleware;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Models\GroupMembership;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the tool-settings surface.
 *
 * Tool settings carry credentials, so they are not open to any signed-in
 * user — but requiring a global admin for them is wrong for this product:
 * the people who configure a group's tools are that group's owners and
 * admins, and there may be no global admin in the install at all. So the bar
 * is "administers at least one group", which a global admin clears by
 * definition.
 *
 * This is deliberately *not* group-scoped: the route it guards writes the
 * global tool-settings row, which every tool in the install reads. A
 * per-group write belongs to {@see \Spora\Http\GroupToolsController}, which
 * checks the specific group through `GroupAuthorizationTrait`.
 */
final class GroupAdminMiddleware implements MiddlewareInterface
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

        if (!$this->administersAnyGroup((int) $userId)) {
            return new JsonResponse(
                ['error' => ['code' => 'FORBIDDEN', 'message' => 'Group owner or admin access required.']],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }

    private function administersAnyGroup(int $userId): bool
    {
        if ($this->authService->isAdmin()) {
            return true;
        }

        return Capsule::table('group_memberships')
            ->where('user_id', $userId)
            ->whereIn('role', [GroupMembership::ROLE_OWNER, GroupMembership::ROLE_ADMIN])
            ->exists();
    }
}
