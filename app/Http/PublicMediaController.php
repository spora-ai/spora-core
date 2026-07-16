<?php

declare(strict_types=1);

namespace Spora\Http;

use Spora\Core\Paths;
use Spora\Models\MediaAsset;
use Spora\Services\AssetStorageException;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * No-auth, token-gated public endpoint for sharing media files.
 *
 * - GET /api/v1/public/media/{id}?token=<token>
 *
 * The token is the row's `public_access_token` column, set by the
 * user via `PATCH /api/v1/media/{id}` with `public_access_enabled: true`.
 * This controller returns 404 (with no detail) for any mismatch so
 * callers cannot probe UUID existence.
 *
 * Per the user's design choice, the endpoint serves **raw bytes only**
 * — no JSON envelope, no Content-Disposition. The MIME is the row's
 * sniffed mime, the cache header is `private, max-age=86400` so
 * CDNs can cache the response for a day without leaking the file
 * to other callers.
 */
final class PublicMediaController
{
    public function __construct(
        private readonly DatabaseAssetStore $database,
        private readonly LocalAssetStore $local,
    ) {}

    public function show(string $id, Request $request): Response
    {
        $asset = $this->findSharedAsset($id, $request);
        if ($asset === null) {
            return $this->notFound();
        }

        return $this->stream($asset);
    }

    private function findSharedAsset(string $id, Request $request): ?MediaAsset
    {
        $asset = null;
        $token = (string) $request->query->get('token', '');
        if ($this->isUuid($id) && $token !== '') {
            $candidate = MediaAsset::query()->find($id);
            if ($candidate !== null
                && is_string($candidate->public_access_token)
                && hash_equals($candidate->public_access_token, $token)
            ) {
                $asset = $candidate;
            }
        }

        return $asset;
    }

    private function stream(MediaAsset $asset): Response
    {
        try {
            $payload = match ($asset->storage_mode) {
                'data_url' => $this->database->read($asset),
                'local'    => $this->local->readFromAsset($asset),
                default    => throw new AssetStorageException('Public media requires local or data_url storage'),
            };
        } catch (AssetStorageException) {
            return $this->notFound();
        }

        $mime = (string) $payload['mime'];
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        if (array_key_exists('bytes', $payload)) {
            $bytes = (string) $payload['bytes'];
            return new StreamedResponse(static function () use ($bytes): void {
                echo $bytes;
            }, 200, [
                'Content-Type'    => $mime,
                'Content-Length'  => (string) strlen($bytes),
                'Cache-Control'   => 'private, max-age=86400',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }

        $path = (string) $payload['path'];
        // Defense in depth: refuse paths that resolve outside the storage
        // root. BinaryFileResponse would happily stream an arbitrary file
        // if a misbehaving asset store returned one.
        $root = $this->storageRoot();
        $realPath = realpath($path);
        $realRoot = realpath($root);
        if ($realPath === false || $realRoot === false || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            return $this->notFound();
        }

        $response = new BinaryFileResponse($realPath, 200, ['Content-Type' => $mime]);
        $response->headers->set('Cache-Control', 'private, max-age=86400');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }

    private function storageRoot(): string
    {
        $paths = new Paths(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3));
        return $paths->storage('assets');
    }

    private function isUuid(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    private function notFound(): Response
    {
        return new JsonResponse(
            ['error' => ['code' => 'NOT_FOUND', 'message' => 'Media not found.']],
            Response::HTTP_NOT_FOUND,
        );
    }
}
