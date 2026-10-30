<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\PrincipalContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The media library's folders, from the assistant's side.
 *
 * A separate tool rather than more operations on {@see MediaTool}: that
 * class sits at its per-method budget, and the folder surface has a
 * different scope — the *principal's* folders, not the calling agent's
 * media — so folding it in would blur two unrelated scopes.
 *
 * The assistant may **discover** folders, **file** an asset into one and
 * **search** within one. It may not create, rename, move or delete
 * folders: the operator curates the tree, and the assistant works inside
 * it. That split is why there is no `create_folder` operation here — the
 * folders API (`POST /api/v1/media/folders`) is the operator's surface.
 *
 * Every operation is scoped to the calling principal. An asset of another
 * principal is reported as missing rather than as forbidden.
 */
#[Tool(
    name: 'media_folders',
    displayName: 'Media Folders',
    description: 'Discover the media library folders, file an asset into a folder, and search inside a folder. The operator curates the folders; the assistant works inside them. See the media-library skill for the rules.',
    category: 'data',
    icon: 'folder',
    recommendsSkills: ['media-library'],
)]
#[ToolOperation(
    name: 'list_folders',
    description: 'List the media folders of your principal — name, path and how many assets each holds.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'file_asset',
    description: 'File a media asset into a folder (or back into the root). Filenames are unique within a folder.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolOperation(
    name: 'search_assets',
    description: 'List the assets inside one folder.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'folder_id',
    type: 'string',
    description: 'UUID of the folder. Required for `search_assets`; optional for `file_asset` (omit it to move the asset back into the root).',
    required: ['search_assets'],
)]
#[ToolParameter(
    name: 'asset_id',
    type: 'string',
    description: 'UUID of the media asset. Required for `file_asset`.',
    required: ['file_asset'],
)]
#[ToolParameter(
    name: 'limit',
    type: 'integer',
    description: 'Maximum assets to return from `search_assets` (default 24, capped at 100).',
    required: false,
    default: 24,
)]
final class MediaFolderTool extends AbstractTool
{
    public function __construct(
        private readonly MediaFolderService $folders,
        private readonly MediaArchiveService $archive,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $principalId = $context !== null ? $context->principalId : 0;
        if ($principalId <= 0) {
            return ToolResult::fail(
                'The media folder operations need a resolvable principal — none was supplied for this call.',
            );
        }

        return match ($this->getOperationName($arguments)) {
            'list_folders'  => $this->listFolders($principalId),
            'file_asset'    => $this->fileAsset($arguments, $principalId),
            'search_assets' => $this->searchAssets($arguments, $principalId),
            default         => ToolResult::fail(
                'Invalid action. Must be list_folders, file_asset, or search_assets.',
            ),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? $this->getOperationName($arguments));
        $folderId = (string) ($arguments['folder_id'] ?? '');
        $assetId = (string) ($arguments['asset_id'] ?? '');

        return match ($operation) {
            'list_folders'  => 'Media list_folders',
            'file_asset'    => sprintf('Media file_asset(%s, folder=%s)', $assetId, $folderId),
            'search_assets' => sprintf('Media search_assets(folder=%s)', $folderId),
            default         => sprintf('Media %s', $operation),
        };
    }

    private function listFolders(int $principalId): ToolResult
    {
        $folders = $this->folders->listForPrincipal($principalId);

        return ToolResult::ok(
            sprintf('Found %d media folder(s).', count($folders)),
            ['folders' => $folders],
        );
    }

    private function fileAsset(array $arguments, int $principalId): ToolResult
    {
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::fail('asset_id is required for file_asset.');
        }

        $asset = $this->archive->find($assetId);
        if ($asset === null || (int) $asset->principal_id !== $principalId) {
            return ToolResult::fail('Media asset not found.');
        }

        $folderId = $this->readFolderId($arguments);

        try {
            $this->folders->fileAsset($principalId, $asset, $folderId);
        } catch (MediaFolderNotFoundException) {
            return ToolResult::fail('That media folder does not exist.');
        } catch (MediaFolderUniquenessException $e) {
            return ToolResult::fail($e->getMessage());
        }

        $target = $folderId === null ? 'the root' : $folderId;

        return ToolResult::ok(
            sprintf('Filed asset %s in %s.', $asset->id, $target),
            ['asset_id' => $asset->id, 'folder_id' => $asset->folder_id],
        );
    }

    private function searchAssets(array $arguments, int $principalId): ToolResult
    {
        $folderId = $this->readFolderId($arguments);
        if ($folderId === null) {
            return ToolResult::fail('folder_id is required for search_assets.');
        }

        $limit = max(1, min(ListMediaQuery::PER_PAGE_MAX, (int) ($arguments['limit'] ?? 24)));
        $page = $this->archive->list(new ListMediaQuery(
            folderId: $folderId,
            principalIds: [$principalId],
            sort: ListMediaQuery::SORT_CREATED_DESC,
            page: 1,
            perPage: $limit,
        ));

        return ToolResult::ok(
            sprintf('Found %d asset(s) in folder %s.', $page->total(), $folderId),
            [
                'folder_id' => $folderId,
                'total'     => $page->total(),
                'items'     => $page->getCollection()->map(fn($asset): array => [
                    'id'        => $asset->id,
                    'filename'  => $asset->filename,
                    'media_type' => $asset->media_type,
                    'mime_type' => $asset->mime_type,
                    'kind'      => $asset->kind,
                    'created_at' => $asset->created_at?->toIso8601String(),
                ])->all(),
            ],
        );
    }

    private function readFolderId(array $arguments): ?string
    {
        $raw = $arguments['folder_id'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $value = trim($raw);

        return $value === '' ? null : $value;
    }
}
