<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Throwable;

/**
 * Computes the dynamic set of MIME types accepted by the upload UI.
 *
 * Four sources, combined:
 *
 *  1. Static text allowlist — file types an LLM can read directly
 *     (TXT, MD, CSV, JSON, HTML, XML, YAML). Always allowed, and
 *     independent of source 3: a text source is its own text, so it
 *     needs no derivative — {@see \Spora\Agents\AttachmentRowRenderer}
 *     inlines the raw bytes whenever they fit the budget.
 *
 *  2. Static audio allowlist — the recording pipeline's input surface
 *     (see {@see \Spora\Speech\OpenAiCompatibleTranscriber}). Always
 *     allowed: audio bytes have no XSS or prompt-injection attack
 *     surface at the byte level and storage size is the only concern
 *     (same risk class as any other attachment). The list deliberately
 *     includes `video/webm` because {@see https://w3c.github.io/mediacapture-record/}
 *     MediaRecorder reports audio-only WebM recordings as `video/webm`
 *     — there is no byte-level way for the sniffer to distinguish an
 *     audio-only WebM track from a WebM that also carries a video
 *     track, so the container is `video/webm` for both. We accept the
 *     container and let the downstream STT provider handle the
 *     audio-only-WebM case (see
 *     {@see \Spora\Speech\OpenAiCompatibleTranscriber::extensionFor()}).
 *
 *  3. Producer-supplied MIME types — every registered
 *     {@see MediaDerivativeProducerInterface}'s
 *     `supportedSourceFormats()`. This union is what keeps a binary
 *     document uploadable *because* something can extract its text: the
 *     PDF producer ships in core, so PDFs work out of the box, and
 *     plugins (Word-DOCX, Typst) extend the list by registering a
 *     producer rather than a separate converter. Losing this union is
 *     what made PDF uploads start rejecting at the gate, so it is
 *     load-bearing, not decorative.
 *
 *     This is a *second* allowlist surface that has to stay in sync with
 *     the MIME-refiner chain: a `.docx` that sniffs as `application/zip`
 *     is corrected by a refiner before the gate, and only the refiner's
 *     target MIME can be allowlisted. Nothing structurally enforces the
 *     pairing.
 *
 *  4. Configurable image MIME types — `image/*` is **additionally** allowed
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
     * Static text allowlist. A text source needs no extraction pipeline:
     * the bytes are stored once and inlined directly into the prompt
     * when they fit {@see \Spora\Agents\AttachmentRowRenderer}'s inline
     * budget, which is why this list is self-sufficient.
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
     * Static audio allowlist for the speech-to-text pipeline. The
     * `video/{webm,mp4}` entries cover Safari's MediaRecorder labelling
     * audio-only recordings with the video container type — see the
     * class docblock for the byte-sniff rationale.
     */
    public const AUDIO_MIME_TYPES = [
        'audio/webm',
        'audio/ogg',
        'audio/mp4',
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/x-m4a',
        'audio/flac',
        'video/webm',
        'video/mp4',
    ];

    /**
     * @param list<string>|null $imageExtensions Resolved image extensions
     *        (e.g. `['png', 'jpeg', 'webp']`). null falls back to the
     *        built-in default. An empty array disables images entirely.
     *        Strings are normalized through {@see normalizeImageExtensions()}.
     */
    public function __construct(
        private readonly MediaDerivativeService $derivatives,
        private readonly DriverFactory $driverFactory,
        ?array $imageExtensions = null,
    ) {
        $this->imageExtensions = self::normalizeImageExtensions($imageExtensions);
    }

    /** @var list<string> */
    private readonly array $imageExtensions;

    /**
     * @return list<string> Allowed MIME types for the given context.
     *                     Images are allowed when EITHER:
     *                       (a) the caller passed an `agent_id` whose
     *                           LLM driver reports `supportsImageInput()`
     *                           (the original post-`MediaPickerOverlay`
     *                           composer flow), OR
     *                       (b) the caller passed no `agent_id` (the
     *                           Media Archive plugin's direct upload flow
     *                           where the operator archives bytes on
     *                           their own behalf, not via an agent's
     *                           tool call) — there's no LLM in the
     *                           loop at upload time, so the
     *                           `supportsImageInput` gate doesn't apply.
     *                     In both cases `imageExtensions` must be
     *                     non-empty (operator hasn't disabled images).
     */
    public function allowedMimeTypes(?int $agentId = null): array
    {
        $set = [];
        foreach (self::TEXT_MIME_TYPES as $mime) {
            $set[strtolower($mime)] = true;
        }
        foreach (self::AUDIO_MIME_TYPES as $mime) {
            $set[strtolower($mime)] = true;
        }
        foreach ($this->derivatives->producerSourceMimeTypes() as $mime) {
            $set[strtolower($mime)] = true;
        }
        $agentSupportsImages = $agentId === null
            || $this->agentSupportsImages($agentId);
        if ($agentSupportsImages && $this->imageExtensions !== []) {
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
