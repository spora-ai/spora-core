<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Resolve the principal-id cascade consulted by {@see ToolConfigService}
 * when neither an explicit `PrincipalContext` nor a cached service is
 * in scope. Lives outside `ToolConfigService` so that umbrella stays
 * under the SonarCloud S1448 20-method-per-class ceiling; this helper
 * is a thin facade over {@see PrincipalService} and exposes two shapes:
 *
 *   - {@see resolvePrincipalIds()} returns just the ordered list for
 *     the merge loop in `getEffectiveSettings()`.
 *   - {@see resolvePrincipalIdsWithUserRef()} returns the list plus the
 *     user-principal id so the source-annotated cascade in
 *     `getEffectiveSettingsWithSource()` can tag each iterated
 *     principal as `'group'` or `'principal'` in the wire shape.
 *
 * Cascade order: `group[0..N]`, then user-principal — the user-principal
 * is iterated last so its value wins on conflict (last write wins).
 * Groups are iterated in `principal.id` ASCENDING order so the cascade
 * is stable across calls.
 *
 * `PrincipalService` is optional in the constructor for backward
 * compatibility with callers that construct the helper standalone;
 * production wires the shared container-resolved service in.
 */
final class ToolConfigPrincipalCascade
{
    public function __construct(
        private readonly ?PrincipalService $principalService = null,
    ) {}

    /**
     * Resolve the principal ids the cascade should consult, in the order
     * `group[0..N], then user-principal` so the user-principal wins on
     * conflict (last write wins). Returns an empty list when neither a
     * `PrincipalContext` nor a `?int $userId` is supplied.
     *
     * @return list<int>
     */
    public function resolvePrincipalIds(?int $userId, ?PrincipalContext $context): array
    {
        return $this->resolvePrincipalIdsWithUserRef($userId, $context)[0];
    }

    /**
     * Same as {@see resolvePrincipalIds()} but also returns the
     * user-principal id (or `null` if no `?int $userId` was supplied) so
     * callers can tag each iterated principal as `'group'` or
     * `'principal'` in the source-annotated cascade output.
     *
     * @return array{0: list<int>, 1: int|null}
     */
    public function resolvePrincipalIdsWithUserRef(?int $userId, ?PrincipalContext $context): array
    {
        if ($context !== null) {
            return [[$context->principalId], null];
        }
        if ($userId === null) {
            return [[], null];
        }

        $principalService = $this->principalService ?? new PrincipalService(new PrincipalResolver());
        $userPrincipalId = $principalService->ensureUserPrincipal($userId)->id;
        $allIds = $principalService->principalIdsForUser($userId);

        $groupIds = array_values(array_filter(
            $allIds,
            static fn(int $id): bool => $id !== $userPrincipalId,
        ));

        return [array_merge($groupIds, [$userPrincipalId]), $userPrincipalId];
    }
}
