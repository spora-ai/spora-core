<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeInterface;
use Spora\Auth\AuthService;
use Spora\Models\Principal;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Principal-discovery endpoints.
 *
 *   GET /api/v1/principals/me — the primary "who am I allowed to act as"
 *                               enumerable. The dashboard uses this to
 *                               populate the principal selector (own
 *                               user-principal + every group-principal
 *                               of which the caller is a member).
 */
final class PrincipalController
{
    use JsonControllerHelpers;

    private PrincipalResolver $resolver;

    public function __construct(
        private readonly AuthService $authService,
        ?PrincipalResolver $resolver = null,
    ) {
        $this->resolver = $resolver ?? new PrincipalResolver();
    }

    /**
     * GET /api/v1/principals/me
     *
     * Returns the principal rows the caller can act as: their own
     * user-principal (auto-created if missing) and the group-principal
     * for every group they belong to. The response is a flat list;
     * each entry carries the principal type, the linked user_id /
     * group_id, and a derived display label for the UI.
     */
    public function currentForUser(): JsonResponse
    {
        $userId = $this->authService->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $principalIds = $this->resolver->visiblePrincipalIds($userId);
        if ($principalIds === []) {
            // No user-principal yet. Surface an empty list so the UI
            // can render the "no principals" state without a second
            // round-trip; the next call that creates an agent will
            // materialise the principal.
            return new JsonResponse(['data' => ['principals' => []]]);
        }

        $principals = Principal::whereIn('id', $principalIds)->get();
        $payload = $principals->map(static function (Principal $p): array {
            return [
                'id'        => (int) $p->id,
                'type'      => (string) $p->type,
                'user_id'   => $p->user_id !== null ? (int) $p->user_id : null,
                'group_id'  => $p->group_id !== null ? (int) $p->group_id : null,
                'created_at' => $p->created_at->format(DateTimeInterface::ATOM),
                'updated_at' => $p->updated_at->format(DateTimeInterface::ATOM),
            ];
        })->values()->all();

        return new JsonResponse(['data' => ['principals' => $payload]]);
    }
}
