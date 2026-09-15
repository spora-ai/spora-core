<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Component\HttpFoundation\Request;

/**
 * Built-in tool for reading and producing media library content.
 *
 * Seven operations:
 *
 *   - `search`            — paginated list of `media_assets` rows (auto-approved read).
 *                           Derivatives are filtered out — the LLM fetches a
 *                           derivative via `get_media` on its parent id.
 *   - `get_media`         — fetch one asset + a markdown embed snippet the LLM
 *                           can echo verbatim so the chat UI renders the
 *                           asset inline. The response now includes a
 *                           `derivatives[]` array (every render of this
 *                           asset, e.g. PNG/PDF/SVG siblings) and a
 *                           `parent_id` (set when this asset is itself a
 *                           derivative of another), so the LLM can walk
 *                           both directions without a second round-trip.
 *                           Auto-approved read.
 *   - `get_public_url`    — mint or fetch the public shareable URL of a single
 *                           asset. Hidden by default (`enabledByDefault: false`)
 *                           and always requires approval. Operators opt the
 *                           operation in via a per-agent override.
 *   - `get_embed_code`    — return a markdown snippet (image / audio / video /
 *                           link) the assistant can drop into its reply,
 *                           pointing at the local archive URL. Auto-approved
 *                           read-only operation.
 *   - `get_source`        — return the source of a single asset so the LLM
 *                           can iterate on it (e.g. read a `.typ` source,
 *                           re-ingest an extracted document). Text-shaped
 *                           mimes (text/*, JSON, XML, YAML, SVG, CSV, x-typst)
 *                           inline up to {@see self::GET_SOURCE_TEXT_MAX};
 *                           binary mimes do NOT return raw bytes — instead
 *                           the asset's extracted `markdown_content` is
 *                           surfaced (truncated to
 *                           {@see self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES}) so
 *                           the LLM gets something it can actually iterate
 *                           on. When no markdown_content exists, fail with a
 *                           hint pointing at `get_media` /
 *                           `list_derivatives`. Hidden by default
 *                           (`enabledByDefault: false`) and always requires
 *                           approval — every call surfaces the asset's source
 *                           to the LLM, which is a stronger promise than
 *                           `get_media`'s embed-only contract.
 *   - `list_derivatives`  — return the derivative rows of a parent asset
 *                           (e.g. the PNG/PDF renders of a `.typ` source).
 *                           Takes an optional `format` filter to narrow
 *                           to a specific derivative kind (e.g. only the
 *                           PNG). Auto-approved read — the same
 *                           operator-visible shape the dashboard renders,
 *                           so the LLM and the operator see the same row.
 *   - `create_derivative` — generate a fresh derivative by calling the
 *                           registered {@see \Spora\Services\MediaArchive\MediaDerivativeProducerInterface}
 *                           that matches the parent's MIME and the
 *                           requested `format`. Idempotent on the
 *                           natural key `(parent_id, format, producer_plugin,
 *                           producer_operation)` — re-rendering returns
 *                           the same derivative id. Off by default; each
 *                           call requires operator approval.
 *
 * Scope behavior (`scope` setting, default `agent`):
 *
 *   - `agent` (default): `search` filters by `agent_id`, the per-asset
 *     ops require `asset->agent_id === $agentId`.
 *   - `principal`: the per-asset ops accept any asset whose
 *     `asset->user_id === $context->ownerUserId` (direct upload by the
 *     principal's owner user) or whose attached agent belongs to the
 *     calling agent's principal. `search` falls through to the listing
 *     controller's principal-aware path.
 *   - `user` (legacy): kept as a silent alias for `principal` so existing
 *     `agent_tool_settings` rows keep working without a DB migration.
 *   - Admins (`AuthService::isAdmin()`) bypass scope and see all rows.
 */
