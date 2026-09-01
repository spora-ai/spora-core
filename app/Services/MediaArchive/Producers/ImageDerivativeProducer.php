<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Producers;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;
use Spora\Core\Paths;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\Exceptions\ImageDerivativeProducerException;
use Spora\Services\MediaArchive\ImageDerivativeFormat;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Throwable;

/**
 * In-core image derivative producer.
 *
 * Registers as `pluginSlug='spora-core'` (the convention for producers
 * that ship with the framework, not a separate plugin slug) and serves
 * the two operations the operator-facing "Convert to" menu exposes:
 *
 *   - Resize presets (`thumbnail-256`, `medium-1024`) — `scaleDown` on
 *     the long edge, WebP-encoded.
 *   - Format-conversion presets (`format-png`, `format-jpeg`,
 *     `format-webp`) — re-encode at the target MIME, no resize.
 *
 * Source bytes are read straight from the {@see MediaAsset} row: the
 * data-url branch reads the column directly, the local branch reads
 * the on-disk file by deriving the path from `$asset->asset_token` and
 * the {@see Paths::storage()} root. The external branch (referenced
 * URL, no bytes on hand) isn't supported by design — "Convert to" only
 * operates on materialised assets.
 *
 * The producer has a no-arg constructor because
 * {@see \Spora\Services\MediaArchive\MediaDerivativeService} and
 * {@see \Spora\Http\MediaDerivativeController} instantiate producers
 * with `new $class()` — only tests get a chance to inject
 * collaborators. `Paths` is therefore rehydrated inside `produce()`
 * via the `BASE_PATH` constant the consumer defines at boot
 * (matches the convention used by `bin/spora` and `Kernel::boot()`).
 *
 * EXIF orientation is applied by the Intervention driver during decode
 * (`autoOrientation = true` is the default), so a portrait iPhone
 * upload lands the right way up regardless of the resize/convert
 * preset chosen. Metadata (including EXIF tags and colour profiles)
 * is stripped on encode via the dedicated `strip = true` driver
 * config so the operator's derivative doesn't leak GPS coordinates
 * the way the upload sometimes does.
 */
final class ImageDerivativeProducer implements MediaDerivativeProducerInterface
{
    /** Pillow: lowercase MIMEs, no trailing parameters. */
    private const SUPPORTED_SOURCE_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

    /**
     * Mime → file extension map. Mirrors the small lookup
     * {@see \Spora\Services\LocalAssetStore::pickExtension()} keeps
     * private; duplicating it here keeps the producer a leaf with no
     * injected dependencies.
     *
     * @var array<string, string>
     */
    private const MIME_TO_EXT = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function pluginSlug(): string
    {
        return 'spora-core';
    }

    public function operationName(): string
    {
        return 'image.derive';
    }

    public function supportedSourceFormats(): array
    {
        return self::SUPPORTED_SOURCE_MIMES;
    }

    public function supportedDerivativeFormats(): array
    {
        return ImageDerivativeFormat::formatKeys();
    }

    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        $preset = ImageDerivativeFormat::for($format);
        if ($preset === null) {
            throw new InvalidArgumentException(sprintf(
                'ImageDerivativeProducer: unknown format "%s"',
                $format,
            ));
        }

        $sourceMime = strtolower((string) $source->mime_type);
        if (!in_array($sourceMime, self::SUPPORTED_SOURCE_MIMES, true)) {
            throw new InvalidArgumentException(sprintf(
                'ImageDerivativeProducer: source MIME "%s" is not supported',
                $sourceMime,
            ));
        }

        $bytes = $this->loadSourceBytes($source);

        try {
            $manager = new ImageManager($this->resolveDriver());
            $image = $manager->read($bytes);
        } catch (Throwable $e) {
            throw new ImageDerivativeProducerException(sprintf(
                'ImageDerivativeProducer: failed to decode source image: %s',
                $e->getMessage(),
            ), 0, $e);
        }

        if ($preset['kind'] === ImageDerivativeFormat::KIND_RESIZE) {
            // scaleDown never upscales; small sources keep their native
            // dimensions. Aspect ratio preserved by passing only one edge.
            $image->scaleDown($preset['longEdge']);
        }
        // KIND_CONVERT: no transform — the encode call below flips the
        // output MIME regardless of the source.

