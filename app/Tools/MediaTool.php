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
 * Eight operations:
 *
 *   - `search`            — paginated list of `media_assets` rows (auto-approved read).
 *                           Derivatives are filtered out — the LLM fetches a
 *                           derivative via `get_media` on its parent id.
 *   - `get_media`         — fetch one asset + a markdown embed snippet the LLM
 *                           can echo verbatim so the chat UI renders the
 *                           asset inline. The response includes a
 *                           `derivatives[]` array (every render of this
 *                           asset, e.g. PNG/PDF/SVG siblings) and a
 *                           `parent_id` (set when this asset is itself a
 *                           derivative of another), so the LLM can walk
 *                           both directions without a second round-trip.
 *                           Documents additionally carry an `md`
 *                           entry here — that is the extraction the LLM
 *                           reads, and `get_source` is how it reads it.
 *                           Auto-approved read.
 *   - `get_public_url`    — mint or fetch the public shareable URL of a single
 *                           asset. The only operation that requires approval
 *                           by default: it is the one that hands out a link
 *                           that keeps working outside the session, so the
 *                           operator sees and answers every call. Operators
 *                           can still drop the approval per-agent via an
 *                           override.
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
 *                           the asset's `md` derivative is surfaced
 *                           (truncated to
 *                           {@see self::GET_SOURCE_DERIVATIVE_PREVIEW_BYTES})
 *                           so the LLM gets something it can actually iterate
 *                           on. When no `md` derivative exists, fail with a
 *                           hint pointing at `create_derivative`. A pure
 *                           read: it never mints the derivative itself, so
 *                           it keeps the "reads a row the calling agent
 *                           already owns" auto-approval rationale intact.
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
 *                           the same derivative id, which is what makes a
 *                           blind retry safe. Enabled and auto-approved: the
 *                           derivative is derived from a parent the agent
 *                           already owns, it costs a render rather than
 *                           granting access, and the row it writes is
 *                           operator-visible in the dashboard.
 *   - `create_media`       — store LLM-authored text (Markdown, plain text,
 *                           CSV, JSON, XML, YAML, HTML) as a new source
 *                           asset. The only write that needs no existing
 *                           parent, so it is the primitive every text-parent
 *                           derivative producer builds on. The declared
 *                           `mime_type` is a hint: the byte ingest path
 *                           re-sniffs, and a row that lands on a
 *                           non-allowlisted MIME is deleted and rejected.
 *                           **Not idempotent** — a retry creates a second
 *                           asset, so reuse the returned `asset_id`.
 *                           Enabled and auto-approved, and the one write with
 *                           no natural key — so the row count is unbounded.
 *                           Bound it per agent via `requiresApprovalByDefault`.
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
    description: 'Search, retrieve, embed, share, and produce media library content (images, audio, video, documents). See the media-library skill for operation matrix, scope rules, and asset_id chaining.',
    category: 'data',
    icon: 'image',
    recommendsSkills: ['media-library'],
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
    description: 'List media_assets matching the given filters; returns paginated metadata.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'get_media',
    description: 'Return metadata + a markdown embed (image/audio/video/link) for one asset. Echo the embed verbatim so the chat UI renders inline.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'get_public_url',
    description: 'Mint or fetch a public shareable URL. Operator approval on every call — it hands out a link that works outside the session.',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'get_embed_code',
    description: 'Return a clean markdown snippet only (no asset header, no extracted text).',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'get_source',
    description: 'Read source bytes (text mimes) or the markdown derivative (binary mimes). See skill for mime handling.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'list_derivatives',
    description: "List a parent asset's derivative rows; optional `format` filter narrows to one kind.",
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'create_derivative',
    description: 'Render a fresh derivative via a registered producer; idempotent on (parent, format, producer_plugin, producer_operation). Safe to retry — a repeat returns the existing derivative id.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'create_media',
    description: 'Store text as a new media asset (Markdown, plain text, CSV, JSON, XML, YAML, HTML). Returns asset_id and a download link. Non-idempotent — a retry creates a second asset, so reuse the returned asset_id.',
    operatorDescription: 'Create a text media asset',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(name: 'plugin_slug', type: 'string', description: 'Filter by media_assets.plugin_slug.', required: false)]
