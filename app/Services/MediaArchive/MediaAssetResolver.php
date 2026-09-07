<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Models\MediaAsset;
use Spora\Services\PrincipalResolver;

/**
 * Batch resolver for `MediaArchiveService::resolveMany()` + the
 * principal-aware visibility union it enforces.
 *
 * Lives in its own class so {@see MediaArchiveService} stays under
 * Sonar's per-class method-count cap. The contract is the same one
 * {@see \Spora\Http\AssetController::ownsAsset()} enforces for the
 * byte-serving read path — a chat-rendered `asset_url` always
 * resolves when the row is here.
 */
final class MediaAssetResolver
{
    public function __construct(
        private readonly PrincipalResolver $principalResolver,
    ) {}

    /**
     * Resolve a list of Media Archive UUIDs to their full rows, in input order,
     * silently dropping any IDs the caller cannot access. Existence-hiding —
     * a foreign id surfaces as a missing slot in the response, never as 404
     * or 403, so the chat list cannot probe for archive rows it does not own.
     *
     * The cap (64 IDs) is enforced by the controller; this method itself
     * does not enforce the cap so it stays composable for in-process callers.
     *
     * @param  list<string> $ids
     * @return list<MediaAsset>
     */
    public function resolveMany(array $ids, int $userId, bool $isAdmin): array
    {
        if ($ids === []) {
            return [];
        }
        $byId = $this->loadAssetsById($ids);
        // Pre-compute the caller's visible-principal set once so the
        // post-0075 fast path (asset.principal_id is in the set) stays an
        // O(1) in-memory check per asset instead of issuing a per-asset
        // `visiblePrincipalIds()` + `whereIn` round-trip via
        // {@see PrincipalResolver::isVisibleTo()}.
        $visiblePrincipalIds = $this->principalResolver->visiblePrincipalIds($userId);
        $principalIdSet = array_flip($visiblePrincipalIds);
        return $this->filterByVisibility($ids, $byId, $userId, $isAdmin, $principalIdSet);
    }

    /**
     * @param  list<string> $ids
     * @return array<string, MediaAsset>
     */
    private function loadAssetsById(array $ids): array
    {
        $assets = MediaAsset::query()->whereIn('id', $ids)->get();
        $byId = [];
        foreach ($assets as $asset) {
            $byId[$asset->id] = $asset;
        }
        return $byId;
    }

    /**
     * Walk the requested id list in order, drop missing rows (existence-
     * hiding) and rows the caller cannot access (visibility union). Returns
     * the input-order list of accessible `MediaAsset` rows.
     *
     * @param  list<string> $ids
     * @param  array<string, MediaAsset> $byId
     * @param  array<int, int> $principalIdSet Caller's visible principal ids,
     *         flipped for O(1) `isset` checks; built once in {@see resolveMany()}.
     * @return list<MediaAsset>
     */
    private function filterByVisibility(
        array $ids,
        array $byId,
        int $userId,
        bool $isAdmin,
        array $principalIdSet,
    ): array {
        $resolved = [];
        foreach ($ids as $id) {
            $asset = $byId[$id] ?? null;
            if ($asset === null) {
                continue;
            }
            if ($this->canResolveAsset($asset, $userId, $isAdmin, $principalIdSet)) {
                $resolved[] = $asset;
            }
        }
        return $resolved;
    }

    /**
     * Visibility check for {@see resolveMany()}. Returns `true` for
     * admins, callers who uploaded the asset directly, callers whose
     * precomputed visible-principal set covers the asset's `principal_id`
     * (the post-0075 fast path), or — as a last-resort fallback — the
     * per-asset `PrincipalResolver::isVisibleTo()` check that resolves
     * legacy rows where `principal_id` was left NULL but `agent_id`
     * points at an agent whose principal is in the set.
     */
    private function canResolveAsset(
        MediaAsset $asset,
        int $userId,
        bool $isAdmin,
        array $principalIdSet,
    ): bool {
        if ($this->isAdminBypassed($isAdmin)) {
            return true;
        }
        if ($this->callerOwnsAsset($asset, $userId)) {
            return true;
        }
        if ($asset->principal_id !== null && isset($principalIdSet[(int) $asset->principal_id])) {
            return true;
        }
        return $this->agentIsVisibleToCaller($asset, $userId);
    }

    private function isAdminBypassed(bool $isAdmin): bool
    {
        return $isAdmin;
    }

    private function callerOwnsAsset(MediaAsset $asset, int $userId): bool
    {
        return $asset->user_id !== null && (int) $asset->user_id === $userId;
    }

    private function agentIsVisibleToCaller(MediaAsset $asset, int $userId): bool
    {
        return $asset->agent_id !== null
            && $this->principalResolver->isVisibleTo((int) $asset->agent_id, $userId);
    }
}
