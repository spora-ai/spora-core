<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Throwable;

/**
 * Computes the dynamic set of MIME types accepted by the upload UI.
 *
 * Three sources, combined:
 *
 *  1. Static text allowlist — file types an LLM can read directly
 *     (TXT, MD, CSV, JSON, HTML, XML, YAML). Always allowed; the
 *     bytes are passed through via {@see PlainTextPassthroughConverter}.
 *
 *  2. Converter-supplied MIME types — every {@see MediaConverterInterface}
 *     registered with {@see MediaConverterRegistry}. The PDF converter
 *     ships in core; plugins (e.g. a Word-DOCX plugin) extend this
 *     list automatically.
 *
 *  3. Configurable image MIME types — `image/*` is **additionally** allowed
 *     when the requesting user's agent's LLM reports
 *     `LLMDriverInterface::supportsImageInput() === true`. The allowed
 *     extensions are resolved by the container from
 *     `config['media_archive']['allowed_image_types']` (default
 *     `['png', 'jpeg', 'webp']`). Operators can extend the list via
 *     config.php or `SPORA_MEDIA_ARCHIVE_ALLOWED_IMAGE_TYPES` env var.
 *     An empty list explicitly disables image uploads.
 *
 * The result drives the upload UI's `<input type="file" accept>`
 * attribute (the frontend fetches `/api/v1/media/allowed-types` to
 * populate it) and the server-side allowlist check in
 * {@see MediaUploadController}.
 */
final class MediaAllowedTypesService
{
    /**
     * Built-in image-type default when no configuration is supplied. The
     * actual value used at runtime comes from `MediaArchiveConfig::imageExtensions()`,
     * which is wired by the container. Kept here as a public constant so
     * tests and the config layer have one canonical source.
     */
    public const DEFAULT_IMAGE_EXTENSIONS = ['png', 'jpeg', 'webp'];

    /**
     * Static text allowlist. The bytes are stored verbatim in
     * `markdown_content` via {@see PlainTextPassthroughConverter}.
     * Plugins can override this with their own text converter by
     * adding it BEFORE PlainTextPassthroughConverter in the discovery
     * list.
     */
    public const TEXT_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'text/html',
        'application/json',
        'application/xml',
        'text/xml',
        'application/yaml',
        'text/yaml',
    ];

    /**
     * @param list<string>|null $imageExtensions Resolved image extensions
     *        (e.g. `['png', 'jpeg', 'webp']`). null falls back to the
     *        built-in default. An empty array disables images entirely.
     *        Strings are normalized through {@see normalizeImageExtensions()}.
     */
    public function __construct(
        private readonly MediaConverterRegistry $converters,
        private readonly DriverFactory $driverFactory,
        ?array $imageExtensions = null,
    ) {
        $this->imageExtensions = self::normalizeImageExtensions($imageExtensions);
    }

    /** @var list<string> */
    private readonly array $imageExtensions;

    /**
     * @return list<string> Allowed MIME types for the given agent.
     *                     Empty `$agentId` (no agent context) returns the
     *                     text + converter union, but no images.
     */
    public function allowedMimeTypes(?int $agentId = null): array
    {
        $set = [];
        foreach (self::TEXT_MIME_TYPES as $mime) {
            $set[strtolower($mime)] = true;
        }
        foreach ($this->converters->allSupportedMimeTypes() as $mime) {
            $set[strtolower($mime)] = true;
        }
        if ($agentId !== null && $this->agentSupportsImages($agentId) && $this->imageExtensions !== []) {
            foreach ($this->imageExtensions as $ext) {
                $mime = self::imageMimeForExtension($ext);
                if ($mime !== null) {
                    $set[$mime] = true;
                }
            }
        }
        return array_keys($set);
    }

    /**
     * @return list<string> Allowed file extensions (without dot) for the
     *                     given agent. Used to populate the upload UI's
     *                     `accept="…"` attribute.
     */
    public function allowedExtensions(?int $agentId = null): array
    {
        $exts = [];
        foreach ($this->allowedMimeTypes($agentId) as $mime) {
            $ext = MediaArchiveService::extensionForMime($mime);
            if ($ext !== null) {
                $exts[$ext] = true;
            }
        }
        // Add a few extensions whose MIME type doesn't round-trip cleanly.
        foreach (['md', 'json', 'csv'] as $ext) {
            $exts[$ext] = true;
        }
        return array_keys($exts);
    }

    public function isAllowed(string $mime, ?int $agentId = null): bool
    {
        return in_array(strtolower($mime), $this->allowedMimeTypes($agentId), true);
    }

    /**
     * @return list<string> Image extensions configured for this service.
     *                     Empty when the operator disabled image uploads.
     */
    public function imageExtensions(): array
    {
        return $this->imageExtensions;
    }

    private function agentSupportsImages(int $agentId): bool
    {
        $agent = Agent::query()->find($agentId);
        if ($agent === null) {
            return false;
        }
        try {
            $driver = $this->driverFactory->makeFromAgent($agent);
        } catch (Throwable) {
            return false;
        }
        return $driver->supportsImageInput();
    }

    /**
     * Normalize a configured image-extension list.
     *
     * Rules (matching the env parser for symmetry):
     * - lowercased, whitespace-trimmed, leading dots stripped
     * - `jpg` → `jpeg` (canonical MIME `image/jpeg`)
     * - SVG variants (`svg`, `svg+xml`, `image/svg+xml`) are excluded; the
     *   picker only offers raster types. They remain rejected server-side
     *   even if an operator explicitly configures them.
     * - duplicates collapsed to the first occurrence
     * - order preserved
     *
     * @param list<string>|null $input null → built-in default
     * @return list<string>
     */
    public static function normalizeImageExtensions(?array $input): array
    {
        if ($input === null) {
            return self::DEFAULT_IMAGE_EXTENSIONS;
        }
        $out = [];
        $seen = [];
        foreach ($input as $raw) {
            $t = strtolower(trim((string) $raw));
            if ($t === '') {
                continue;
            }
            $t = ltrim($t, '.');
            if ($t === 'svg' || $t === 'svg+xml') {
                continue;
            }
            $alias = $t === 'jpg' ? 'jpeg' : $t;
            if (!isset($seen[$alias])) {
                $seen[$alias] = true;
                $out[] = $alias;
            }
        }
        return $out;
    }

    /**
     * Map a normalized image extension to its canonical `image/*` MIME.
     * Returns null when the extension does not have a recognized image
     * MIME in {@see MediaArchiveService::mimeForExtension()}.
     */
    private static function imageMimeForExtension(string $ext): ?string
    {
        $mime = MediaArchiveService::mimeForExtension($ext);
        if ($mime === null || !str_starts_with(strtolower($mime), 'image/')) {
            return null;
        }
        return strtolower($mime);
    }
}