#[Tool(
    name: 'media',
    displayName: 'Media Library',
    description: 'Search, retrieve, share, and produce media library content. `get_media` echoes a markdown embed and lists the asset\'s derivatives; `get_embed_code` returns the embed alone; `get_public_url` mints a shareable link; `get_source` reads bytes; `list_derivatives` enumerates an asset\'s derivatives; `create_derivative` generates a fresh derivative via a registered producer.',
    category: 'data',
    icon: 'image',
)]
#[ToolSetting(
    key: 'scope',
    label: 'Library scope',
    type: 'select',
    default: 'agent',
    options: [
        'agent'     => 'Only media created by this agent',
        'principal' => 'All media owned by the calling agent\'s principal '
                     . '(direct uploads + every agent of the principal)',
    ],
    description: 'Controls which media_assets rows the tool can read.',
)]
#[ToolOperation(
    name: 'search',
    description: 'List media_assets matching the given filters. Returns paginated metadata.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'get_media',
    description: 'Return metadata + a markdown embed (image / audio / video / link) for a single asset. The LLM should echo the embed verbatim so the chat UI renders it inline.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'get_public_url',
    description: 'Mint or fetch a public shareable URL for a single asset. Off by default — must be enabled in the tool config.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'get_embed_code',
    description: 'Return a markdown snippet (image / audio / video) for a single '
               . 'asset that the assistant can include in its reply. Uses the '
               . 'local archive URL by default.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'get_source',
    description: 'Return the source of a single asset so the LLM can iterate on it '
               . '(e.g. read a .typ file, re-ingest an extracted document). Text-shaped '
               . 'mimes (text/*, application/json, application/xml, application/yaml, '
               . 'application/x-yaml, application/svg+xml, application/csv, '
               . 'application/x-typst) return the bytes inline, capped at '
               . '{@see self::GET_SOURCE_TEXT_MAX}. Binary mimes do NOT return raw '
               . 'bytes; instead the asset\'s extracted `markdown_content` is '
               . 'surfaced (truncated to {@see self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES}) '
               . 'when available. When no markdown_content exists, fail with a hint '
               . 'pointing at `get_media` / `list_derivatives`. External assets '
               . '(storage_mode=external) have no Spora-side payload — fail with a '
               . 'hint to use `get_media` for the source URL. Off by default; each '
               . 'call requires operator approval.',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'list_derivatives',
    description: 'List the derivative rows of a parent asset (e.g. every PDF/PNG/SVG render '
               . 'of a .typ source, every thumbnail/format conversion of an uploaded image). '
               . 'Pass an optional `format` filter (e.g. "png") to narrow to one derivative '
               . 'kind. Each row carries `media_id`, `format`, `asset_url`, `label`, '
               . '`producer_plugin`, `producer_operation`, and `created_at` — the same shape '
               . 'the operator dashboard renders on the VersionsStrip, so the LLM and the '
               . 'operator see identical rows. Empty list when the parent has no derivatives. '
               . 'Auto-approved read.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'create_derivative',
    description: 'Generate a fresh derivative of a parent asset via the registered '
               . 'derivative producer that matches the parent\'s MIME/extension and the '
               . 'requested `format` (e.g. render a .typ source to PNG, convert an uploaded '
               . 'image to a WebP thumbnail). The natural key `(parent_id, format, '
               . 'producer_plugin, producer_operation)` makes the operation idempotent — '
               . 're-rendering returns the same derivative id. Optional `options` carries '
               . 'producer-specific knobs (e.g. {"page": 0, "ppi": 144} for typst). Returns '
               . 'the new derivative\'s id + asset_url + producer attribution. Each call '
               . 'requires operator approval because the producer may take seconds and '
               . 'always writes a fresh `media_assets` row, but the operation is exposed '
               . 'by default so agents can propose renders without an enable step.',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolParameter(name: 'plugin_slug', type: 'string', description: 'Filter by media_assets.plugin_slug.', required: false)]
#[ToolParameter(name: 'mime_type', type: 'string', description: 'Filter by media_assets.mime_type (case-insensitive LIKE).', required: false)]
#[ToolParameter(name: 'task_id', type: 'integer', description: 'Filter by media_assets.task_id.', required: false)]
#[ToolParameter(name: 'limit', type: 'integer', description: 'Maximum items to return (default 24, capped at 100).', required: false, default: 24)]
#[ToolParameter(name: 'offset', type: 'integer', description: 'Items to skip (default 0).', required: false, default: 0)]
#[ToolParameter(name: 'asset_id', type: 'string', description: 'UUID of the media asset. Required for get_media, get_public_url, get_embed_code, get_source, list_derivatives, and create_derivative (search ignores it).', required: ['get_media', 'get_public_url', 'get_embed_code', 'get_source', 'list_derivatives', 'create_derivative'])]
#[ToolParameter(
    name: 'format',
    type: 'string',
    description: 'Derivative format identifier. Required for `create_derivative` (e.g. "png", "pdf", "thumbnail-256", "format-webp"). Optional filter for `list_derivatives` (e.g. "png" returns only PNG derivatives). Ignored by every other op.',
    required: ['create_derivative'],
)]
#[ToolParameter(
    name: 'options',
    type: 'object',
    description: 'Producer-specific options for `create_derivative` (e.g. {"page": 0, "ppi": 144} for typst; {"longEdge": 1024} for image derivatives). Omit for defaults. Ignored by every other op.',
    required: false,
)]
final class MediaTool extends AbstractTool
{
    /** @var string  Single error string used for asset-not-found / not-in-scope responses. */
    private const ERR_ASSET_NOT_FOUND = 'Media asset not found.';

