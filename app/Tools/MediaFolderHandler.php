<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderValidationException;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * The folder operations of the {@see MediaTool} — the media library's
 * tree, seen from the assistant's side.
 *
 * Split out of MediaTool for the same reason as
 * {@see MediaCreateHandler} and {@see MediaDerivativeHandler}: MediaTool
 * sits at Sonar's per-class method budget.
 *
 * The tree belongs to the principal, so every operation is scoped to the
 * calling principal: a folder or an asset of another principal is never
 * named as forbidden — the answer is an absent folder or an absent asset,
 * the same answer a row that does not exist gets — so there is no oracle
 * to probe with.
 *
 * The assistant may **discover** folders, **shape** them (create, rename,
 * delete) and **file** assets in them. It may not **re-parent** a folder:
 * `MediaFolderService::move()` rewrites the path of a whole subtree, and
 * a new folder's `parent_id` covers what the assistant needs.
 */
final readonly class MediaFolderHandler
{
    /**
     * The refusal for a folder that is not the principal's.
     *
     * The same words for every operation that names one: the folder
     * either is the principal's or it does not exist as far as the
     * caller is concerned.
     */
    private const MSG_FOLDER_NOT_FOUND = 'That media folder does not exist.';

    public function __construct(
        private MediaFolderService $folders,
        private MediaArchiveService $archive,
    ) {}

    /**
     * Run one folder operation.
     *
     * The operation name is the tool's own — the same string the
     * assistant sends — so the tool hands it over unchanged.
     *
     * @param  array<string, mixed> $arguments
     */
    public function run(string $operation, int $principalId, array $arguments = []): ToolResult
    {
        if ($principalId <= 0) {
            return ToolResult::fail(
                'The media folder operations need a resolvable principal — none was supplied for this call.',
            );
        }

        return match ($operation) {
            'list_folders'  => $this->listFolders($principalId),
            'create_folder' => $this->createFolder($principalId, $arguments),
            'rename_folder' => $this->renameFolder($principalId, $arguments),
            'delete_folder' => $this->deleteFolder($principalId, $arguments),
            'file_asset'    => $this->fileAsset($principalId, $arguments),
            'search_assets' => $this->searchAssets($principalId, $arguments),
            default         => ToolResult::fail('Invalid folder action.'),
        };
    }

    private function listFolders(int $principalId): ToolResult
    {
        $folders = $this->folders->listForPrincipal($principalId);

        // The folder ids are what the folder ops take, and the data
        // channel never reaches the model — so the rows go into the
        // content too.
        return ToolResult::ok(
            MediaToolPresenter::folders($folders),
            ['folders' => $folders],
        );
    }

    /**
     * Create a folder in the principal's tree.
     *
     * The id is in the answer: the folder ops take it, and the agent
     * that just created the folder is about to file an asset into it.
     * A path alone is not a handle — the id is.
     *
     * @param  array<string, mixed> $arguments
     */
    private function createFolder(int $principalId, array $arguments): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ToolResult::fail('name is required for create_folder.');
        }

        $parentId = $this->readId($arguments, 'parent_id');

        try {
            $folder = $this->folders->create($principalId, $name, $parentId);
        } catch (MediaFolderNotFoundException | MediaFolderUniquenessException | MediaFolderValidationException $e) {
            return ToolResult::fail(
                $e instanceof MediaFolderNotFoundException ? 'That parent folder does not exist.' : $e->getMessage(),
            );
        }

        return ToolResult::ok(
            sprintf('Created the media folder %s (id %s).', $folder->path, (string) $folder->id),
            ['folder' => $this->folders->describe($folder)],
        );
    }

    /**
     * Rename a folder. The path of the folder and of its descendants
     * follows the new name.
     *
     * @param  array<string, mixed> $arguments
     */
    private function renameFolder(int $principalId, array $arguments): ToolResult
    {
        $refusal = $this->missingArgument('rename_folder', $arguments, ['folder_id', 'name']);
        if ($refusal !== null) {
            return $refusal;
        }

        $folderId = (string) $arguments['folder_id'];
        $name = trim((string) $arguments['name']);

        try {
            $folder = $this->folders->rename($principalId, $folderId, $name);
        } catch (MediaFolderNotFoundException | MediaFolderUniquenessException | MediaFolderValidationException $e) {
            return ToolResult::fail(
                $e instanceof MediaFolderNotFoundException ? self::MSG_FOLDER_NOT_FOUND : $e->getMessage(),
            );
        }

        return ToolResult::ok(
            sprintf('Renamed the media folder to %s.', $folder->path),
            ['folder' => $this->folders->describe($folder)],
        );
    }

    /**
     * Delete a folder. Its assets are not deleted — they fall back to
     * the root, which is what the folder's deletion means for them.
     *
     * @param  array<string, mixed> $arguments
     */
    private function deleteFolder(int $principalId, array $arguments): ToolResult
    {
        $folderId = $this->readId($arguments, 'folder_id');
        if ($folderId === null) {
            return ToolResult::fail('folder_id is required for delete_folder.');
        }

        try {
            $this->folders->delete($principalId, $folderId);
        } catch (MediaFolderNotFoundException) {
            return ToolResult::fail(self::MSG_FOLDER_NOT_FOUND);
        }

        return ToolResult::ok(
            sprintf(
                'Deleted the media folder %s. Its assets are in the root now; its subfolders went with it.',
                $folderId,
            ),
            ['folder_id' => $folderId],
        );
    }

    /**
     * File an asset into a folder, or back into the root.
     *
     * @param  array<string, mixed> $arguments
     */
    private function fileAsset(int $principalId, array $arguments): ToolResult
    {
        $asset = $this->resolveAsset($arguments, $principalId);
        if ($asset instanceof ToolResult) {
            return $asset;
        }

        $folderId = $this->readId($arguments, 'folder_id');

        $failure = $this->fileFailure($principalId, $asset, $folderId);
        if ($failure !== null) {
            return $failure;
        }

        $target = $folderId === null ? 'the root' : $folderId;

        return ToolResult::ok(
            sprintf('Filed asset %s in %s.', $asset->id, $target),
            ['asset_id' => $asset->id, 'folder_id' => $asset->folder_id],
        );
    }

    /**
     * The assets inside one folder.
     *
     * @param  array<string, mixed> $arguments
     */
    private function searchAssets(int $principalId, array $arguments): ToolResult
    {
        $folderId = $this->readId($arguments, 'folder_id');
        if ($folderId === null) {
            return ToolResult::fail('folder_id is required for search_assets.');
        }

        // The folder is resolved before the listing, so "no such folder"
        // and "an empty folder" do not read the same. The lookup is
        // principal-scoped, so another principal's folder resolves to
        // null here too and is reported as missing — never as forbidden,
        // which would be an oracle to probe with.
        if ($this->folders->find($principalId, $folderId) === null) {
            return ToolResult::fail(self::MSG_FOLDER_NOT_FOUND);
        }

        $limit = max(1, min(ListMediaQuery::PER_PAGE_MAX, (int) ($arguments['limit'] ?? 24)));
        $page = $this->archive->list(new ListMediaQuery(
            folderId: $folderId,
            principalIds: [$principalId],
            sort: ListMediaQuery::SORT_CREATED_DESC,
            page: 1,
            perPage: $limit,
        ));

        $items = $page->getCollection()->map(fn($asset): array => [
            'id'         => $asset->id,
            'filename'   => $asset->filename,
            'media_type' => $asset->media_type,
            'mime_type'  => $asset->mime_type,
            'byte_size'  => $asset->byte_size,
            'kind'       => $asset->kind,
            'created_at' => $asset->created_at?->toIso8601String(),
        ])->all();

        return ToolResult::ok(
            MediaToolPresenter::folderAssets($items, $page->total(), $folderId),
            [
                'folder_id' => $folderId,
                'total'     => $page->total(),
                'items'     => $items,
            ],
        );
    }

    /**
     * The refusal for a missing argument, or null when they are all there.
     *
     * One check for a list of parameters, so the operation methods stay
     * under the 3-return ceiling the house style sets.
     *
     * @param  array<string, mixed> $arguments
     * @param  list<string>         $required
     */
    private function missingArgument(string $operation, array $arguments, array $required): ?ToolResult
    {
        foreach ($required as $parameter) {
            if (trim((string) ($arguments[$parameter] ?? '')) === '') {
                return ToolResult::fail(sprintf('%s is required for %s.', $parameter, $operation));
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function resolveAsset(array $arguments, int $principalId): MediaAsset|ToolResult
    {
        $assetId = trim((string) ($arguments['asset_id'] ?? ''));
        if ($assetId === '') {
            return ToolResult::fail('asset_id is required for file_asset.');
        }

        $asset = $this->archive->find($assetId);
        if ($asset === null || (int) $asset->principal_id !== $principalId) {
            return ToolResult::fail('Media asset not found.');
        }

        return $asset;
    }

    private function fileFailure(int $principalId, MediaAsset $asset, ?string $folderId): ?ToolResult
    {
        try {
            $this->folders->fileAsset($principalId, $asset, $folderId);
        } catch (MediaFolderNotFoundException) {
            return ToolResult::fail(self::MSG_FOLDER_NOT_FOUND);
        } catch (MediaFolderUniquenessException $e) {
            return ToolResult::fail($e->getMessage());
        }

        return null;
    }

    /**
     * The id the operation names, or `null` for the root.
     *
     * @param  array<string, mixed> $arguments
     */
    private function readId(array $arguments, string $key): ?string
    {
        $raw = $arguments[$key] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $value = trim($raw);

        return $value === '' ? null : $value;
    }
}
