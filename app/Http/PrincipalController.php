<?php

declare(strict_types=1);

namespace Spora\Http;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Models\Principal;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\JsonResponse;

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
     * for every group they belong to. Each entry includes a derived
     * `name` (the linked user's name/email or the group's name) so the
     * principal picker can label entries without a second round-trip.
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

        return new JsonResponse([
            'data' => ['principals' => $this->serialisePrincipals($principalIds)],
        ]);
    }

    /**
     * @param  list<int> $principalIds
     * @return list<array<string, mixed>>
     */
    private function serialisePrincipals(array $principalIds): array
    {
        $principals = Principal::whereIn('id', $principalIds)->get();
        return $principals->map(static function (Principal $p): array {
            return [
                'id'         => (int) $p->id,
                'type'       => (string) $p->type,
                'name'       => self::deriveName($p),
                'user_id'    => $p->user_id !== null ? (int) $p->user_id : null,
                'group_id'   => $p->group_id !== null ? (int) $p->group_id : null,
                'created_at' => $p->created_at->format(DateTimeInterface::ATOM),
                'updated_at' => $p->updated_at->format(DateTimeInterface::ATOM),
            ];
        })->values()->all();
    }

    private static function deriveName(Principal $p): string
    {
        if ($p->type === Principal::TYPE_USER && $p->user_id !== null) {
            return self::userPrincipalName($p);
        }
        if ($p->type === Principal::TYPE_GROUP && $p->group_id !== null) {
            return self::groupPrincipalName($p);
        }
        return 'Principal #' . $p->id;
    }

    private static function userPrincipalName(Principal $p): string
    {
        $row = Capsule::table('users')
            ->where('id', $p->user_id)
            ->select(['email', 'name'])
            ->first();
        if ($row === null) {
            return 'User #' . $p->user_id;
        }
        return self::firstNonEmpty($row->name, $row->email) ?? ('User #' . $p->user_id);
    }

    private static function groupPrincipalName(Principal $p): string
    {
        $row = Capsule::table('groups')
            ->where('id', $p->group_id)
            ->select(['name'])
            ->first();
        if ($row !== null && isset($row->name) && is_string($row->name) && $row->name !== '') {
            return $row->name;
        }
        return 'Group #' . $p->group_id;
    }

    /**
     * @param  mixed $first
     * @param  mixed $second
     */
    private static function firstNonEmpty($first, $second): ?string
    {
        foreach ([$first, $second] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }
        return null;
    }
}
