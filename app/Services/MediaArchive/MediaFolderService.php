<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Spora\Models\MediaAsset;
use Spora\Models\MediaFolder;
use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderValidationException;

/**
 * The Media Archive's folder tree.
 *
 * Folders are principal-scoped: every folder belongs to exactly one
 * principal, and a folder of another principal is reported as missing
 * rather than as forbidden — the caller has no business knowing it
 * exists.
 *
 * A folder's name and path are decided by {@see MediaFolderPaths}, which
 * also owns the sibling-name rule; the filename rule is owned here:
 * filenames are unique within a folder, enforced by UNIQUE
 * `(folder_id, filename)`. Assets in the root have a `NULL` folder, and
 * a SQL unique index does not compare `NULL`s, so the root is not
 * constrained.
 *
 * Each rule is checked before the write, and a write's own constraint
 * violation is converted into {@see MediaFolderUniquenessException}, so a
 * caller never sees a raw database exception.
 *
 * The tree itself — the subtree of a folder, the rewrite that keeps a
 * subtree's paths in step, and the write of a folder row — lives in
 * {@see MediaFolderTree}, so this class stays under the Sonar 20-method
 * cap.
 */
final class MediaFolderService
{
    public function __construct(
        private readonly MediaFolderPaths $paths = new MediaFolderPaths(),
        private readonly MediaFolderTree $tree = new MediaFolderTree(),
    ) {}

    /**
     * `asset_count` counts the assets filed directly in the folder; the
     * assets of a subfolder are counted in that subfolder.
     *
     * @return list<array{id: string, name: string, path: string, parent_id: string|null, asset_count: int}>
     */
    public function listForPrincipal(int $principalId): array
    {
        $folders = MediaFolder::query()
            ->where('principal_id', $principalId)
            ->orderBy('path')
            ->get();

        $counts = MediaAsset::query()
            ->whereIn('folder_id', $folders->pluck('id')->all())
            ->selectRaw('folder_id, COUNT(*) as aggregate')
            ->groupBy('folder_id')
            ->pluck('aggregate', 'folder_id');

        return $folders->map(
            fn(MediaFolder $folder): array => $this->row($folder, (int) ($counts[$folder->id] ?? 0)),
        )->all();
    }

    public function find(int $principalId, string $folderId): ?MediaFolder
    {
        return MediaFolder::query()
            ->where('principal_id', $principalId)
            ->where('id', $folderId)
            ->first();
    }

    /**
     * @return array{id: string, name: string, path: string, parent_id: string|null, asset_count: int}
     */
    public function describe(MediaFolder $folder): array
    {
        $count = MediaAsset::query()
            ->where('folder_id', $folder->id)
            ->count();

        return $this->row($folder, (int) $count);
    }

    /**
     * @throws MediaFolderNotFoundException When the parent is not the principal's.
     * @throws MediaFolderUniquenessException When a sibling already has that name.
     * @throws MediaFolderValidationException When the name is not a usable folder name.
     */
    public function create(int $principalId, string $name, ?string $parentId = null): MediaFolder
    {
        $name = $this->paths->assertName($name);
        $parent = $this->parentFor($principalId, $parentId);
        $path = $this->paths->pathFor($parent, $name);

        $this->paths->assertFree($principalId, $path, []);

        $folder = new MediaFolder();
        $folder->id = self::generateUuid();
        $folder->principal_id = $principalId;
        $folder->parent_id = $parent?->id;
        $folder->name = $name;
        $folder->path = $path;
        $this->tree->save($folder);

        return $folder;
    }

    /**
     * The folder's path and the paths of its descendants change with it.
     *
     * @throws MediaFolderNotFoundException When the folder is not the principal's.
     * @throws MediaFolderUniquenessException When a sibling already has that name.
     * @throws MediaFolderValidationException When the name is not a usable folder name.
     */
    public function rename(int $principalId, string $folderId, string $name): MediaFolder
    {
        $folder = $this->requireFolder($principalId, $folderId);
        $name = $this->paths->assertName($name);
        $parent = $folder->parent_id !== null
            ? $this->parentFor($principalId, (string) $folder->parent_id)
            : null;

        $this->tree->repath($folder, $this->paths->pathFor($parent, $name), $name);

        return $folder;
    }

