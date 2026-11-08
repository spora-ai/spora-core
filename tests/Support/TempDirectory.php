<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * A private temporary directory that one test owns for its own lifetime.
 *
 * The name is unpredictable and the directory is owner-only, so nothing else
 * on the machine can plant anything inside it. The cleanup removes exactly
 * the path it was handed — a scan of the shared temp directory for a prefix
 * would let anything else in there decide what the test runner deletes.
 */
final class TempDirectory
{
    public static function create(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));

        if (!mkdir($dir, 0o700, true)) {
            throw new RuntimeException("Could not create the temporary directory {$dir}.");
        }

        return $dir;
    }

    /**
     * A symlink at the root is left alone: following it would delete what
     * it points at rather than what the test created.
     */
    public static function remove(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink()
                ? @rmdir($entry->getPathname())
                : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
