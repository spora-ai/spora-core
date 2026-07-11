<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Models\Task;
use Spora\Services\AssetStorageException;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves files referenced by the {@see MediaArchiveService}.
 *
 * Route: `GET /api/v1/assets/{filename}` — registered WITH
 * {@see Middleware\AuthMiddleware} in
 * {@see \Spora\Core\RouteDefinitions}. The URL form is
 * `/api/v1/assets/<uuid>`, where the UUID is the row's primary key.
 * Lookups go through {@see MediaArchiveService::find()}, so the response
 * is whatever the row's `storage_mode` says — DB BLOB, filesystem file,
 * or 404 for `external` rows that never had bytes.
 *
 * Authorization
 * -------------
 * The URL itself is no longer the authorization token: the URL is
 * `/api/v1/assets/<uuid>` where `<uuid>` is the row's primary key, so
 * anyone who can see the URL can fetch the bytes. Instead, the
 * controller checks ownership against the requester's session:
 *
 *  1. The requester must own the row's task — the asset was ingested
 *     for a task whose `user_id` matches the requester's `currentUserId()`.
 *  2. Admins (per {@see AuthService::isAdmin()}) can fetch any asset
 *     for support / moderation purposes.
 *
 * Non-owners get a 404 (not 403) to avoid leaking the existence of
 * the UUID — same pattern as the standard not-found envelope.
 *
 * Pre-refactor rows whose URL was returned to the LLM in the legacy
 * `<token>.<ext>` HMAC form resolve via {@see LocalAssetStore::resolve()}
 * as a backwards-compat path; those legacy rows are treated as
 * pre-existing public assets and skip the ownership check.
 */
final class AssetController
{
    /**
     * 24h browser cache: asset URLs are stable per the daily HMAC rotation
     * (legacy mode) or per row UUID (opaque mode), and the underlying
     * bytes don't change post-ingest. Same constant used in three
     * response paths so Sonar's S1192 doesn't flag the duplication.
     */
    private const CACHE_HEADER = 'private, max-age=86400';

    public function __construct(
        private readonly MediaArchiveService $archive,
        private readonly DatabaseAssetStore $database,
        private readonly LocalAssetStore $local,
        private readonly AuthService $auth,
    ) {}

    public function show(string $filename): Response
    {
        // UUID lookup first (new opaque-URL scheme).
        $asset = $this->archive->find($filename);
        if ($asset !== null) {
            // Ownership check — return 404 to non-owners so we don't leak
            // which UUIDs exist in the archive.
            if (!$this->canAccessAsset($asset)) {
                return $this->notFound();
            }
            return $this->streamAsset($asset);
        }

        // Fallback: legacy HMAC-token URLs from pre-refactor rows.
        // Those were minted under the "URL is the token" model and
        // predate the ownership model — they serve as before.
        $resolved = $this->local->resolve($filename);
        if ($resolved !== null) {
            $response = new BinaryFileResponse($resolved['path'], 200, ['Content-Type' => $resolved['mime']]);
            $response->headers->set('Cache-Control', self::CACHE_HEADER);
            return $response;
        }

        return $this->notFound();
    }

    /**
     * Return true if the current requester is allowed to read this row's
     * bytes. Admins bypass the check; everyone else must own the task
     * that produced the asset.
     *
     * Rows without a `task_id` (manually-ingested assets, or pre-refactor
     * orphans) are inaccessible to non-admins — denying by default is
     * safer than allowing accidentally-public rows.
     */
    private function canAccessAsset(MediaAsset $asset): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }

        if ($asset->task_id === null) {
            return false;
        }

        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return false;
        }

        $task = (new Task())->find($asset->task_id);
        return $task !== null && (int) $task->user_id === $userId;
    }

    /**
     * Resolve a {@see MediaAsset} row to a streamed HTTP response. Dispatches
     * on `storage_mode`: DB rows stream their BLOB via {@see StreamedResponse},
     * local rows hand off to {@see BinaryFileResponse} which streams from disk
     * via `sendfile`.
     */
    private function streamAsset(MediaAsset $asset): Response
    {
        try {
            $payload = match ($asset->storage_mode) {
                'data_url' => $this->database->read($asset),
                'local'    => $this->local->readFromAsset($asset),
                'external' => throw new AssetStorageException("External assets have no Spora-side payload"),
                default    => throw new AssetStorageException("Unsupported storage_mode: {$asset->storage_mode}"),
            };
        } catch (AssetStorageException) {
            return $this->notFound();
        }

        if (array_key_exists('bytes', $payload)) {
            // DB mode: emit bytes straight to the response. Using
            // StreamedResponse avoids loading the payload into PHP's
            // memory twice (once via read(), once via echo).
            $bytes = (string) $payload['bytes'];
            $mime  = (string) $payload['mime'];
            return new StreamedResponse(static function () use ($bytes): void {
                echo $bytes;
            }, 200, [
                'Content-Type'   => $mime,
                'Content-Length' => (string) strlen($bytes),
                'Cache-Control'  => self::CACHE_HEADER,
            ]);
        }

        // Local mode: hand off to BinaryFileResponse which streams from
        // disk via sendfile/sendfile64.
        $path = (string) $payload['path'];
        $mime = (string) $payload['mime'];
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $mime]);
        $response->headers->set('Cache-Control', self::CACHE_HEADER);
        return $response;
    }

    /**
     * Standard JSON not-found envelope. Used for both unknown UUIDs
     * (404 is real) and ownership denials (404 hides the UUID's
     * existence).
     */
    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'asset_not_found', 'message' => 'Asset not found.']],
            404,
        );
    }
}