    /**
     * @throws MediaFolderNotFoundException When the folder or the parent is not the principal's.
     * @throws MediaFolderUniquenessException When a sibling already has that name.
     * @throws MediaFolderValidationException When the move would put the folder inside itself.
     */
    public function move(int $principalId, string $folderId, ?string $parentId): MediaFolder
    {
        // The ancestry check and the parent write share one transaction, so
        // a move that is refused leaves the tree as it was.
        return Capsule::connection()->transaction(
            function () use ($principalId, $folderId, $parentId): MediaFolder {
                $this->tree->lockHierarchy($principalId);

                $folder = $this->requireFolder($principalId, $folderId);
                $parent = $this->parentFor($principalId, $parentId);

                if ($parent !== null) {
                    $descendants = $this->tree->descendantsWithPaths($folder, (string) $folder->path);
                    $inside = (string) $parent->id === (string) $folder->id
                        || array_key_exists((string) $parent->id, $descendants);
                    if ($inside) {
                        throw new MediaFolderValidationException(
                            'A folder cannot be moved inside itself.',
                        );
                    }
                }

                // The parent changes before the path is rewritten, so the single
                // save in `repath()` persists both.
                $folder->parent_id = $parent?->id;
                $this->tree->repath($folder, $this->paths->pathFor($parent, (string) $folder->name), (string) $folder->name);

                return $folder;
            },
        );
    }

    /**
     * The two writes share a transaction, so a move that collides with a
     * sibling rolls the rename back: the caller never sees a 422 next to
     * a folder that was renamed anyway.
     *
     * @throws MediaFolderNotFoundException When the folder or the parent is not the principal's.
     * @throws MediaFolderUniquenessException When a sibling already has that name.
     * @throws MediaFolderValidationException When the name is not a usable folder name, or the move would put the folder inside itself.
     */
    public function renameAndMove(int $principalId, string $folderId, string $name, ?string $parentId): MediaFolder
    {
        return Capsule::connection()->transaction(
            function () use ($principalId, $folderId, $name, $parentId): MediaFolder {
                $this->rename($principalId, $folderId, $name);

                return $this->move($principalId, $folderId, $parentId);
            },
        );
    }

    /**
     * Its subfolders go with it; its assets fall back to the root.
     *
     * @throws MediaFolderNotFoundException When the folder is not the principal's.
     */
    public function delete(int $principalId, string $folderId): void
    {
        $folder = $this->requireFolder($principalId, $folderId);
        $descendants = $this->tree->descendantsWithPaths($folder, (string) $folder->path);
        $folderIds = array_merge([(string) $folder->id], array_keys($descendants));

        // The assets fall back to the root here rather than through the
        // `ON DELETE SET NULL` foreign key: SQLite cannot add a foreign
        // key to an existing table, so the constraint does not exist on
        // that engine and the behaviour has to be the service's.
        MediaAsset::query()
            ->whereIn('folder_id', $folderIds)
            ->update(['folder_id' => null]);

        // Deepest first, so a child is never orphaned by its parent's
        // delete.
        uasort(
            $descendants,
            static fn(array $a, array $b): int => substr_count($b['path'], '/') <=> substr_count($a['path'], '/'),
        );
        foreach ($descendants as $descendant) {
            $descendant['folder']->delete();
        }
        $folder->delete();
    }

