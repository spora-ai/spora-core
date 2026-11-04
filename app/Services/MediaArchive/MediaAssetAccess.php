<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\PrincipalResolver;

/**
 * Whether a caller may read a media asset's bytes.
 *
 * The ownership union the archive's read paths share: admins bypass, and
 * everyone else must either own the row directly (they uploaded it) or own
 * an agent that produced it. The agent arm goes through
 * {@see PrincipalResolver::isVisibleTo()}, so a plain group member can
 * read their group's agents' media — the same scoping
 * {@see MediaArchiveService::list()} applies, which is what makes the URL
 * the listing endpoint surfaces always resolve.
 *
 * It lives here rather than in a controller because two controllers need
 * it: the asset route and the embed route. A copy of the union in each
 * would be two definitions of "may read this", and the kind that drift.
 */
final class MediaAssetAccess
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ?PrincipalResolver $resolver = null,
    ) {}

    /**
     * Whether the caller may read `$asset`.
     *
     * A caller with no session cannot read anything — not even an admin,
     * because there is no admin without an identity.
     */
    public function canRead(MediaAsset $asset): bool
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return false;
        }

        return $this->auth->isAdmin() || $this->ownsAsset($asset, $userId);
    }

    private function ownsAsset(MediaAsset $asset, int $userId): bool
    {
        return $this->ownsDirectly($asset, $userId) || $this->ownsViaAgent($asset, $userId);
    }

    private function ownsDirectly(MediaAsset $asset, int $userId): bool
    {
        return $asset->user_id !== null && (int) $asset->user_id === $userId;
    }

    /**
     * Resolve the asset's agent and ask the principal resolver whether
     * the caller can act as any of the agent's principals. A
     * direct-uploaded asset (no `agent_id`) falls through to false here;
     * the direct-ownership arm covers it.
     */
    private function ownsViaAgent(MediaAsset $asset, int $userId): bool
    {
        if ($asset->agent_id === null) {
            return false;
        }

        $resolver = $this->resolver ?? new PrincipalResolver();

        return $resolver->isVisibleTo((int) $asset->agent_id, $userId);
    }
}
