<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Models\MediaAsset;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a media asset's bytes.
 *
 * Extracted from {@see \Spora\Http\AssetController} so the admin asset route
 * and the Agent API's media route share one implementation of the
 * `storage_mode` branching. That branching is wire behaviour, and two copies
 * of it would eventually disagree about which storage modes exist.
 */
final class AssetStreamer
{
    /** 24h browser cache: asset bytes don't change post-ingest. */
    public const CACHE_HEADER = 'private, max-age=86400';

    public function __construct(
        private readonly DatabaseAssetStore $database,
        private readonly LocalAssetStore $local,
    ) {}

    /**
     * @param string $disposition `inline` renders in a browser or an LLM's
     *   viewer; `attachment` forces a download. The admin route downloads,
     *   the Agent API route renders.
     */
    public function stream(MediaAsset $asset, string $disposition = 'attachment'): ?Response
    {
        try {
            $payload = match ($asset->storage_mode) {
                'data_url' => $this->database->read($asset),
                'local' => $this->local->readFromAsset($asset),
                'external' => throw new AssetStorageException('External assets have no Spora-side payload'),
                default => throw new AssetStorageException("Unsupported storage_mode: {$asset->storage_mode}"),
            };
        } catch (AssetStorageException) {
            return null;
        }

        if (array_key_exists('bytes', $payload)) {
            // StreamedResponse avoids loading the BLOB into PHP memory twice
            // (once via read(), once via echo).
            $bytes = (string) $payload['bytes'];
            $mime = (string) $payload['mime'];
            $response = new StreamedResponse(static function () use ($bytes): void {
                echo $bytes;
            }, 200, [
                'Content-Type' => $mime,
                'Content-Length' => (string) strlen($bytes),
                'Cache-Control' => self::CACHE_HEADER,
            ]);
            $this->applyContentDisposition($response, $asset, $disposition);

            return $response;
        }

        $path = (string) $payload['path'];
        $mime = (string) $payload['mime'];
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $mime]);
        $response->headers->set('Cache-Control', self::CACHE_HEADER);
        $this->applyContentDisposition($response, $asset, $disposition);

        return $response;
    }

    /**
     * `ingest()` stores the filename as handed, so this is the only place a
     * CR/LF cannot reach the header. Falls back to the opaque UUID plus
     * extension so the client still gets a sensible default name.
     */
    private function applyContentDisposition(Response $response, MediaAsset $asset, string $disposition): void
    {
        $name = $asset->filename !== null && $asset->filename !== ''
            ? $asset->filename
            : $asset->publicUrl();

        // PHP's `header()` would refuse a value containing a newline — a
        // SAPI backstop, not a fix; the download just loses its name.
        $name = basename(preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '');

        $response->headers->set(
            'Content-Disposition',
            sprintf('%s; filename="%s"', $disposition, $name === '' ? 'download' : addcslashes($name, '"\\')),
        );
        // `nosniff` because this route can serve `inline`: without it the
        // browser may re-type the bytes, and an inline response is rendered
        // in the Spora origin.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
    }
}
