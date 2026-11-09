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
        private readonly ?AuthService $auth,
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
        $auth = $this->auth;
        if ($auth === null) {
            return false;
        }

        $userId = $auth->currentUserId();
        if ($userId === null) {
            return false;
        }

        return $auth->isAdmin() || $this->ownsAsset($asset, $userId);
    }

    /**
     * Whether `$userId` may attach `$asset` to one of their tasks.
     *
     * The attach-time union: the caller uploaded it, or the caller can act
     * as the principal the asset was filed under (a group member and the
     * group's media), or the caller can act as the principal of the agent
     * that produced it.
     *
     * The principal arm is what makes a group member able to attach the
     * group's own uploads. The direct-ownership arm alone would not: an
     * asset uploaded by a member keeps that member as its `user_id`, and a
     * sibling member attaching it must be allowed.
     *
     * An asset with no attribution at all matches no arm, so it is not
     * attachable — an unknown owner is not a permission.
     */
    public function canAttachAs(MediaAsset $asset, int $userId): bool
    {
        if ($this->ownsDirectly($asset, $userId)) {
            return true;
        }

        $resolver = $this->resolver ?? new PrincipalResolver();

        $principalId = $asset->principal_id !== null ? (int) $asset->principal_id : null;
        if ($principalId !== null && in_array($principalId, $resolver->visiblePrincipalIds($userId), true)) {
            return true;
        }

        return $asset->agent_id !== null && $resolver->isVisibleTo((int) $asset->agent_id, $userId);
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
