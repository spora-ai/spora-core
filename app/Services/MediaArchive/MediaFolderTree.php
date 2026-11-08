<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Spora\Models\MediaFolder;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;

/**
 * The folder tree's shape, and the writes that keep it.
 *
 * Split out of {@see MediaFolderService} so that service stays under the
 * Sonar S1448 20-method cap, the same reason the ingest pipeline lives in
 * {@see MediaArchiveIngestPipeline}.
 *
 * Three concerns, one because they are the tree's:
 *
 *   - the subtree of a folder, and the paths each descendant takes under
 *     a new root path;
 *   - the rewrite that keeps a subtree's paths in step with its root's —
 *     a folder and its descendants are rewritten as one unit, so a write
 *     that fails half way through cannot leave a subtree whose paths no
 *     longer follow from its root;
 *   - the write of a folder row, where the driver's unique-constraint
 *     violation is read as the domain error it means, so a caller never
 *     sees a raw database exception.
 *
 * The unique-constraint reading is public because an asset filed into a
 * folder lands on a unique index too, and {@see MediaFolderService} needs
 * the same reading for it.
 */
final class MediaFolderTree
{
    public function __construct(
        private readonly MediaFolderPaths $paths = new MediaFolderPaths(),
    ) {}

    /**
     * The folder's descendants and the path each one takes under
     * `$rootPath`.
     *
     * A folder id seen twice means the persisted tree holds a cycle. The
     * walk stops there: following it would never end, and every hop would
     * lengthen the path strings.
     *
     * @return array<string, array{folder: MediaFolder, path: string}>
     */
    public function descendantsWithPaths(MediaFolder $root, string $rootPath): array
    {
        $all = MediaFolder::query()
            ->where('principal_id', $root->principal_id)
            ->get();

        $byParent = [];
        foreach ($all as $folder) {
            $byParent[(string) $folder->parent_id][] = $folder;
        }

        $descendants = [];
        $visited = [(string) $root->id => true];
        $stack = [[(string) $root->id, $rootPath]];
        while ($stack !== []) {
            [$parentId, $parentPath] = array_pop($stack);
            foreach ($byParent[$parentId] ?? [] as $child) {
                $childId = (string) $child->id;
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $childPath = $parentPath . MediaFolderPaths::SEPARATOR . (string) $child->name;
                $descendants[$childId] = [
                    'folder' => $child,
                    'path'   => $childPath,
                ];
                $stack[] = [$childId, $childPath];
            }
        }

        return $descendants;
    }

    /**
     * The descendants' paths are recomputed from their names under the
     * new root path rather than patched, so a rename or a move can never
     * leave a stale segment behind.
     *
     * @throws MediaFolderUniquenessException
     */
    public function repath(MediaFolder $folder, string $path, string $name): void
    {
        // A folder and its descendants are rewritten as one unit: a write
        // that fails half way through would leave a subtree whose paths
        // no longer follow from its root.
        Capsule::connection()->transaction(function () use ($folder, $path, $name): void {
            $principalId = (int) $folder->principal_id;
            $descendants = $this->descendantsWithPaths($folder, $path);
            $exceptIds = array_merge([(string) $folder->id], array_keys($descendants));

            $this->paths->assertFree($principalId, $path, $exceptIds);
            foreach ($descendants as $descendant) {
                $this->paths->assertFree($principalId, $descendant['path'], $exceptIds);
            }

            $folder->name = $name;
            $folder->path = $path;
            $this->save($folder);

            foreach ($descendants as $descendant) {
                $descendant['folder']->path = $descendant['path'];
                $this->save($descendant['folder']);
            }
        });
    }

    /**
     * Lock the principal's folders for the rest of the transaction.
     *
     * Two overlapping moves otherwise both validate against the same
     * pre-move state — A under B and B under A each pass the "not inside
     * itself" check — and both save, leaving a cycle the traversal above
     * would then follow forever. The lock makes the second move wait for
     * the first to commit, so its check reads the committed tree.
     */
    public function lockHierarchy(int $principalId): void
    {
        MediaFolder::query()
            ->where('principal_id', $principalId)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Write a folder row, with the driver's unique-constraint violation
     * read as the domain error it means.
     *
     * @throws MediaFolderUniquenessException
     */
    public function save(MediaFolder $folder): void
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
    public function isUniqueViolation(QueryException $e): bool
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
}
