<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Illuminate\Database\QueryException;
use Spora\Models\MediaAsset;
use Spora\Models\MediaFolder;
use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderValidationException;
use Spora\Services\Text\Utf8Sanitizer;

/**
 * The Media Archive's folder tree.
 *
 * Folders are principal-scoped: every folder belongs to exactly one
 * principal, and a folder of another principal is reported as missing
 * rather than as forbidden — the caller has no business knowing it
 * exists.
 *
 * Two uniqueness rules are owned here and mirrored by database indexes:
 *
 *   - sibling names are unique — enforced by UNIQUE `(principal_id, path)`,
 *     because two siblings with the same name produce the same path;
 *   - filenames are unique within a folder — enforced by UNIQUE
 *     `(folder_id, filename)`. Assets in the root have a `NULL` folder, and
 *     a SQL unique index does not compare `NULL`s, so the root is not
 *     constrained.
 *
 * Both rules are checked before the write, and a write's own constraint
 * violation is converted into {@see MediaFolderUniquenessException}, so a
 * caller never sees a raw database exception.
 */
final class MediaFolderService
{
    /** The separator between path segments. */
    private const PATH_SEPARATOR = '/';

    /** The longest a folder name may be, matching the column width. */
    private const NAME_MAX_LENGTH = 255;

    /** The longest a folder path may be, matching the column width. */
    private const PATH_MAX_LENGTH = 512;

    /**
     * The principal's folders, flat, ordered by path.
     *
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

    /** The folder, or null when it does not exist for this principal. */
    public function find(int $principalId, string $folderId): ?MediaFolder
    {
        return MediaFolder::query()
            ->where('principal_id', $principalId)
            ->where('id', $folderId)
            ->first();
    }

    /**
     * The wire shape of one folder.
     *
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
     * Create a folder under `$parentId` (the root when it is null).
     *
     * @throws MediaFolderNotFoundException When the parent is not the principal's.
     * @throws MediaFolderUniquenessException When a sibling already has that name.
     * @throws MediaFolderValidationException When the name is not a usable folder name.
     */
    public function create(int $principalId, string $name, ?string $parentId = null): MediaFolder
    {
        $name = $this->assertName($name);
        $parent = $this->parentFor($principalId, $parentId);
        $path = $this->pathFor($parent, $name);

        $this->assertPathFree($principalId, $path, []);

        $folder = new MediaFolder();
        $folder->id = self::generateUuid();
        $folder->principal_id = $principalId;
        $folder->parent_id = $parent?->id;
        $folder->name = $name;
        $folder->path = $path;
        $this->saveFolder($folder);

        return $folder;
    }

    /**
     * Rename a folder. The folder's path and the paths of its descendants
     * change with it.
     *
     * @throws MediaFolderNotFoundException When the folder is not the principal's.
     * @throws MediaFolderUniquenessException When a sibling already has that name.
     * @throws MediaFolderValidationException When the name is not a usable folder name.
     */
    public function rename(int $principalId, string $folderId, string $name): MediaFolder
    {
        $folder = $this->requireFolder($principalId, $folderId);
        $name = $this->assertName($name);
        $parent = $folder->parent_id !== null
            ? $this->parentFor($principalId, (string) $folder->parent_id)
            : null;

        $this->repath($folder, $this->pathFor($parent, $name), $name);

        return $folder;
    }

