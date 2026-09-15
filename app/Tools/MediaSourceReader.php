<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\MediaAsset;
use Spora\Services\AssetStorageException;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;

/**
 * Reads the raw bytes of a {@see MediaAsset} from whichever storage
 * backend the row points at.
 *
 * Owned by {@see MediaTool} so the tool can layer its scope check
 * and size cap on top without exposing the read seam. Mirrors
 * {@see \Spora\Http\AssetController::streamAsset()}'s dispatch but
 * never throws: a missing local file or a legacy `data_url` row
 * whose `payload` was never backfilled should not surface as an
 * uncaught exception out of a tool the LLM is driving.
 */
final readonly class MediaSourceReader
{
    public function __construct(
        private DatabaseAssetStore $database,
        private LocalAssetStore $local,
    ) {}

    public function read(MediaAsset $asset): ?string
    {
        try {
            return match ($asset->storage_mode) {
                'data_url' => (string) $this->database->read($asset)['bytes'],
                'local'    => $this->readLocalFile($asset),
                default    => null,
            };
        } catch (AssetStorageException) {
            return null;
        }
    }

    /**
     * Text-shaped mimes inline into the LLM context. The list mirrors
     * what the operator-facing converters emit (text, JSON, YAML, XML)
     * plus the wildcards LLM agents routinely ingest (SVG, CSV).
     */
    public static function isTextShapedMime(string $mime): bool
    {
        $mime = strtolower(trim($mime));
        if ($mime === '') {
            return false;
        }
        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        return in_array($mime, [
            'application/json',
            'application/xml',
            'application/yaml',
            'application/x-yaml',
            'application/svg+xml',
            'application/csv',
            'application/x-typst',
        ], true);
    }

    private function readLocalFile(MediaAsset $asset): ?string
    {
        try {
            $payload = $this->local->readFromAsset($asset);
        } catch (AssetStorageException) {
            return null;
        }
        $path = (string) $payload['path'];
        if ($path === '') {
            return null;
        }
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }
}
