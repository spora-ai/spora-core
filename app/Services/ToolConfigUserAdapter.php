<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Backwards-compatible user-id-keyed shims over {@see ToolConfigService}.
 *
 * Extracted from ToolConfigService so the main service stays under the
 * SonarCloud 20-method-per-class ceiling (S1448). The shims are pure
 * delegation — they resolve a `userId` to its user-principal and call
 * the principal-keyed equivalent.
 *
 * New callers should depend on the principal-keyed methods on
 * {@see ToolConfigService} directly; these shims exist so legacy
 * controller and test code keeps working.
 */
final class ToolConfigUserAdapter
{
    public function __construct(
        private readonly ToolConfigServiceInterface $service,
        private readonly PrincipalService $principalService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getUserSettings(string $toolClass, int $userId): array
    {
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
        return $this->service->getPrincipalSettings($toolClass, $principalId);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function putUserSettings(string $toolClass, int $userId, array $settings): array
    {
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
        return $this->service->putPrincipalSettings($toolClass, $principalId, $settings);
    }

    public function deleteUserSettings(string $toolClass, int $userId): void
    {
        $principalId = $this->principalService->ensureUserPrincipal($userId)->id;
        $this->service->deletePrincipalSettings($toolClass, $principalId);
    }
}
