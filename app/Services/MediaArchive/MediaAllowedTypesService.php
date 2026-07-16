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
 *  3. Image MIME types — `image/*` is **additionally** allowed when
 *     the requesting user's agent's LLM reports
 *     `LLMDriverInterface::supportsImageInput() === true`. Images are
 *     not converted; they flow as image blocks via the multimodal
 *     driver layer.
 *
 * The result drives the upload UI's `<input type="file" accept>`
 * attribute (the frontend fetches `/api/v1/media/allowed-types` to
 * populate it) and the server-side allowlist check in
 * {@see MediaUploadController}.
 */
final class MediaAllowedTypesService
{
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

    /** Image types — allowed only when the agent's LLM supports them. */
    public const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];

    public function __construct(
        private readonly MediaConverterRegistry $converters,
        private readonly DriverFactory $driverFactory,
    ) {}

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
        if ($agentId !== null && $this->agentSupportsImages($agentId)) {
            foreach (self::IMAGE_MIME_TYPES as $mime) {
                $set[strtolower($mime)] = true;
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
}
