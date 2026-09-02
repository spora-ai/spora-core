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
        return $this->filterByVisibility($ids, $byId, $userId, $isAdmin);
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
     * @return list<MediaAsset>
     */
    private function filterByVisibility(array $ids, array $byId, int $userId, bool $isAdmin): array
    {
        $resolved = [];
        foreach ($ids as $id) {
            $asset = $byId[$id] ?? null;
            if ($asset === null) {
                continue;
            }
            if ($this->canResolveAsset($asset, $userId, $isAdmin)) {
                $resolved[] = $asset;
            }
        }
        return $resolved;
    }

    /**
     * Visibility check for {@see resolveMany()}. Returns `true` for
     * admins, callers who uploaded the asset directly, or callers whose
     * visible-principal set covers the asset's owning agent.
     */
    private function canResolveAsset(MediaAsset $asset, int $userId, bool $isAdmin): bool
    {
        if ($this->isAdminBypassed($isAdmin)) {
            return true;
        }
        return $this->callerOwnsAsset($asset, $userId)
            || $this->agentIsVisibleToCaller($asset, $userId);
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