    /**
     * @param string $exceptAssetId The asset being moved, so that filing an
     *        asset into the folder it already sits in is not a conflict.
     *
     * @throws MediaFolderNotFoundException
     * @throws MediaFolderUniquenessException
     */
    public function fileTarget(
        int $principalId,
        string $folderId,
        ?string $filename,
        string $exceptAssetId = '',
    ): MediaFolder {
        $folder = $this->requireFolder($principalId, $folderId);

        // A row without a filename is not constrained by the index — a
        // SQL unique index does not compare `NULL`s — so there is nothing
        // to check and nothing to report.
        if ($filename === null || $filename === '') {
            return $folder;
        }

        $taken = MediaAsset::query()
            ->where('folder_id', $folder->id)
            ->where('filename', $filename);

        // The exclusion is only emitted when there is something to
        // exclude. Comparing a UUID column with an empty string is
        // engine-dependent: MariaDB stores `media_assets.id` in its
        // native `uuid` type, and that type compares as NULL against a
        // string that is not a UUID — so `id != ''` matches *no row*
        // there, while the same predicate is an ordinary string
        // comparison on MySQL's `char(36)` and on SQLite's TEXT. The
        // check would silently pass on MariaDB and the write would land
        // on the raw unique index.
        if ($exceptAssetId !== '') {
            $taken->where('id', '!=', $exceptAssetId);
        }

        if ($taken->exists()) {
            throw new MediaFolderUniquenessException(sprintf(
                'The folder "%s" already holds a file named "%s".',
                (string) $folder->name,
                $filename,
            ));
        }

        return $folder;
    }

    /**
     * @throws MediaFolderNotFoundException When the folder is not the principal's.
     * @throws MediaFolderUniquenessException When the folder already holds an asset with that filename.
     */
    public function fileAsset(int $principalId, MediaAsset $asset, ?string $folderId): MediaAsset
    {
        if ($folderId === null || $folderId === '') {
            $this->assertRootNameFree($principalId, $asset);

            $asset->folder_id = null;
            $asset->save();

            return $asset;
        }

        $folder = $this->fileTarget($principalId, $folderId, $asset->filename, (string) $asset->id);

        $asset->folder_id = (string) $folder->id;
        try {
            $asset->save();
        } catch (QueryException $e) {
            if (!$this->tree->isUniqueViolation($e)) {
                throw $e;
            }
            throw new MediaFolderUniquenessException(sprintf(
                'The folder "%s" already holds a file named "%s".',
                (string) $folder->name,
                (string) $asset->filename,
            ), 0, $e);
        }

        return $asset;
    }

    /**
     * The root is a namespace too: the rule the UNIQUE `(folder_id,
     * filename)` index cannot enforce is enforced here.
     *
     * A SQL unique index does not compare `NULL`s, so the root — whose
     * rows carry no folder — is outside the index's reach.
     *
     * @throws MediaFolderUniquenessException
     */
    private function assertRootNameFree(int $principalId, MediaAsset $asset): void
    {
        $filename = $asset->filename;
        if ($filename === null || $filename === '') {
            return;
        }

        $taken = MediaAsset::query()
            ->whereNull('folder_id')
            ->where('filename', $filename);

        // A row with no principal is its own namespace, the same way the
        // create path reads it.
        if ($principalId > 0) {
            $taken->where('principal_id', $principalId);
        } else {
            $taken->whereNull('principal_id');
        }

        if ((string) $asset->id !== '') {
            $taken->where('id', '!=', (string) $asset->id);
        }

        if ($taken->exists()) {
            throw new MediaFolderUniquenessException(sprintf(
                'The library root already holds a file named "%s".',
                $filename,
            ));
        }
    }

    /**
     * @throws MediaFolderNotFoundException
     */
    private function requireFolder(int $principalId, string $folderId): MediaFolder
    {
        $folder = $this->find($principalId, $folderId);
        if ($folder === null) {
            throw new MediaFolderNotFoundException(sprintf(
                'Media folder %s was not found.',
                $folderId,
            ));
        }

        return $folder;
    }

    /**
     * @throws MediaFolderNotFoundException
     */
    private function parentFor(int $principalId, ?string $parentId): ?MediaFolder
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        return $this->requireFolder($principalId, $parentId);
    }

    /**
     * @return array{id: string, name: string, path: string, parent_id: string|null, asset_count: int}
     */
    private function row(MediaFolder $folder, int $assetCount): array
    {
        return [
            'id'          => (string) $folder->id,
            'name'        => (string) $folder->name,
            'path'        => (string) $folder->path,
            'parent_id'   => $folder->parent_id !== null ? (string) $folder->parent_id : null,
            'asset_count' => $assetCount,
        ];
    }

    /**
     * Generate a UUIDv4 string without the `ramsey/uuid` dependency —
     * the same layout as the asset ids, so both share one canonical
     * format.
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
