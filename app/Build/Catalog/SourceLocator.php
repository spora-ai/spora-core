<?php

declare(strict_types=1);

namespace Spora\Build\Catalog;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Where a harvested string is written.
 *
 * The locator is the `file:line` the failure output points at, so it has
 * to be the path the operator recognises — relative to the framework root
 * — and not the absolute one the extractor happens to read.
 */
final class SourceLocator
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function appDir(): string
    {
        return $this->basePath . '/app';
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function relative(string $path): string
    {
        return str_starts_with($path, $this->basePath . '/') ? substr($path, strlen($this->basePath) + 1) : $path;
    }

    /**
     * @return list<string>
     */
    public function phpFilesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isFile() && str_ends_with($path, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
