<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\PrincipalContext;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Implements the `create_media` {@see MediaTool} operation — the one
 * primitive that lets an LLM put authored text into the Media Archive.
 * Extracted out of MediaTool for the same reason as
 * {@see MediaDerivativeHandler}: MediaTool sits at Sonar's per-class
 * method budget.
 *
 * The declared `mime_type` is a hint, never a claim: the byte ingest
 * path always re-sniffs the bytes, so the row is re-gated on the MIME
 * that actually landed in the DB and deleted when it isn't allowlisted.
 * That post-ingest check is the real gate — the pre-gate on the hint
 * only catches a MIME the operator would never have accepted anyway.
 *
 * Inputs arrive already-resolved (`$agentId` / `$userId` / `$context`
 * come from the orchestrator); failures flow back through
 * {@see ToolResult::fail()} with operator-friendly hints.
 */
final readonly class MediaCreateHandler
{
    /**
     * Hard cap on the authored payload. LLM generations top out around
     * 256 KB at 64k tokens, so 1 MiB never truncates a legitimate write
     * while still bounding the worker that has to hold the bytes.
     */
    private const MAX_CONTENT_BYTES = 1024 * 1024;

    /** `media_assets.filename` column width. */
    private const FILENAME_MAX_LENGTH = 255;

    public function __construct(
        private MediaArchiveService $archive,
        private MediaAllowedTypesService $allowedTypes,
    ) {}

    /**
     * @param  array<string, mixed> $arguments
     */
    public function create(
        array $arguments,
        int $agentId,
        ?int $userId,
        ?PrincipalContext $context,
    ): ToolResult {
        $content = Utf8Sanitizer::scrubString((string) ($arguments['content'] ?? ''));
        // Whitespace-only counts as empty: the op is non-idempotent, so a
        // row holding three spaces is a duplicate the caller can never
        // reconcile against later.
        if (trim($content) === '') {
            return ToolResult::fail('`content` is required for `create_media` and must not be empty.');
        }

        $size = strlen($content);
        if ($size > self::MAX_CONTENT_BYTES) {
            return ToolResult::fail(sprintf(
                '`create_media` content is %d bytes, over the %d-byte limit. Create one asset per section instead, or trim the document before storing it.',
                $size,
                self::MAX_CONTENT_BYTES,
            ));
        }

        $hint = trim((string) ($arguments['mime_type'] ?? ''));
        if ($hint === '') {
            $hint = 'text/markdown';
        }
        $hintFailure = $this->gateMime($hint, $agentId);
        if ($hintFailure !== null) {
            return $hintFailure;
        }

        $filename = $this->sanitiseFilename((string) ($arguments['filename'] ?? ''), $hint);
        $prompt = (string) ($arguments['prompt'] ?? '');

        // Without this the orchestrator's catch-all turns a store failure
        // into `System Error: The tool encountered a fatal exception: …`
        // plus a full stack trace in `data.trace`, all of it written to chat
        // history — the LLM gets no size hint and no retry strategy, and the
        // exception class leaks into a user-visible tool result.
        try {
            $asset = $this->archive->ingest(new MediaIngestRequest(
                bytes: $content,
                mime: $hint,
                filename: $filename,
                agentId: $agentId,
                userId: $userId,
                principalId: $context?->principalId,
                pluginSlug: 'spora-core',
                toolName: 'create_media',
                prompt: $prompt === '' ? null : $prompt,
                uploadSource: 'tool',
            ));
        } catch (Throwable $e) {
            return ToolResult::fail(sprintf(
                '`create_media` could not store the document: %s',
                $e->getMessage(),
            ));
        }

        // The pipeline ignored `$hint` and stored what it sniffed, so the
        // row is judged on that value — a good hint over bad bytes fails
        // here, which the pre-gate above cannot see.
        $sniffed = (string) ($asset->mime_type ?? '');
        if (!$this->allowedTypes->isAllowed($sniffed, $agentId)) {
            // `delete()` removes the row, which also removes the bytes in
            // `data_url` mode. A `local`-mode row would leave its file on
            // disk with no row pointing at it. Unreachable under the
            // shipped defaults — `MAX_CONTENT_BYTES` equals the default
            // `asset_store.auto_threshold_bytes` and `AutoAssetStore`
            // compares `<=`, so a payload at the cap stays inline — but an
            // operator who lowers the threshold or forces `local` would
            // accumulate one orphaned file per rejected call. Fixing that
            // belongs in `MediaArchiveService::delete()`, which has the same
            // gap on every other delete path.
            $this->archive->delete($asset->id);

            return ToolResult::fail(sprintf(
                '`create_media` rejected and discarded the stored row: the content was detected as "%s", which is not an allowed media type. Allowed: %s.',
                $sniffed === '' ? 'unknown' : $sniffed,
                $this->allowedMimeList($agentId),
            ));
        }

        return $this->created($asset);
    }

    /**
     * Shared allowlist rejection so both gates — the declared hint and
     * the sniffed result — name the same cause and list the same options.
     * Returning null means the MIME passed.
     */
    private function gateMime(string $mime, int $agentId): ?ToolResult
    {
        if ($this->allowedTypes->isAllowed($mime, $agentId)) {
            return null;
        }

        return ToolResult::fail(sprintf(
            '`create_media` does not accept mime_type "%s". Allowed: %s.',
            $mime,
            $this->allowedMimeList($agentId),
        ));
    }

    /**
     * `basename()` so a stored filename containing `../` cannot escape
     * the card label or the download path, then a Unicode-safe
     * allowlist plus a control-character strip (the value is echoed into
     * a `Content-Disposition` header), then the 255-char column cap. The
     * extension implied by the MIME hint is appended when the caller
     * left it off so the asset URL and the archive's extension map agree.
     */
    private function sanitiseFilename(string $raw, string $hint): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', Utf8Sanitizer::scrubString($raw)) ?? '';
        $name = basename(trim($name));
        $name = preg_replace('/[^\p{L}\p{N}._\- ]/u', '_', $name) ?? '';
        $name = trim($name);
        // A dot-only survivor (`.`, `..`) is what `basename()` leaves behind
        // for a path that names a directory rather than a file, and it
        // would be echoed straight into a `Content-Disposition` header.
        if (trim($name, '.') === '') {
            $name = 'media';
        }

        $extension = MediaArchiveService::extensionForMime($hint);
        if ($extension !== null && pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name .= '.' . $extension;
        }

        // Cap the stem, not the assembled name. Truncating the whole
        // string drops the extension — the first thing to go — so a
        // 400-character filename stored an operator-visible `Content-
        // Disposition` name with no `.md` on it.
        //
        // A name the archive has no extension for (`application/json`
        // maps to nothing) must come back with no trailing dot, so the
        // separator is only there when there is an extension to follow.
        $suffix = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $suffix === '' ? '' : '.' . $suffix;
        $stem   = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, self::FILENAME_MAX_LENGTH - mb_strlen($suffix));

        return $stem . $suffix;
    }

    /**
     * Asset header + a download-card embed, matching `get_media`'s shape
     * so the chat UI renders the card wherever the markdown is echoed.
     */
    private function created(MediaAsset $asset): ToolResult
    {
        $assetUrl = $asset->publicUrl();

        return ToolResult::ok(
            "Media asset {$asset->id}: " . ($asset->filename ?? '(no filename)') . "\n\n"
                . MediaEmbed::forAsset(
                    $asset,
                    $asset->typedMediaType(),
                    $assetUrl,
                    (string) ($asset->filename ?? '') ?: $asset->id,
                )
                . "\n\nEcho the block above verbatim so the chat UI renders the download card."
                . ' `create_media` is not idempotent — reuse this asset_id instead of calling it again.',
            [
                'asset_id'  => $asset->id,
                'asset_url' => $assetUrl,
                'filename'  => $asset->filename,
                'mime_type' => $asset->mime_type,
                'byte_size' => $asset->byte_size,
            ],
        );
    }

    private function allowedMimeList(int $agentId): string
    {
        return implode(', ', $this->allowedTypes->allowedMimeTypes($agentId));
    }
}
