<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Models\Agent;
use Spora\Models\MediaAsset;
use Spora\Services\AssetStorageException;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;

/**
 * Resolve a Media Archive UUID to its bytes for in-process consumers
 * (e.g. a plugin that needs to forward the bytes to a downstream API
 * without going through the HTTP layer).
 *
 * Mirrors the ownership union that {@see \Spora\Http\AssetController}
 * enforces for the HTTP read path: the caller must own the asset
 * directly (`user_id`) or own the agent that produced it (`agent.user_id`).
 * The HTTP controller additionally bypasses the check for admins; this
 * service has no auth context, so an admin caller passes `userId = null`
 * — same as the system-context contract used by `MediaArchiveService::list()`.
 *
 * Lives outside `MediaArchiveService` so that class stays under the
 * Sonar 20-method threshold (the class docstring explicitly carves out
 * the URL branch to {@see MediaArchiveUrlResolver} for the same reason).
 *
 * Returned shapes:
 *  - `['status' => 'data_url', 'bytes' => string, 'mime' => string]`
 *  - `['status' => 'local',    'bytes' => string, 'mime' => string]`
 *  - `['status' => 'external', 'sourceUrl' => string]`
 *  - `null` — asset not found, caller not authorised, or the storage_mode
 *    is not one of the three known values (legacy rows).
 *
 * The caller is expected to translate `null` into a user-facing message
 * (e.g. "Media asset <uuid> not found in the Spora Media Archive.").
 */
final class MediaAssetReader
{
    public function __construct(
        private readonly DatabaseAssetStore $database,
        private readonly LocalAssetStore $local,
    ) {}

    /**
     * @return array{status: 'data_url', bytes: string, mime: string}
     *         | array{status: 'local', bytes: string, mime: string}
     *         | array{status: 'external', sourceUrl: string}
     *         | null
     */
    public function readAsset(string $id, ?int $userId): ?array
    {
        $asset = MediaAsset::query()->find($id);
        if ($asset === null) {
            return null;
        }
        if ($userId !== null && !$this->isAccessibleTo($asset, $userId)) {
            return null;
        }
        return $this->readPayload($asset);
    }

    /**
     * Ownership union: `user_id == $userId` OR `agent_id` belongs to an
     * agent owned by `$userId`. Same predicate as
     * {@see \Spora\Http\AssetController::ownsAsset()} so an asset the
     * listing endpoint surfaces is reachable here too.
     */
    private function isAccessibleTo(MediaAsset $asset, int $userId): bool
    {
        if ($asset->user_id !== null && (int) $asset->user_id === $userId) {
            return true;
        }
        if ($asset->agent_id === null) {
            return false;
        }
        $agent = Agent::query()->find($asset->agent_id);
        return $agent !== null && (int) $agent->user_id === $userId;
    }

    /**
     * @return array{status: 'data_url', bytes: string, mime: string}
     *         | array{status: 'local', bytes: string, mime: string}
     *         | array{status: 'external', sourceUrl: string}
     *         | null
     */
    private function readPayload(MediaAsset $asset): ?array
    {
        return match ($asset->storage_mode) {
            'data_url' => $this->readDataUrlPayload($asset),
            'local'    => $this->readLocalPayload($asset),
            'external' => $this->externalSourceUrl($asset),
            default    => null,
        };
    }

    /**
     * @return array{status: 'data_url', bytes: string, mime: string}|null
     */
    private function readDataUrlPayload(MediaAsset $asset): ?array
    {
        try {
            $payload = $this->database->read($asset);
        } catch (AssetStorageException) {
            // Row says data_url but payload is missing — treat as
            // not-readable rather than crashing the caller.
            return null;
        }
        return [
            'status' => 'data_url',
            'bytes'  => (string) $payload['bytes'],
            'mime'   => (string) $payload['mime'],
        ];
    }

    /**
     * @return array{status: 'local', bytes: string, mime: string}|null
     */
    private function readLocalPayload(MediaAsset $asset): ?array
    {
        try {
            $payload = $this->local->readFromAsset($asset);
        } catch (AssetStorageException) {
            // Missing asset_token or file gone — same not-readable
            // behaviour as the data_url branch.
            return null;
        }
        $path = (string) $payload['path'];
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        return [
            'status' => 'local',
            'bytes'  => $bytes,
            'mime'   => (string) $payload['mime'],
        ];
    }

    /**
     * @return array{status: 'external', sourceUrl: string}|null
     */
    private function externalSourceUrl(MediaAsset $asset): ?array
    {
        $url = $asset->source_url;
        if (!is_string($url) || $url === '') {
            return null;
        }
        return ['status' => 'external', 'sourceUrl' => $url];
    }
}