    /**
     * Move a folder under `$parentId` (the root when it is null).
     *
     * @throws MediaFolderNotFoundException When the folder or the parent is not the principal's.
     * @throws MediaFolderUniquenessException When a sibling already has that name.
     * @throws MediaFolderValidationException When the move would put the folder inside itself.
     */
    public function move(int $principalId, string $folderId, ?string $parentId): MediaFolder
    {
        $folder = $this->requireFolder($principalId, $folderId);
        $parent = $this->parentFor($principalId, $parentId);

        if ($parent !== null) {
            $descendants = $this->descendantsWithPaths($folder, (string) $folder->path);
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
        $this->repath($folder, $this->pathFor($parent, (string) $folder->name), (string) $folder->name);

        return $folder;
    }

    /**
     * Delete a folder. Its subfolders go with it; its assets fall back to
     * the root.
     *
     * @throws MediaFolderNotFoundException When the folder is not the principal's.
     */
    public function delete(int $principalId, string $folderId): void
    {
        $folder = $this->requireFolder($principalId, $folderId);
        $descendants = $this->descendantsWithPaths($folder, (string) $folder->path);
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
     * The folder an asset may be filed in.
     *
     * Throws when the folder is not the principal's, or when the folder
     * already holds an asset with that filename — the rule the UNIQUE
     * `(folder_id, filename)` index enforces.
     *
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
            ->where('filename', $filename)
            ->where('id', '!=', $exceptAssetId)
            ->exists();

        if ($taken) {
            throw new MediaFolderUniquenessException(sprintf(
                'The folder "%s" already holds a file named "%s".',
                (string) $folder->name,
                $filename,
            ));
        }

        return $folder;
    }

    /**
     * File an asset into a folder, or back into the root when `$folderId`
     * is null.
     *
     * @throws MediaFolderNotFoundException When the folder is not the principal's.
     * @throws MediaFolderUniquenessException When the folder already holds an asset with that filename.
     */
    public function fileAsset(int $principalId, MediaAsset $asset, ?string $folderId): MediaAsset
    {
        if ($folderId === null || $folderId === '') {
            $asset->folder_id = null;
            $asset->save();

            return $asset;
        }

        $folder = $this->fileTarget($principalId, $folderId, $asset->filename, (string) $asset->id);

        $asset->folder_id = (string) $folder->id;
        try {
            $asset->save();
        } catch (QueryException $e) {
            if (!$this->isUniqueViolation($e)) {
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
     * The folder, or a "not found" domain error.
     *
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
     * The parent folder, or null for the root.
     *
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
     * @throws MediaFolderValidationException
     */
    private function assertName(string $name): string
    {
        $name = trim(Utf8Sanitizer::scrubString($name));

        if ($name === '') {
            throw new MediaFolderValidationException('A folder name is required.');
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new MediaFolderValidationException(sprintf(
                'A folder name may not exceed %d characters.',
                self::NAME_MAX_LENGTH,
            ));
        }
        // A name is one path segment, never a path: the library reads a
        // theme's name as a single segment, and a separator in it would
        // make the folder's path ambiguous.
        if (str_contains($name, self::PATH_SEPARATOR)) {
            throw new MediaFolderValidationException(
                'A folder name may not contain a "/" — it is one path segment, not a path.',
            );
        }

        return $name;
    }

    /**
     * @throws MediaFolderValidationException
     */
    private function pathFor(?MediaFolder $parent, string $name): string
    {
        $prefix = $parent !== null ? (string) $parent->path : '';
        $path = $prefix . self::PATH_SEPARATOR . $name;

        if (mb_strlen($path) > self::PATH_MAX_LENGTH) {
            throw new MediaFolderValidationException(sprintf(
                'The folder path may not exceed %d characters.',
                self::PATH_MAX_LENGTH,
            ));
        }

        return $path;
    }

    /**
     * @param list<string> $exceptIds Folder ids whose own path does not count as a collision.
     * @throws MediaFolderUniquenessException
     */
    private function assertPathFree(int $principalId, string $path, array $exceptIds): void
    {
        $query = MediaFolder::query()
            ->where('principal_id', $principalId)
            ->where('path', $path);

        if ($exceptIds !== []) {
            $query->whereNotIn('id', $exceptIds);
        }

        if ($query->exists()) {
            throw new MediaFolderUniquenessException(sprintf(
                'A folder already exists at "%s".',
                $path,
            ));
        }
    }

    /**
     * Rewrite a folder's path and the paths of its descendants.
     *
     * The descendants' paths are recomputed from their names under the
     * new root path rather than patched, so a rename or a move can never
     * leave a stale segment behind.
     *
     * @throws MediaFolderUniquenessException
     */
    private function repath(MediaFolder $folder, string $path, string $name): void
    {
        $principalId = (int) $folder->principal_id;
        $descendants = $this->descendantsWithPaths($folder, $path);
        $exceptIds = array_merge([(string) $folder->id], array_keys($descendants));

        $this->assertPathFree($principalId, $path, $exceptIds);
        foreach ($descendants as $descendant) {
            $this->assertPathFree($principalId, $descendant['path'], $exceptIds);
        }

        $folder->name = $name;
        $folder->path = $path;
        $this->saveFolder($folder);

        foreach ($descendants as $descendant) {
            $descendant['folder']->path = $descendant['path'];
            $this->saveFolder($descendant['folder']);
        }
    }

    /**
     * The folder's descendants and the path each one takes under
     * `$rootPath`.
     *
     * @return array<string, array{folder: MediaFolder, path: string}>
     */
    private function descendantsWithPaths(MediaFolder $root, string $rootPath): array
    {
        $all = MediaFolder::query()
            ->where('principal_id', $root->principal_id)
            ->get();

        $byParent = [];
        foreach ($all as $folder) {
            $byParent[(string) $folder->parent_id][] = $folder;
        }

        $descendants = [];
        $stack = [[(string) $root->id, $rootPath]];
        while ($stack !== []) {
            [$parentId, $parentPath] = array_pop($stack);
            foreach ($byParent[$parentId] ?? [] as $child) {
                $childPath = $parentPath . self::PATH_SEPARATOR . (string) $child->name;
                $descendants[(string) $child->id] = [
                    'folder' => $child,
                    'path'   => $childPath,
                ];
                $stack[] = [(string) $child->id, $childPath];
            }
        }

        return $descendants;
    }

    /**
     * @throws MediaFolderUniquenessException
     */
    private function saveFolder(MediaFolder $folder): void
    {
        try {
            $folder->save();
        } catch (QueryException $e) {
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }
            throw new MediaFolderUniquenessException(sprintf(
                'A folder already exists at "%s".',
                (string) $folder->path,
            ), 0, $e);
        }
    }

    /**
     * True for the driver errors that mean "this unique key is taken".
     *
     * MySQL reports 1062 (ER_DUP_ENTRY), MariaDB the same, PostgreSQL
     * SQLSTATE 23505, and SQLite says so in the message.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo;
        $driverCode = is_array($errorInfo) ? (int) ($errorInfo[1] ?? 0) : 0;
        if ($driverCode === 1062 || $driverCode === 1586) {
            return true;
        }

        $sqlState = is_array($errorInfo) ? (string) ($errorInfo[0] ?? '') : '';
        if ($sqlState === '23505') {
            return true;
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed');
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
     * mirrors {@see MediaArchiveIngestPipeline::generateUuid()} so the
     * folder ids and the asset ids share the same canonical format.
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
