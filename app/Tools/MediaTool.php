<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
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
 * Built-in tool for reading the media library.
 *
 * Four operations:
 *
 *   - `search`         — paginated list of `media_assets` rows (auto-approved read)
 *   - `get_media`      — fetch one asset + a markdown embed snippet the LLM
 *                        can echo verbatim so the chat UI renders the
 *                        asset inline. Auto-approved read.
 *   - `get_public_url` — mint or fetch the public shareable URL of a single
 *                        asset. Hidden by default (`enabledByDefault: false`)
 *                        and always requires approval. Operators opt the
 *                        operation in via a per-agent override.
 *   - `get_embed_code` — return a markdown snippet (image / audio / video /
 *                        link) the assistant can drop into its reply,
 *                        pointing at the local archive URL. Auto-approved
 *                        read-only operation.
 *
 * Scope behavior (`scope` setting, default `agent`):
 *
 *   - `agent` (default): `search` filters by `agent_id`, `get_media`,
 *     `get_public_url` and `get_embed_code` require
 *     `asset->agent_id === $agentId`.
 *   - `principal`: `get_media`/`get_public_url`/`get_embed_code` accept any
 *     asset whose `asset->user_id === $context->ownerUserId` (direct upload
 *     by the principal's owner user) or whose attached agent belongs to the
 *     calling agent's principal. `search` falls through to the listing
 *     controller's principal-aware path.
 *   - `user` (legacy): kept as a silent alias for `principal` so existing
 *     `agent_tool_settings` rows keep working without a DB migration.
 *   - Admins (`AuthService::isAdmin()`) bypass scope and see all rows.
 */
#[Tool(
    name: 'media',
    displayName: 'Media Library',
    description: 'Search, retrieve, and share media from the media library. `get_media` echoes a markdown embed; `get_embed_code` returns the embed alone; `get_public_url` mints a shareable link.',
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
#[ToolParameter(name: 'plugin_slug', type: 'string', description: 'Filter by media_assets.plugin_slug.', required: false)]
#[ToolParameter(name: 'mime_type', type: 'string', description: 'Filter by media_assets.mime_type (case-insensitive LIKE).', required: false)]
#[ToolParameter(name: 'task_id', type: 'integer', description: 'Filter by media_assets.task_id.', required: false)]
#[ToolParameter(name: 'limit', type: 'integer', description: 'Maximum items to return (default 24, capped at 100).', required: false, default: 24)]
#[ToolParameter(name: 'offset', type: 'integer', description: 'Items to skip (default 0).', required: false, default: 0)]
#[ToolParameter(name: 'asset_id', type: 'string', description: 'UUID of the media asset. Required for get_media, get_public_url, and get_embed_code (search ignores it).', required: ['get_media', 'get_public_url', 'get_embed_code'])]
final class MediaTool extends AbstractTool
{
    /** @var string  Single error string used for asset-not-found / not-in-scope responses. */
    private const ERR_ASSET_NOT_FOUND = 'Media asset not found.';

    private readonly array $config;

    public function __construct(
        private readonly MediaArchiveService $archive,
        private readonly AuthService $auth,
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
            'search'         => $this->search($arguments, $agentId, $userId),
            'get_media'      => $this->getMedia($arguments, $agentId, $userId, $context),
            'get_public_url' => $this->getPublicUrl($arguments, $agentId, $userId, $context),
            'get_embed_code' => $this->getEmbedCode($arguments, $agentId, $userId, $context),
            default          => ToolResult::fail('Invalid action. Must be search, get_media, get_public_url, or get_embed_code.'),
        };
    }

    public function describeAction(array $arguments): string
    {
        $op = (string) ($arguments['action'] ?? $this->getOperationName($arguments));
        $assetId = (string) ($arguments['asset_id'] ?? '');

        return match ($op) {
            'search'         => 'Media library search',
            'get_media'      => "Media get_media({$assetId})",
            'get_public_url' => "Media get_public_url({$assetId})",
            'get_embed_code' => "Media get_embed_code({$assetId})",
            default          => "Media {$op}",
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

        $query = new ListMediaQuery(
            mediaType: $this->mediaTypeFromMime($arguments['mime_type'] ?? null),
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
            default => self::markdownLink($assetUrl, $altText),
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
     * Markdown link with the link text escaped against `\`/`[`/`]` injection,
     * mirroring {@see MediaEmbed::image()}'s alt escaping. URLs are HTML-escaped.
     */
    private static function markdownLink(string $url, string $text): string
    {
        $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $mdEsc    = strtr($safeText, ['\\' => '\\\\', ']' => '\\]', '[' => '\\[']);
        $safeUrl  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return "[{$mdEsc}]({$safeUrl})";
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

    private function mediaTypeFromMime(mixed $mime): ?MediaType
    {
        if (!is_string($mime) || trim($mime) === '') {
            return null;
        }
        return MediaType::fromMime($mime);
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
     * extracted text, public URL when minted). `search` stays on the
     * leaner {@see summarizeAsset()} to avoid N KB of converter output per row.
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
