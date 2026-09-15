<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\SpeechProviderConfiguration;

/**
 * Write-side companion to {@see SpeechProviderConfigServiceInterface}.
 *
 * The mutator owns every mutating call on a {@see SpeechProviderConfiguration}
 * row (create / update / delete / set-default / resolve-group-principal).
 * {@see SpeechProviderConfigMutator} is the only class allowed to invoke
 * these on the persistence + preferences collaborators; the read-side
 * service stays purely query-shaped so the umbrella stays under the
 * SonarCloud S1448 20-method ceiling.
 */
interface SpeechProviderConfigMutatorInterface
{
    public function createConfiguration(int $userId, array $data, bool $isAdmin): SpeechProviderConfiguration;

    /**
     * Resolve a `groups.id` (from the SPA's wire shape) to the matching
     * `principals.id` so the controller can target a group-scope write.
     * Returns `null` when the caller is not authorised to manage the group.
     */
    public function resolveGroupPrincipal(int $groupId, int $callerUserId, bool $isAdmin): ?int;

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): SpeechProviderConfiguration;

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool;

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): SpeechProviderConfiguration;
}
