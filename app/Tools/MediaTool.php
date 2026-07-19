<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Auth\AuthService;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaType;
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
 *   - `user`: `search` filters by `user_id`, the three single-asset ops
 *     require `asset->user_id === $userId`.
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
        'agent' => 'Only media created by this agent',
        'user'  => 'All media owned by the current user (across agents)',
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
#[ToolParameter(name: 'asset_id', type: 'string', description: 'UUID of the media asset (required for get_media, get_public_url, and get_embed_code).', required: false)]
final class MediaTool extends AbstractTool
{
    public function __construct(
        private readonly MediaArchiveService $archive,
        private readonly AuthService $auth,
        private readonly ?ToolConfigService $toolConfigService = null,
        private readonly ?Request $request = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'search'         => $this->search($arguments, $agentId, $userId),
            'get_media'      => $this->getMedia($arguments, $agentId, $userId),
            'get_public_url' => $this->getPublicUrl($arguments, $agentId, $userId),
            'get_embed_code' => $this->getEmbedCode($arguments, $agentId, $userId),
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
            search: isset($arguments['mime_type']) ? (string) $arguments['mime_type'] : null,
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
    private function getMedia(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::fail('asset_id is required for get_media.');
        }

        $asset = $this->archive->find($assetId);
        if ($asset === null || !$this->assetInScope($asset, $agentId, $userId)) {
            return ToolResult::fail('Media asset not found.');
        }

        return ToolResult::ok(
            "Media asset {$asset->id}: {$asset->filename}",
            $this->summarizeAsset($asset),
        );
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function getPublicUrl(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::fail('asset_id is required for get_public_url.');
        }

        $asset = $this->archive->find($assetId);
        if ($asset === null || !$this->assetInScope($asset, $agentId, $userId)) {
            return ToolResult::fail('Media asset not found.');
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
    private function getEmbedCode(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::fail('asset_id is required for get_embed_code.');
        }

        $asset = $this->archive->find($assetId);
        if ($asset === null || !$this->assetInScope($asset, $agentId, $userId)) {
            return ToolResult::fail('Media asset not found.');
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

        return in_array($scope, ['agent', 'user'], true) ? $scope : 'agent';
    }

    private function assetInScope(MediaAsset $asset, int $agentId, ?int $userId): bool
    {
        if ($this->auth->isAdmin()) {
            return true;
        }

        $scope = $this->resolveScope($agentId, $userId);

        if ($scope === 'user') {
            $effectiveUserId = $userId ?? $this->auth->currentUserId();
            return $effectiveUserId !== null && (int) $asset->user_id === $effectiveUserId;
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
        $host = $this->request !== null
            ? $this->request->getSchemeAndHttpHost()
            : ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return rtrim($host, '/') . '/api/v1/public/media/' . $asset->id . '?token=' . $asset->public_access_token;
    }
}
