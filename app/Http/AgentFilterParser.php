<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Body-parsing helpers for {@see AgentController} extracted so the
 * controller stays under the SonarCloud S1448 20-method cap.
 *
 * These methods are stateless except for the optional `PrincipalResolver`
 * + `PrincipalService` collaborators they accept as arguments. Static
 * so they don't add an instance method to the controller per use case.
 */
final class AgentFilterParser
{
    /**
     * @return list<int>|null  Visible principal ids from the request's
     *     `?principal_id=` filter (intersected with `visiblePrincipalIds`),
     *     or null when the request omitted the filter.
     */
    public static function parsePrincipalFilter(
        ?Request $request,
        int $userId,
        ?PrincipalResolver $resolver,
        ?PrincipalService $service,
    ): ?array {
        $ids = self::parsePrincipalIds($request);
        if ($ids === null) {
            return null;
        }
        return array_values(array_intersect($ids, self::visiblePrincipalIds($userId, $resolver, $service)));
    }

    /**
     * Parse the request's `principal_id` query param(s) into a list of
     * positive integers. Accepts all three Symfony syntaxes
     * (`?principal_id=10`, `?principal_id=10&principal_id=20`,
     * `?principal_id[]=10&principal_id[]=20`) and silently ignores
     * non-digit / non-int values so a malformed client request doesn't
     * surface as an error envelope.
     *
     * @return list<int>|null  null when the filter is absent or empty.
     */
    private static function parsePrincipalIds(?Request $request): ?array
    {
        if ($request === null) {
            return null;
        }
        $raw = $request->query->all()['principal_id'] ?? null;
        if ($raw === null) {
            return null;
        }
        $values = is_array($raw) ? $raw : [$raw];
        $ids = [];
        foreach ($values as $v) {
            if (is_int($v)) {
                $ids[] = $v;
            } elseif (is_string($v) && ctype_digit($v)) {
                $ids[] = (int) $v;
            }
        }
        return $ids === [] ? null : $ids;
    }

    /**
     * @return list<int>
     */
    private static function visiblePrincipalIds(
        int $userId,
        ?PrincipalResolver $resolver,
        ?PrincipalService $service,
    ): array {
        $visible = $resolver?->visiblePrincipalIds($userId) ?? [];
        if ($visible !== [] || $service === null) {
            return $visible;
        }
        return [(int) $service->ensureUserPrincipal($userId)->id];
    }
}
