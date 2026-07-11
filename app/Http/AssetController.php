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
 * Route: `GET /api/v1/assets/{filename}` — registered with
 * {@see Middleware\AuthMiddleware} (see
 * {@see \Spora\Core\RouteDefinitions}). URL form is
 * `/api/v1/assets/<uuid>`, where the UUID is the row's primary key.
 *
 * Authorization is ownership-based, not URL-based: the URL is now the
 * row's primary key, so anyone who sees the URL could otherwise fetch
 * the bytes. The controller checks `task.user_id == currentUserId()`
 * (admins bypass). Non-owners get a 404 to avoid leaking UUID existence.
 *
 * Pre-refactor rows whose URL was returned to the LLM in the legacy
 * `<token>.<ext>` HMAC form skip the ownership check — they were minted
 * under the "URL is the token" model and predate this control.
 */
final class AssetController
{
    /** 24h browser cache: asset bytes don't change post-ingest. */
    private const CACHE_HEADER = 'private, max-age=86400';

    public function __construct(
        private readonly MediaArchiveService $archive,
        private readonly DatabaseAssetStore $database,
        private readonly LocalAssetStore $local,
        private readonly AuthService $auth,
    ) {}

    public function show(string $filename): Response
    {
        // Accept both `/api/v1/assets/<uuid>` and `/api/v1/assets/<uuid>.<ext>`.
        // UUIDs never contain dots, so a trailing `.<ext>` is safe to strip
        // here without affecting the legacy HMAC-token fallback path.
        $uuid = $this->stripExtension($filename);

        $asset = $this->archive->find($uuid);
        if ($asset !== null) {
            return $this->streamOwnedAsset($asset);
        }

        $resolved = $this->local->resolve($filename);
        if ($resolved !== null) {
            $response = new BinaryFileResponse($resolved['path'], 200, ['Content-Type' => $resolved['mime']]);
            $response->headers->set('Cache-Control', self::CACHE_HEADER);
            return $response;
        }

        return $this->notFound();
    }

    /**
     * Return the UUID portion of a public asset filename, stripping any
     * `.ext` suffix the browser appended for filename hints. UUIDs match
     * `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` (36 chars); anything after
     * the 36th char is treated as the extension. Inputs without a `.`
     * are returned unchanged.
     */
    private function stripExtension(string $filename): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(.+)$/i', $filename) === 1) {
            return substr($filename, 0, 36);
        }
        return $filename;
    }

    /**
     * Non-owners get the standard 404 envelope so we don't leak which
     * UUIDs exist in the archive.
     */
    private function streamOwnedAsset(MediaAsset $asset): Response
    {
        if (!$this->canAccessAsset($asset)) {
            return $this->notFound();
        }
        return $this->streamAsset($asset);
    }

    /**
     * Admins bypass; everyone else must own the row's task. Rows
     * without a `task_id` are denied — refusing accidentally-public
     * rows is safer than allowing them.
     */
    private function canAccessAsset(MediaAsset $asset): bool
    {
        $userId = $this->auth->currentUserId();
        $task   = $asset->task_id !== null ? (new Task())->find($asset->task_id) : null;

        return $this->auth->isAdmin()
            || ($userId !== null
                && $task !== null
                && (int) $task->user_id === $userId);
    }

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
            // StreamedResponse avoids loading the BLOB into PHP memory
            // twice (once via read(), once via echo).
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

        $path = (string) $payload['path'];
        $mime = (string) $payload['mime'];
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $mime]);
        $response->headers->set('Cache-Control', self::CACHE_HEADER);
        return $response;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'asset_not_found', 'message' => 'Asset not found.']],
            404,
        );
    }
}