#[ToolParameter(
    name: 'mime_type',
    type: 'string',
    description: 'For `search`: coarse bucket filter — mapped through MediaType::fromMime, NOT a LIKE on the exact mime. For `create_media`: the declared type of the content, default "text/markdown". Hint only — the archive re-sniffs the bytes and the returned `data.mime_type` is authoritative.',
    required: false,
    // No `default:` even though the description names one for
    // `create_media`. The schema advertises it to every op that accepts
    // `mime_type`, and `search` maps the value through
    // MediaType::fromMime, where `text/markdown` resolves to the Document
    // bucket — a model copying the advertised default would silently
    // narrow its search. Nothing in core applies a JSON-Schema default at
    // runtime either, so it would be documentation the model acts on and
    // the code ignores. `MediaCreateHandler::create()` holds the real
    // fallback, where it is actually honoured.
)]
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
#[ToolParameter(
    name: 'content',
    type: 'string',
    description: 'The text to store, verbatim. Required for `create_media`; capped at 1 MiB (larger calls fail with both byte counts). Ignored by every other op.',
    required: ['create_media'],
)]
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Filename to store the content under. Required for `create_media`; sanitised (basename, control characters stripped, 255-char cap) and the extension implied by `mime_type` is appended when absent. Ignored by every other op.',
    required: ['create_media'],
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Provenance for `create_media` — persisted on `media_assets.prompt` and read back by `get_media`. Ignored by every other op.',
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

    /**
     * Cap on the `md` derivative `get_source` inlines for a binary mime.
     *
     * Deliberately NOT the same constant as
     * {@see self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES}: this op is the LLM's
     * explicit "read this document" round-trip, so it owes the caller the
     * whole extracted text up to a sane ceiling, whereas the `get_media`
     * preview is an unsolicited glance. Harmonising them would either
     * starve `get_source` or flood `get_media`.
     */
    private const GET_SOURCE_DERIVATIVE_PREVIEW_BYTES = 64 * 1024;

    private readonly array $config;

    public function __construct(
        private readonly MediaArchiveService $archive,
        private readonly AuthService $auth,
        private readonly MediaAssetSerializer $serializer,
        private readonly MediaDerivativeService $derivatives,
        private readonly MediaSourceReader $sourceReader,
        private readonly MediaDerivativeHandler $derivativeHandler,
        private readonly MediaCreateHandler $createHandler,
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
            // Straight from the match, no `createMedia()` wrapper: the
            // handler extraction exists to keep this class under the
            // per-method budget, and a 21st method would undo it.
            'create_media'      => $this->createHandler->create($arguments, $agentId, $userId, $context),
            default             => ToolResult::fail('Invalid action. Must be search, get_media, get_public_url, get_embed_code, get_source, list_derivatives, create_derivative, or create_media.'),
        };
    }

    public function describeAction(array $arguments): string
    {
        $op = (string) ($arguments['action'] ?? $this->getOperationName($arguments));
        $assetId  = (string) ($arguments['asset_id'] ?? '');
        $format   = (string) ($arguments['format'] ?? '');
        $filename = (string) ($arguments['filename'] ?? '');
        $mime     = (string) ($arguments['mime_type'] ?? '');

        return match ($op) {
            'search'            => 'Media library search',
            'get_media'         => "Media get_media({$assetId})",
            'get_public_url'    => "Media get_public_url({$assetId})",
            'get_embed_code'    => "Media get_embed_code({$assetId})",
            'get_source'        => "Media get_source({$assetId})",
            'list_derivatives'  => "Media list_derivatives({$assetId}" . ($format !== '' ? ", format={$format}" : '') . ')',
            'create_derivative' => "Media create_derivative({$assetId}, format={$format})",
            'create_media'      => "Media create_media({$filename}, mime={$mime})",
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
     * Cap on the extracted-text preview inlined into `get_media`.
     * 8 KB keeps a single PDF chapter under the typical tool-result
     * cap; anything larger gets a truncation notice — the full content
     * stays on `ToolResult.data.extracted_text` (which is never sent to
     * the LLM, only to the operator UI).
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
        $extractedText = $this->readTextDerivative($asset);

        if ($prompt !== null) {
            $content .= "\n\nPrompt: " . $prompt;
        }
        if ($extractedText !== null) {
            $content .= "\n\nExtracted text:\n" . $this->previewExtractedText($extractedText);
            $content .= "\n\nCall `get_source` to read the full extracted text.";
        }

        return ToolResult::ok(
            $content,
            $this->describeAsset($asset, $mediaType, $assetUrl, $extractedText),
        );
    }

    /** Shared by `get_media` and `get_embed_code` so the two stay in lockstep. */
    private function embedForAsset(
        MediaAsset $asset,
        MediaType $mediaType,
        string $assetUrl,
        string $altText,
    ): string {
        return MediaEmbed::forAsset($asset, $mediaType, $assetUrl, $altText);
    }

    /**
     * Truncate extracted text to `$limit` so a 200-page PDF doesn't blow
     * the chat context. The full content is never inlined at this
     * boundary — it stays on the asset's `md` derivative, which
     * `get_source` reads.
     */
    private function previewExtractedText(string $text, int $limit = self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }
        return substr($text, 0, $limit)
            . "\n\n[…truncated — call `get_source` for the full extracted text]";
    }

    /**
     * Bytes of the asset's `md` derivative, or null when it has none.
     * Pure read: `get_source` and `get_media` both surface what ingest
     * or an explicit `create_derivative` already minted, and neither
     * mints one on the spot — a lazy create here would make a read
     * operation write to the archive, which is a different approval
     * story than the one these ops are approved under.
     */
    private function readTextDerivative(MediaAsset $asset): ?string
    {
        $derivative = $this->derivatives->findTextDerivative($asset);
        if ($derivative === null) {
            return null;
        }
        return $this->readDerivativeBytes($derivative);
    }

    /**
     * Derivative bytes, or null when they cannot be read or the producer
     * returned nothing. An empty extraction is treated as absent on
     * purpose — a scanned PDF with no text layer has no derivative worth
     * surfacing, and an empty block reads as a broken tool to the LLM.
     */
    private function readDerivativeBytes(MediaAsset $derivative): ?string
    {
        $bytes = $this->sourceReader->read($derivative);
        if ($bytes === null || trim($bytes) === '') {
            return null;
        }
        return $bytes;
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
     *    asset's `md` derivative (truncated to
     *    {@see self::GET_SOURCE_DERIVATIVE_PREVIEW_BYTES}) — that's
     *    the natural shape for an LLM to iterate on, and ingest
     *    mints it for every binary document it accepts. When no `md`
     *    derivative exists, fail pointing at `create_derivative`,
     *    which is how the LLM gets one. Base64-encoding raw binary
     *    bytes into the chat context is not useful for an LLM and
     *    ballooned the previous tool, so it was removed.
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
        // (which would say "no md derivative" and miss the real reason).
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
     * The `md` derivative is the shape an LLM can actually iterate on
     * (re-prompt on the doc, quote a page) — read it when the asset has
     * one. When it doesn't, fail pointing at `create_derivative`, which
     * mints it. Deliberately a pure read: minting here would make a
     * read op write to the archive, and its auto-approval rests on
     * "reads a row the calling agent already owns".
     */
    private function binarySourceFallback(MediaAsset $asset, string $mime): ToolResult
    {
        $derivative = $this->derivatives->findTextDerivative($asset);
        $extracted  = $derivative !== null ? $this->readDerivativeBytes($derivative) : null;
        $filename   = (string) ($asset->filename ?? $asset->id);

        if ($extracted === null) {
            return ToolResult::fail(sprintf(
                'Asset %s (%s) is a binary mime; `get_source` does not return raw bytes '
                    . 'for binary mimes, and this asset has no readable `md` derivative. '
                    . 'Call `create_derivative(asset_id: %s, format: "md")` to extract its '
                    . 'text, or `get_media` for the asset URL.',
                $asset->id,
                $mime,
                $asset->id,
            ));
        }

        $preview   = $this->previewExtractedText($extracted, self::GET_SOURCE_DERIVATIVE_PREVIEW_BYTES);
        $truncated = strlen($extracted) > self::GET_SOURCE_DERIVATIVE_PREVIEW_BYTES;

        return ToolResult::ok(
            sprintf(
                "⚠ %s is a binary mime (%s); `get_source` does not return raw bytes, "
                    . "showing its markdown derivative%s.\n\n%s",
                $filename,
                $mime,
                $truncated ? ' (truncated to ' . self::GET_SOURCE_DERIVATIVE_PREVIEW_BYTES . ' bytes)' : '',
                $preview,
            ),
            [
                'asset_id'      => $asset->id,
                'filename'      => $filename,
                'mime_type'     => $mime,
                'byte_size'     => $asset->byte_size,
                'fallback'      => 'md_derivative',
                'derivative_id' => $derivative?->id,
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
     * public URL when minted) plus the derivative graph an LLM needs to
     * walk the parent → child relationship in one round-trip. `search`
     * stays on the leaner {@see summarizeAsset()} to avoid N KB of
     * extracted text per row.
     *
     * `extracted_text` is the asset's `md` derivative content — the full
     * string, untruncated (the `content` the LLM sees is capped by
     * {@see self::GET_MEDIA_MARKDOWN_PREVIEW_BYTES}). It is null for an
     * asset that has no `md` derivative, which includes every text
     * source: those are their own text, and `get_source` reads them
     * directly.
     *
     * Derivative enrichment:
     *  - `derivatives[]` is empty for non-parents; for parents it
     *    reuses {@see MediaAssetSerializer::derivativeRowsFor()} so the
     *    LLM-visible row shape is byte-for-byte identical to the
     *    operator dashboard's VersionsStrip (label, asset_url,
     *    producer_plugin, producer_operation, created_at). The `md`
     *    entry is what `get_source` and the chat-attachment path read.
     *  - `parent_id` is null for non-derivatives; for derivatives it
     *    is the parent asset's id (one-shot reverse lookup on
     *    `media_derivatives.derivative_id`), so the LLM can fetch the
     *    parent via `get_media(asset_id: parent_id)` if it wants the
     *    wider context.
     *
     * @return array<string, mixed>
     */
    private function describeAsset(
        MediaAsset $asset,
        MediaType $mediaType,
        string $assetUrl,
        ?string $extractedText = null,
    ): array {
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
            'extracted_text'   => $extractedText,
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
