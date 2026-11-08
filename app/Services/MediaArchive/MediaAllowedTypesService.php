<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Drivers\DriverFactory;
use Spora\Models\Agent;
use Throwable;

/**
 * Computes the dynamic set of MIME types accepted by the upload UI.
 *
 * Five sources, combined:
 *
 *  1. Static text allowlist — file types an LLM can read directly
 *     (TXT, MD, CSV, JSON, XML, YAML). Always allowed, and
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
 *     A producer's claim does not widen the operator's active-content
 *     decision: a script-capable type a producer advertises stays out of
 *     the allowlist while source 5 forbids it.
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
 *  5. The script-capable formats — HTML and SVG are allowed **only** when
 *     the operator enables `SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES`. A
 *     browser parses both as documents with an execution model, so they
 *     are governed by an explicit opt-in rather than being always-on
 *     (HTML) or always-off (SVG, which the raster image list excludes).
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
     *
     * `text/html` is deliberately absent. A browser parses it as a
     * document with an execution model, so it is not in the same class
     * as the prose formats here — it is governed by the operator's
     * `SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES` toggle and joined to the
     * allowlist from there.
     */
    public const TEXT_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        'text/csv',
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
     * The operator's decision about the script-capable formats.
     *
     * Null when the service was built without one — the state of the
     * archive before the operator's toggle existed, and the state of every
     * test that does not care about it. Absent a policy, the script-capable
     * formats are not permitted, which is the safe reading.
     */
    private readonly ?MediaActiveTypesPolicy $activeTypes;

    /**
     * The script-capable formats an operator may create.
     *
     * Narrower than {@see MediaScriptableTypes::SCRIPTABLE_MIME_TYPES},
     * which is the *serving* vocabulary: it decides what the sandbox
     * policy covers, and covering a format nothing produces costs nothing
     * but a line of the serving list. Offering one for upload is a
     * separate decision — an operator who turns on "Allow HTML and SVG"
     * asked for HTML and SVG, not for XHTML.
     */
    public const CREATION_MIME_TYPES = [
        'text/html',
        'image/svg+xml',
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
        ?MediaActiveTypesPolicy $activeTypes = null,
    ) {
        $this->imageExtensions = self::normalizeImageExtensions($imageExtensions);
        $this->activeTypes = $activeTypes;
    }

    private function permitsActiveTypes(): bool
    {
        return $this->activeTypes?->allowActiveTypes() ?? false;
    }

    /**
     * Whether a MIME may be created.
     *
     * A producer's claim to handle a format does not widen the operator's
     * active-content decision: a producer that advertises `text/html`
     * would otherwise make HTML uploadable while the operator has
     * forbidden it.
     */
    private function permitsCreationOf(string $mime): bool
    {
        if ($this->activeTypes !== null) {
            return $this->activeTypes->permitsCreationOf($mime);
        }

        return !MediaScriptableTypes::isScriptable($mime);
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
        if ($this->permitsActiveTypes()) {
            // The script-capable list, straight from the class that owns
            // it. Two copies of it would be two answers to "which formats
            // carry script", and the serving boundary would have to be kept
            // in step with this gate by hand.
            foreach (self::CREATION_MIME_TYPES as $mime) {
                $set[strtolower($mime)] = true;
            }
        }
        foreach ($this->derivatives->producerSourceMimeTypes() as $mime) {
            if (!$this->permitsCreationOf($mime)) {
                continue;
            }
            $set[strtolower($mime)] = true;
        }
        foreach ($this->allowedImageMimeTypes($agentId) as $mime) {
            $set[$mime] = true;
        }

        return array_keys($set);
    }

    /**
     * @return list<string>
     */
    private function allowedImageMimeTypes(?int $agentId): array
    {
        if ($agentId !== null && !$this->agentSupportsImages($agentId)) {
            return [];
        }
        if ($this->imageExtensions === []) {
            return [];
        }

        $mimes = [];
        foreach ($this->imageExtensions as $ext) {
            $mime = self::imageMimeForExtension($ext);
            if ($mime !== null) {
                $mimes[] = $mime;
            }
        }

        return $mimes;
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
     *   picker only offers raster types, and this list is the operator's
     *   *image* policy. SVG is not a raster image — it is a script-capable
     *   format, and `SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES` is what governs
     *   it.
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
