<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Models\MediaFolder;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderValidationException;
use Spora\Services\Text\Utf8Sanitizer;

/**
 * The rules for a folder's name and path.
 *
 * A name is one path segment and a path is the name under the parent, so
 * the two are decided together: the path is the column the sibling-name
 * uniqueness index is on, and a name that is not one segment would make
 * the path ambiguous.
 */
final class MediaFolderPaths
{
    /** The separator between a path's segments. */
    public const SEPARATOR = '/';

    /** The longest a folder name may be, matching the column width. */
    private const NAME_MAX_LENGTH = 255;

    /** The longest a folder path may be, matching the column width. */
    private const PATH_MAX_LENGTH = 512;

    /**
     * @throws MediaFolderValidationException When the name is not a usable folder name.
     */
    public function assertName(string $name): string
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
        if (str_contains($name, self::SEPARATOR)) {
            throw new MediaFolderValidationException(
                'A folder name may not contain a "/" — it is one path segment, not a path.',
            );
        }

        return $name;
    }

    /**
     * @throws MediaFolderValidationException When the path would be too long.
     */
    public function pathFor(?MediaFolder $parent, string $name): string
    {
        $prefix = $parent !== null ? (string) $parent->path : '';
        $path = $prefix . self::SEPARATOR . $name;

        if (mb_strlen($path) > self::PATH_MAX_LENGTH) {
            throw new MediaFolderValidationException(sprintf(
                'The folder path may not exceed %d characters.',
                self::PATH_MAX_LENGTH,
            ));
        }

        return $path;
    }

    /**
     * The sibling-name rule: two siblings with the same name produce the
     * same path, and the path is unique per principal.
     *
     * @param list<string> $exceptIds Folder ids whose own path does not count as a collision.
     * @throws MediaFolderUniquenessException
     */
    public function assertFree(int $principalId, string $path, array $exceptIds): void
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
}
