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
 *   - `get_media`      — fetch one asset + its opaque `/api/v1/assets/<uuid>` URL
 *                        (auto-approved read)
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
    description: 'Search, retrieve, and share media from the media library. Reads use the local /api/v1/assets/<uuid> URL; use get_public_url to mint a shareable link, or get_embed_code to render the asset inline.',
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
    description: 'Return metadata + local /api/v1/assets/<uuid> URL for a single asset.',
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
     * @param  array<string, mixed> $arguments
     */
    private function getMedia(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context = null): ToolResult
    {
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::fail('asset_id is required for get_media.');
        }

        $asset = $this->archive->find($assetId);
        if ($asset === null || !$this->assetInScope($asset, $agentId, $userId, $context)) {
            return ToolResult::fail(self::ERR_ASSET_NOT_FOUND);
        }

        return ToolResult::ok(
            "Media asset {$asset->id}: {$asset->filename}",
            $this->summarizeAsset($asset),
        );
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function getPublicUrl(array $arguments, int $agentId, ?int $userId, ?PrincipalContext $context = null): ToolResult
    {
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::fail('asset_id is required for get_public_url.');
        }

        $asset = $this->archive->find($assetId);
        $host = (string) ($this->config['app_url'] ?? '');
        $inScope = $asset !== null && $this->assetInScope($asset, $agentId, $userId, $context);
        if (!$inScope || $host === '') {
            return ToolResult::fail(!$inScope
                ? self::ERR_ASSET_NOT_FOUND
                : 'Public origin is not configured.');
        }

        if ($asset->public_access_token === null || $asset->public_access_token === '') {
            $asset->public_access_token = MediaArchiveService::mintPublicAccessToken();
            $asset->save();
        }

        $url = $this->publicUrl($asset);

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
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::fail('asset_id is required for get_embed_code.');
        }

        $asset = $this->archive->find($assetId);
        if ($asset === null || !$this->assetInScope($asset, $agentId, $userId, $context)) {
            return ToolResult::fail(self::ERR_ASSET_NOT_FOUND);
        }

        $assetUrl = $asset->publicUrl();
        $mediaType = $asset->typedMediaType();
        $filename = (string) ($asset->filename ?? '');

        $embed = match ($mediaType) {
            MediaType::Image => MediaEmbed::image($assetUrl, $filename !== '' ? $filename : $asset->id),
            MediaType::Audio => MediaEmbed::audioFromUrl($assetUrl),
            MediaType::Video => MediaEmbed::videoFromUrl(
                $assetUrl,
                $asset->width !== null ? (int) $asset->width : null,
                $asset->height !== null ? (int) $asset->height : null,
            ),
            default => self::markdownLink($assetUrl, $filename !== '' ? $filename : $asset->id),
        };

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

    private function publicUrl(MediaAsset $asset): string
    {
        // The public base URL is the resolved global config value
        // (`config.app_url` — configured via config.php / SPORA_APP_URL, or
        // detected by RequestOrigin::detect() at boot). Reading it from the
        // global config rather than the per-request host keeps share URLs
        // stable across requests and immune to Host-header spoofing on a
        // single request.
        $host = (string) ($this->config['app_url'] ?? '');

        return rtrim($host, '/') . '/api/v1/public/media/' . $asset->id . '?token=' . $asset->public_access_token;
    }
}