        $encoded = $this->encodeTo($image, $preset['targetMime'], $preset['quality']);
        $width = $image->width();
        $height = $image->height();

        return new DerivativeOutput(
            bytes: (string) $encoded,
            mime: $preset['targetMime'],
            width: $width,
            height: $height,
            durationSeconds: null,
        );
    }

    /**
     * Pick the GD or Imagick driver based on what's loaded. Imagick
     * wins when present — better quality resampling and animated
     * WebP/GIF support — and the producer degrades gracefully to GD
     * on barebones installs. `strip = true` drops EXIF and other
     * metadata from the encoded output so the derivative doesn't
     * leak coordinates the way the upload sometimes does.
     */
    private function resolveDriver(): DriverInterface
    {
        if (extension_loaded('imagick')) {
            return new \Intervention\Image\Drivers\Imagick\Driver();
        }
        return new \Intervention\Image\Drivers\Gd\Driver();
    }

    /**
     * Drive the encode path through the typed helper methods so the
     * quality knob flows through to whichever encoder the Intervention
     * driver picks for the target MIME. PNG ignores the quality arg
     * (lossless) but the call still routes through `toPng()` for
     * symmetry with the other presets.
     */
    private function encodeTo(ImageInterface $image, string $targetMime, ?int $quality): EncodedImageInterface
    {
        $args = $quality !== null ? [$quality] : [];
        return match ($targetMime) {
            'image/png'  => $image->toPng(...$args),
            'image/jpeg' => $image->toJpeg(...$args),
            'image/webp' => $image->toWebp(...$args),
            default      => throw new InvalidArgumentException(sprintf(
                'ImageDerivativeProducer: unsupported target MIME "%s"',
                $targetMime,
            )),
        };
    }

    private function loadSourceBytes(MediaAsset $asset): string
    {
        return match ($asset->storage_mode) {
            'data_url' => $this->readDataUrlBytes($asset),
            'local'    => $this->readLocalBytes($asset),
            default    => throw new ImageDerivativeProducerException(sprintf(
                'ImageDerivativeProducer: storage_mode "%s" has no materialised bytes',
                (string) $asset->storage_mode,
            )),
        };
    }

    private function readDataUrlBytes(MediaAsset $asset): string
    {
        $payload = $asset->payload;
        if (!is_string($payload) || $payload === '') {
            throw new ImageDerivativeProducerException(sprintf(
                'ImageDerivativeProducer: MediaAsset %s has empty data_url payload',
                $asset->id,
            ));
        }
        return $payload;
    }

    private function readLocalBytes(MediaAsset $asset): string
    {
        $token = $asset->asset_token;
        if (!is_string($token) || $token === '') {
            throw new ImageDerivativeProducerException(sprintf(
                'ImageDerivativeProducer: MediaAsset %s has no asset_token',
                $asset->id,
            ));
        }
        $ext = self::MIME_TO_EXT[strtolower((string) $asset->mime_type)] ?? null;
        if ($ext === null) {
            throw new ImageDerivativeProducerException(sprintf(
                'ImageDerivativeProducer: cannot derive local-file extension for MIME "%s"',
                (string) $asset->mime_type,
            ));
        }
        $path = $this->paths()->storage('assets') . '/' . $token . '.' . $ext;
        // PHP 8.4+ no longer fully honours the `@` error-suppression
        // operator for `file_get_contents`; use the explicit handler
        // pattern (same as MetadataExtractor::readImageInfo) so a
        // missing file becomes a clean `null` instead of a runtime
        // warning that the test suite flags as risky.
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $bytes = file_get_contents($path);
        } finally {
            restore_error_handler();
        }
        if (!is_string($bytes)) {
            throw new ImageDerivativeProducerException(sprintf(
                'ImageDerivativeProducer: MediaAsset %s local file unreadable: %s',
                $asset->id,
                $path,
            ));
        }
        return $bytes;
    }

    /**
     * Build a {@see Paths} rooted at the consumer's `BASE_PATH`.
     * Consumers (sponsoring `bin/spora`, the HTTP front controller)
     * define `BASE_PATH` at boot; tests that exercise this producer
     * set `SPORA_STORAGE_DIR` directly to redirect the storage
     * root without touching `BASE_PATH`.
     */
    private function paths(): Paths
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        return new Paths($basePath);
    }
}
