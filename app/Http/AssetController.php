<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Models\MediaAsset;
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
 * Route: `GET /api/v1/assets/{filename}` — registered without middleware
 * in {@see \Spora\Core\RouteDefinitions}. Authorization is the URL itself:
 * the URL form is `/api/v1/assets/<uuid>`, where the UUID is the row's
 * primary key. Lookups go through {@see MediaArchiveService::find()}, so
 * the response is whatever the row's `storage_mode` says — DB BLOB,
 * filesystem file, or 404 for `external` rows that never had bytes.
 *
 * Pre-refactor rows whose URL was returned to the LLM in the legacy
 * `<token>.<ext>` HMAC form still resolve via {@see LocalAssetStore::resolve()}
 * as a backwards-compat path; new rows go through the UUID lookup.
 *
 * Same pattern as S3 presigned URLs: knowledge of the URL = access.
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
    ) {}

    public function show(string $filename): Response
    {
        // UUID lookup first (new opaque-URL scheme).
        $asset = $this->archive->find($filename);
        if ($asset !== null) {
            return $this->streamAsset($asset);
        }

        // Fallback: legacy HMAC-token URLs from pre-refactor rows.
        $resolved = $this->local->resolve($filename);
        if ($resolved !== null) {
            $response = new BinaryFileResponse($resolved['path'], 200, ['Content-Type' => $resolved['mime']]);
            $response->headers->set('Cache-Control', self::CACHE_HEADER);
            return $response;
        }

        // Match the standard JSON error envelope used by every other
        // controller (see JsonControllerHelpers::notFound()). Asset
        // routes don't use the trait because they need to return
        // BinaryFileResponse on success, but the error shape stays
        // consistent so client error handling is uniform.
        return new JsonResponse(
            ['error' => ['code' => 'asset_not_found', 'message' => 'Asset not found.']],
            404,
        );
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
            return new JsonResponse(
                ['error' => ['code' => 'asset_not_found', 'message' => 'Asset payload unavailable.']],
                404,
            );
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
}
