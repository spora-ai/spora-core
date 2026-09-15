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
 * **Group-cascade toggle (`$groupCascadeEnabled`)** — default off so the
 * speech-storage PR (PR #238) ships without silently broadening the
 * effective-settings read path for every tool. The LLM-parity PR flips
 * it on (env override `SPORA_TOOLS_GROUP_CASCADE_ENABLED=true` or
 * `config('spora.tools.group_cascade_enabled', true)`). When off, only
 * the user-principal is consulted — the legacy single-tier shape, which
 * matches the expectations of every tool wired before the group
 * principals landed.
 *
 * `PrincipalService` is required and is wired by the DI container;
 * no inline instantiation is permitted so the controller / service
 * graph stays single-sourced from the container (mirrors the
 * M3 cleanup in the other speech services).
 */
final class ToolConfigPrincipalCascade
{
    public function __construct(
        private readonly PrincipalService $principalService,
        private readonly bool $groupCascadeEnabled = false,
    ) {}

    /**
     * Resolve the principal ids the cascade should consult, in the order
     * `group[0..N], then user-principal` so the user-principal wins on
     * conflict (last write wins). Returns an empty list when neither a
     * `PrincipalContext` nor a `?int $userId` is supplied.
     *
     * When `$groupCascadeEnabled` is false (default) only the
     * user-principal is returned — the legacy shape, kept for
     * speech-storage compatibility.
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

        $principalService = $this->principalService;
        $userPrincipalId = (int) $principalService->ensureUserPrincipal($userId)->id;

        if (!$this->groupCascadeEnabled) {
            return [[$userPrincipalId], $userPrincipalId];
        }

        $allIds = $principalService->principalIdsForUser($userId);

        $groupIds = array_values(array_filter(
            $allIds,
            static fn(int $id): bool => $id !== $userPrincipalId,
        ));

        return [array_merge($groupIds, [$userPrincipalId]), $userPrincipalId];
    }
}