    /**
     * Text-shaped inline cap for `get_source`. 5 MiB is enough for a
     * medium-sized Typst source, a small JSON config, or a long-form
     * document excerpt — anything bigger should be streamed through
     * `get_media`'s public URL, not inlined into the LLM context.
     */
    private const GET_SOURCE_TEXT_MAX = 5 * 1024 * 1024;

    private readonly array $config;

    public function __construct(
        private readonly MediaArchiveService $archive,
        private readonly AuthService $auth,
        private readonly MediaAssetSerializer $serializer,
        private readonly MediaDerivativeService $derivatives,
        private readonly MediaSourceReader $sourceReader,
        private readonly MediaDerivativeHandler $derivativeHandler,
        private readonly ?ToolConfigService $toolConfigService = null,
        Request|array $request = [],
    ) {
        $this->config = is_array($request) ? $request : [];
    }

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'search'            => $this->search($arguments, $agentId, $userId),
            'get_media'         => $this->getMedia($arguments, $agentId, $userId, $context),
            'get_public_url'    => $this->getPublicUrl($arguments, $agentId, $userId, $context),
            'get_embed_code'    => $this->getEmbedCode($arguments, $agentId, $userId, $context),
            'get_source'        => $this->getSource($arguments, $agentId, $userId, $context),
            'list_derivatives'  => $this->listDerivatives($arguments, $agentId, $userId, $context),
            'create_derivative' => $this->createDerivative($arguments, $agentId, $userId, $context),
            default             => ToolResult::fail('Invalid action. Must be search, get_media, get_public_url, get_embed_code, get_source, list_derivatives, or create_derivative.'),
        };
    }

    public function describeAction(array $arguments): string
    {
        $op = (string) ($arguments['action'] ?? $this->getOperationName($arguments));
        $assetId = (string) ($arguments['asset_id'] ?? '');
        $format  = (string) ($arguments['format'] ?? '');

        return match ($op) {
            'search'            => 'Media library search',
            'get_media'         => "Media get_media({$assetId})",
            'get_public_url'    => "Media get_public_url({$assetId})",
            'get_embed_code'    => "Media get_embed_code({$assetId})",
            'get_source'        => "Media get_source({$assetId})",
            'list_derivatives'  => "Media list_derivatives({$assetId}" . ($format !== '' ? ", format={$format}" : '') . ')',
            'create_derivative' => "Media create_derivative({$assetId}, format={$format})",
            default             => "Media {$op}",
        };
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function search(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $scope = $this->resolveScope($agentId, $userId);

        $limit  = (int) ($arguments['limit'] ?? 24);
        $offset = (int) ($arguments['offset'] ?? 0);

        // max(1, ...) guarantees $perPage >= 1, so intdiv is always safe.
        $perPage = max(1, min(ListMediaQuery::PER_PAGE_MAX, $limit));
        $page    = max(1, intdiv($offset, $perPage) + 1);

        $mimeArg = $arguments['mime_type'] ?? null;
        $mediaTypeFilter = (is_string($mimeArg) && trim($mimeArg) !== '') ? MediaType::fromMime($mimeArg) : null;

        $query = new ListMediaQuery(
            mediaType: $mediaTypeFilter,
            agentId: $scope === 'agent' ? $agentId : null,
            userId: $scope === 'user' && $userId !== null ? $userId : null,
            pluginSlug: isset($arguments['plugin_slug']) ? (string) $arguments['plugin_slug'] : null,
            // `mime_type` is accepted for LLM ergonomics (the assistant
            // usually has it on hand from prior tool results) but is NOT
            // passed into `search` — ListMediaQuery's `search` is a LIKE
            // over prompt|filename|asset_url|source_url, not a mime filter.
            // The coarse media_type bucket above is what actually filters.
            search: null,
            sort: ListMediaQuery::SORT_CREATED_DESC,
            page: $page,
            perPage: $perPage,
        );

        $paginator = $this->archive->list($query);

        return ToolResult::ok(
            "Found {$paginator->total()} media asset(s).",
            [
                'total'  => $paginator->total(),
                'limit'  => $perPage,
                'offset' => ($page - 1) * $perPage,
                'items'  => $paginator->getCollection()->map(fn(MediaAsset $a): array => $this->summarizeAsset($a))->all(),
            ],
        );
    }

    /**
     * Cap on the `markdown_content` preview inlined into `get_media`.
     * 8 KB keeps a single PDF chapter under the typical tool-result
     * cap; anything larger gets a truncation notice — the full content
     * stays on `ToolResult.data.markdown_content` (which is never sent
     * to the LLM, only to the operator UI).
     */
    private const GET_MEDIA_MARKDOWN_PREVIEW_BYTES = 8 * 1024;

    /**
     * @param  array<string, mixed> $arguments
     * @return MediaAsset|ToolResult
     */
    private function resolveAssetOrFail(
        string $operation,
        array $arguments,
        int $agentId,
        ?int $userId,
        ?PrincipalContext $context,
    ): MediaAsset|ToolResult {
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::fail("asset_id is required for {$operation}.");
        }
        $asset = $this->archive->find($assetId);
        if ($asset === null || !$this->assetInScope($asset, $agentId, $userId, $context)) {
            return ToolResult::fail(self::ERR_ASSET_NOT_FOUND);
        }
        return $asset;
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function getMedia(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context = null): ToolResult
    {
        $asset = $this->resolveAssetOrFail('get_media', $arguments, $agentId, $userId, $context);
        if ($asset instanceof ToolResult) {
            return $asset;
        }

        $assetUrl  = $asset->publicUrl();
        $mediaType = $asset->typedMediaType();
        $filename  = (string) ($asset->filename ?? '');
        $altText   = $filename !== '' ? $filename : $asset->id;
        $embed     = $this->embedForAsset($asset, $mediaType, $assetUrl, $altText);

        $content = "Media asset {$asset->id}: " . ($asset->filename ?? '(no filename)') . "\n\n" . $embed
            . "\n\nEcho the markdown block above verbatim so the chat UI renders the asset inline."
            . ' For a clean embed snippet without this header, call `get_embed_code`.'
            . ' For a shareable external link, call `get_public_url` (approval-gated).';

        $prompt = isset($asset->prompt) && trim((string) $asset->prompt) !== ''
            ? trim((string) $asset->prompt)
            : null;
        $markdownContent = $asset->markdown_content;

        if ($prompt !== null) {
            $content .= "\n\nPrompt: " . $prompt;
        }
        if (is_string($markdownContent) && $markdownContent !== '') {
            $content .= "\n\nExtracted text:\n" . $this->previewMarkdownContent($markdownContent);
        }

        return ToolResult::ok($content, $this->describeAsset($asset, $mediaType, $assetUrl));
    }

    /** Shared by `get_media` and `get_embed_code` so the two stay in lockstep. */
    private function embedForAsset(
        MediaAsset $asset,
        MediaType $mediaType,
        string $assetUrl,
        string $altText,
    ): string {
        return match ($mediaType) {
            MediaType::Image => MediaEmbed::image($assetUrl, $altText),
            MediaType::Audio => MediaEmbed::audioFromUrl($assetUrl),
            MediaType::Video => MediaEmbed::videoFromUrl(
                $assetUrl,
                $asset->width !== null ? (int) $asset->width : null,
                $asset->height !== null ? (int) $asset->height : null,
            ),
            default => MediaEmbed::link($assetUrl, $altText),
        };
    }

    /**
     * Truncate `markdown_content` to {@see self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES}
     * so a 200-page PDF doesn't blow the chat context.
     */
    private function previewMarkdownContent(string $markdownContent): string
    {
        if (strlen($markdownContent) <= self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES) {
            return $markdownContent;
        }
        return substr($markdownContent, 0, self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES)
            . "\n\n[…truncated — full extracted text is on ToolResult.data.markdown_content]";
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function getPublicUrl(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context = null): ToolResult
    {
        $asset = $this->resolveAssetOrFail('get_public_url', $arguments, $agentId, $userId, $context);
        if ($asset instanceof ToolResult) {
            return $asset;
        }

        $url = $this->ensurePublicUrl($asset);
        if ($url === null) {
            return ToolResult::fail('Public origin is not configured.');
        }

        return ToolResult::ok(
            "Public URL for {$asset->id}: {$url}",
            [
                'asset_id'   => $asset->id,
                'public_url' => $url,
            ],
        );
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function getEmbedCode(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context = null): ToolResult
    {
        $asset = $this->resolveAssetOrFail('get_embed_code', $arguments, $agentId, $userId, $context);
        if ($asset instanceof ToolResult) {
            return $asset;
        }

        $assetUrl  = $asset->publicUrl();
        $mediaType = $asset->typedMediaType();
        $filename  = (string) ($asset->filename ?? '');
        $altText   = $filename !== '' ? $filename : $asset->id;
        $embed     = $this->embedForAsset($asset, $mediaType, $assetUrl, $altText);

        return ToolResult::ok(
            $embed,
            [
                'asset_id'   => $asset->id,
                'asset_url'  => $assetUrl,
                'media_type' => $mediaType->value,
                'embed'      => $embed,
            ],
        );
    }

    /**
     * Read the asset's source back to the caller so the LLM can
     * iterate (e.g. re-typeset a previously uploaded `.typ` source,
     * re-ingest an extracted document). Scope, ownership, and
     * approval are inherited from {@see resolveAssetOrFail()} and
     * the per-op `requiresApprovalByDefault: true`.
     *
     * Behavior by MIME shape:
     *  - Text-shaped (text/*, application/json, application/xml,
     *    application/yaml, application/x-yaml, application/svg+xml,
     *    application/csv, application/x-typst): read the bytes
     *    inline, capped at {@see self::GET_SOURCE_TEXT_MAX}.
     *  - Binary (everything else): do NOT read bytes. Return the
     *    asset's extracted `markdown_content` (truncated to
     *    {@see self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES}) when the
     *    operator already populated it during ingestion — that's
     *    the natural shape for an LLM to iterate on. When no
     *    markdown_content exists, fail with a hint pointing at
     *    `get_media` and `list_derivatives`. Base64-encoding raw
     *    binary bytes into the chat context is not useful for an
     *    LLM and ballooned the previous tool, so it was removed.
     *
     * Storage handling:
     *  - `data_url` / `local`: read bytes via the asset stores
     *    (text path only).
     *  - `external`: no Spora-side payload — point the LLM at
     *    `get_media` for the source URL instead of pretending the
     *    payload is local.
     *
     * @param  array<string, mixed> $arguments
     */
    private function getSource(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context = null): ToolResult
    {
        $asset = $this->resolveAssetOrFail('get_source', $arguments, $agentId, $userId, $context);
        if ($asset instanceof ToolResult) {
            return $asset;
        }

        // External assets never have a Spora-side payload — surface that
        // first so the LLM isn't routed to the binary-fallback path
        // (which would say "no markdown_content" and miss the real reason).
        if ($asset->storage_mode === 'external') {
            return ToolResult::fail(sprintf(
                'Asset %s is stored externally (storage_mode=external) and has no '
                . 'Spora-side payload. Use `get_media` to retrieve its source URL.',
                $asset->id,
            ));
        }

        $mime = (string) ($asset->mime_type ?? 'application/octet-stream');

        return MediaSourceReader::isTextShapedMime($mime)
            ? $this->buildTextSource($asset, $mime)
            : $this->binarySourceFallback($asset, $mime);
    }

    private function buildTextSource(MediaAsset $asset, string $mime): ToolResult
    {
        $bytes = $this->sourceReader->read($asset);
        if ($bytes === null) {
            return ToolResult::fail(sprintf(
                'Asset %s payload could not be read (storage_mode=%s). '
                    . 'The underlying blob may be missing; try `get_media` for the public URL.',
                $asset->id,
                $asset->storage_mode,
            ));
        }

        $filename = (string) ($asset->filename ?? $asset->id);
        $size     = strlen($bytes);

        if ($size > self::GET_SOURCE_TEXT_MAX) {
            return ToolResult::fail(sprintf(
                'Asset %s (%s, %.1f MiB) exceeds the inline `get_source` text cap of %d MiB. '
                    . 'Use `get_media` to fetch the public URL and stream the bytes out-of-band.',
                $asset->id,
                $filename,
                $size / (1024 * 1024),
                (int) (self::GET_SOURCE_TEXT_MAX / (1024 * 1024)),
            ));
        }

        return ToolResult::ok(
            sprintf('Source of %s (%s, %d bytes, mime=%s):', $asset->id, $filename, $size, $mime) . "\n\n" . $bytes,
            [
                'asset_id'  => $asset->id,
                'filename'  => $filename,
                'mime_type' => $mime,
                'byte_size' => $size,
                'encoding'  => 'utf-8',
            ],
        );
    }

    /**
     * Binary files don't return their raw bytes through `get_source`.
     * If the operator already extracted a markdown/text preview during
     * ingestion, surface that — it's the shape an LLM can actually
     * iterate on (re-typeset from `.typ`, re-prompt on the doc). When
     * nothing was extracted, fail with a clear hint pointing at
     * `get_media` and `list_derivatives` so the LLM doesn't keep
     * guessing.
     */
    private function binarySourceFallback(MediaAsset $asset, string $mime): ToolResult
    {
        $markdownContent = $asset->markdown_content;
        if (!is_string($markdownContent) || $markdownContent === '') {
            return ToolResult::fail(sprintf(
                'Asset %s (%s) is a binary mime; `get_source` does not return raw bytes '
                    . 'for binary mimes, and no extracted markdown_content is available. '
                    . 'Use `get_media` for the asset URL or `list_derivatives` to see '
                    . 'rendered alternatives.',
                $asset->id,
                $mime,
            ));
        }

        $preview  = $this->previewMarkdownContent($markdownContent);
        $truncated = strlen($markdownContent) > self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES;
        $filename = (string) ($asset->filename ?? $asset->id);

        return ToolResult::ok(
            sprintf(
                "⚠ Asset %s (%s) is a binary mime; `get_source` does not return raw bytes. "
                    . "Returning the extracted markdown_content%s instead. Use `get_media` "
                    . "for the asset URL.\n\n%s",
                $asset->id,
                $mime,
                $truncated ? ' (truncated to ' . self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES . ' bytes)' : '',
                $preview,
            ),
            [
                'asset_id'      => $asset->id,
                'filename'      => $filename,
                'mime_type'     => $mime,
                'byte_size'     => $asset->byte_size,
                'fallback'      => 'markdown_content',
                'truncated'     => $truncated,
                'content'       => $preview,
            ],
        );
    }

    /**
     * List the derivative rows attached to a parent asset. Thin
     * dispatcher — the row formatting + format-filter logic lives
     * in {@see MediaDerivativeHandler::listDerivatives()} so the
     * {@see MediaAssetSerializer::derivativeRowsFor()} call site is
     * shared with `create_derivative` and the operator dashboard.
     *
     * @param  array<string, mixed> $arguments
     */
    private function listDerivatives(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context = null): ToolResult
    {
        $parent = $this->resolveAssetOrFail('list_derivatives', $arguments, $agentId, $userId, $context);
        if ($parent instanceof ToolResult) {
            return $parent;
        }

        return $this->derivativeHandler->listDerivatives($parent, $arguments);
    }

    /**
     * Produce a fresh derivative of the parent asset. Thin
     * dispatcher — producer resolution + the `produce()` /
     * `create()` pipeline lives in
     * {@see MediaDerivativeHandler::createDerivative()} so the tool
     * and the operator dashboard's REST controller share one
     * code path.
     *
     * @param  array<string, mixed> $arguments
     */
    private function createDerivative(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context = null): ToolResult
    {
        $parent = $this->resolveAssetOrFail('create_derivative', $arguments, $agentId, $userId, $context);
        if ($parent instanceof ToolResult) {
            return $parent;
        }

        return $this->derivativeHandler->createDerivative($parent, $arguments, $userId, $context);
    }

    private function resolveScope(int $agentId, ?int $userId): string
    {
        $settings = $this->toolConfigService?->getEffectiveSettings(self::class, $agentId, $userId) ?? [];
        $scope = is_string($settings['scope'] ?? null) ? $settings['scope'] : 'agent';

        // Legacy 'user' is a runtime alias for 'principal' — see the
        // class-level docblock. Anything unrecognised falls back to
        // 'agent' so a stale or mistyped setting never widens visibility.
        return in_array($scope, ['agent', 'principal', 'user'], true) ? $scope : 'agent';
    }

    /**
     * True iff `$asset` is visible under the calling agent's current
     * `scope` setting. Admin users always pass. For `scope=agent`, the
     * asset must be attached to the calling agent. For `scope=principal`
     * (and the legacy `scope=user` alias), the asset must belong to the
     * calling agent's principal — either as a direct upload by the
     * principal's owner user or as an asset of any agent of the
     * principal — checked via {@see MediaArchiveService::isAssetInPrincipalScope()}.
     *
     * `PrincipalContext` is the orchestrator-supplied bundle; when it's
     * unresolvable (cold principal / test harness) the principal branch
     * returns false rather than crashing.
     */
    private function assetInScope(
        MediaAsset $asset,
        int $agentId,
        ?int $userId,
        ?PrincipalContext $context = null,
    ): bool {
        if ($this->auth->isAdmin()) {
            return true;
        }

        $scope = $this->resolveScope($agentId, $userId);
        // Legacy alias: existing agents may still have 'user' persisted.
        $effective = $scope === 'user' ? 'principal' : $scope;

        if ($effective === 'principal') {
            return $this->archive->isAssetInPrincipalScope($asset, $context, $userId);
        }

        return (int) $asset->agent_id === $agentId;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeAsset(MediaAsset $asset): array
    {
        return [
            'id'         => $asset->id,
            'filename'   => $asset->filename,
            'media_type' => $asset->media_type,
            'mime_type'  => $asset->mime_type,
            'byte_size'  => $asset->byte_size,
            'asset_url'  => $asset->publicUrl(),
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }

    /**
     * Richer per-asset payload for `get_media` — superset of {@see summarizeAsset()}
     * with the metadata the operator UI needs (width / height, prompt,
     * extracted text, public URL when minted) plus the derivative
     * graph an LLM needs to walk the parent → child relationship in
     * one round-trip. `search` stays on the leaner {@see summarizeAsset()}
     * to avoid N KB of converter output per row.
     *
     * Derivative enrichment:
     *  - `derivatives[]` is empty for non-parents; for parents it
     *    reuses {@see MediaAssetSerializer::derivativeRowsFor()} so the
     *    LLM-visible row shape is byte-for-byte identical to the
     *    operator dashboard's VersionsStrip (label, asset_url,
     *    producer_plugin, producer_operation, created_at).
     *  - `parent_id` is null for non-derivatives; for derivatives it
     *    is the parent asset's id (one-shot reverse lookup on
     *    `media_derivatives.derivative_id`), so the LLM can fetch the
     *    parent via `get_media(asset_id: parent_id)` if it wants the
     *    wider context.
     *
     * @return array<string, mixed>
     */
    private function describeAsset(MediaAsset $asset, MediaType $mediaType, string $assetUrl): array
    {
        return [
            'id'               => $asset->id,
            'filename'         => $asset->filename,
            'media_type'       => $mediaType->value,
            'mime_type'        => $asset->mime_type,
            'byte_size'        => $asset->byte_size,
            'width'            => $asset->width,
            'height'           => $asset->height,
            'duration_seconds' => $asset->duration_seconds,
            'prompt'           => $asset->prompt,
            'markdown_content' => $asset->markdown_content,
            'tags'             => $asset->tags,
            'metadata'         => $asset->metadata,
            'asset_url'        => $assetUrl,
            'public_url'       => $this->ensurePublicUrl($asset),
            'parent_id'        => $this->derivatives->parentOf($asset->id),
            'derivatives'      => $this->serializer->derivativeRowsFor($asset),
            'created_at'       => $asset->created_at?->toIso8601String(),
        ];
    }

    /**
     * Mint the public access token if needed and return the share URL.
     * Returns null when no `app_url` is configured. Has a write
     * side-effect on the asset (token mint + save) — both `get_public_url`
     * and `get_media` need the URL, so the side-effect lives here.
     *
     * Reading the host from `config.app_url` (not the per-request host)
     * keeps share URLs stable across requests and immune to Host-header
     * spoofing. Mirrors {@see MediaAssetSerializer::buildPublicUrl()} so
     * the tool payload matches what the REST endpoint returns.
     */
    private function ensurePublicUrl(MediaAsset $asset): ?string
    {
        $host = (string) ($this->config['app_url'] ?? '');
        if ($host === '') {
            return null;
        }

        if ($asset->public_access_token === null || $asset->public_access_token === '') {
            $asset->public_access_token = MediaArchiveService::mintPublicAccessToken();
            $asset->save();
        }

        return rtrim($host, '/') . '/api/v1/public/media/' . $asset->id . '?token=' . $asset->public_access_token;
    }
}
