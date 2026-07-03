<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Spora\Console\Commands\AssetGcCommand;
use Spora\Core\Paths;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives the `assets:gc` command end-to-end via the official Symfony
 * CommandTester. Verifies the happy paths (delete / keep / dry-run) and
 * the failure paths (no asset dir / max-age validation).
 */
function makeAssetGcCommand(): array
{
    $tmp = sys_get_temp_dir() . '/spora-asset-gc-' . bin2hex(random_bytes(4));
    if (! mkdir($tmp, 0755, recursive: true) && ! is_dir($tmp)) {
        throw new RuntimeException("Could not create {$tmp}");
    }
    // Paths::storage() reads SPORA_STORAGE_DIR before falling back to the
    // base path, so we route everything through the env var.
    $previous = getenv('SPORA_STORAGE_DIR');
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $assetsDir = $tmp . '/assets';
    @mkdir($assetsDir, 0755, recursive: true);

    $cmd   = new AssetGcCommand(new Paths(BASE_PATH));
    $tester = new CommandTester($cmd);

    $restore = static function () use ($previous): void {
        if ($previous === false) {
            putenv('SPORA_STORAGE_DIR');
            unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
        } else {
            putenv("SPORA_STORAGE_DIR={$previous}");
            $_ENV['SPORA_STORAGE_DIR']    = $previous;
            $_SERVER['SPORA_STORAGE_DIR'] = $previous;
        }
    };

    return [$tester, $assetsDir, $restore];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/spora-asset-gc-*') ?: [] as $tmp) {
        if (! is_dir($tmp)) {
            continue;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($tmp);
    }
    if (getenv('SPORA_STORAGE_DIR') !== false && str_contains((string) getenv('SPORA_STORAGE_DIR'), '/spora-asset-gc-')) {
        putenv('SPORA_STORAGE_DIR');
        unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
    }
});

test('assets:gc prints a friendly message when the asset dir does not exist', function (): void {
    $tmp = sys_get_temp_dir() . '/spora-asset-gc-empty-' . bin2hex(random_bytes(4));
    if (! mkdir($tmp, 0755, recursive: true) && ! is_dir($tmp)) {
        throw new RuntimeException("Could not create {$tmp}");
    }
    $previous = getenv('SPORA_STORAGE_DIR');
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    try {
        $cmd = new AssetGcCommand(new Paths(BASE_PATH));
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(0);
        expect($tester->getDisplay())->toContain('No asset directory');
    } finally {
        putenv('SPORA_STORAGE_DIR');
        unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
    }
});

test('assets:gc rejects negative max-age-days', function (): void {
    [$tester, , $restore] = makeAssetGcCommand();
    try {
        $tester->execute(['--max-age-days' => '-1']);
        expect($tester->getStatusCode())->toBeGreaterThan(0);
        expect($tester->getDisplay())->toContain('--max-age-days must be >= 0');
    } finally {
        $restore();
    }
});

test('assets:gc keeps recent files and deletes old ones', function (): void {
    [$tester, $assetsDir, $restore] = makeAssetGcCommand();
    try {
        // Create one "old" file (mtime set to 30 days ago) and one "recent" file.
        $oldFile    = $assetsDir . '/old.mp3';
        $recentFile = $assetsDir . '/recent.mp3';
        file_put_contents($oldFile, 'old');
        file_put_contents($recentFile, 'recent');

        $thirtyDaysAgo = time() - (30 * 86400);
        touch($oldFile, $thirtyDaysAgo);
        // recentFile stays at the current mtime (just created).

        $tester->execute(['--max-age-days' => '7']);
        expect($tester->getStatusCode())->toBe(0);
        expect(file_exists($oldFile))->toBeFalse();   // deleted
        expect(file_exists($recentFile))->toBeTrue(); // kept
        expect($tester->getDisplay())->toContain('1 deleted');
        expect($tester->getDisplay())->toContain('1 kept');
    } finally {
        $restore();
    }
});

test('assets:gc --dry-run lists files but does not delete', function (): void {
    [$tester, $assetsDir, $restore] = makeAssetGcCommand();
    try {
        $oldFile = $assetsDir . '/old.mp3';
        file_put_contents($oldFile, 'old');
        touch($oldFile, time() - (30 * 86400));

        $tester->execute(['--max-age-days' => '7', '--dry-run' => true]);
        expect($tester->getStatusCode())->toBe(0);
        expect(file_exists($oldFile))->toBeTrue(); // still there in dry-run
        expect($tester->getDisplay())->toContain('[dry-run]');
        expect($tester->getDisplay())->toContain('would delete');
    } finally {
        $restore();
    }
});

test('assets:gc counts subdirectory entries as kept (not deleted)', function (): void {
    [$tester, $assetsDir, $restore] = makeAssetGcCommand();
    try {
        // The iterator yields subdirectories first; they should be
        // skipped (no recursion, no deletion).
        mkdir($assetsDir . '/lost+found', 0755, recursive: true);
        file_put_contents($assetsDir . '/lost+found/file.txt', 'orphaned');

        $tester->execute(['--max-age-days' => '0']);
        expect($tester->getStatusCode())->toBe(0);
        expect(is_dir($assetsDir . '/lost+found'))->toBeTrue();
        expect(file_exists($assetsDir . '/lost+found/file.txt'))->toBeTrue();
    } finally {
        $restore();
    }
});
