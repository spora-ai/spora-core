<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Spora\Console\Commands\MediaArchiveGcCommand;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\MediaAsset;
use Spora\Services\AutoAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\MediaArchiveTestSupport;

/**
 * End-to-end coverage for `media:gc`. Boots the same in-memory service
 * the other media-archive tests use, drives the command via Symfony's
 * {@see CommandTester}, and asserts the row-level outcomes after the
 * command exits. Mirrors the structure of {@see AssetGcCommandTest}.
 */
function withMediaGcStorageDir(string $tmp): Closure
{
    $previous = getenv('SPORA_STORAGE_DIR');
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    return static function () use ($previous): void {
        if ($previous === false) {
            putenv('SPORA_STORAGE_DIR');
            unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
        } else {
            putenv("SPORA_STORAGE_DIR={$previous}");
            $_ENV['SPORA_STORAGE_DIR']    = $previous;
            $_SERVER['SPORA_STORAGE_DIR'] = $previous;
        }
    };
}

function makeGcCommandTester(): array
{
    $tmp = sys_get_temp_dir() . '/spora-media-gc-cmd-' . bin2hex(random_bytes(4));
    if (! mkdir($tmp, 0755, recursive: true) && ! is_dir($tmp)) {
        throw new RuntimeException("Could not create {$tmp}");
    }
    $restore = withMediaGcStorageDir($tmp);

    $paths      = new Paths(BASE_PATH);
    $security   = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $dataUrl    = new DataUrlAssetStore(50 * 1024 * 1024);
    $local      = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);

    $service = MediaArchiveTestSupport::buildService($assetStore);

    $command = new MediaArchiveGcCommand($service, $paths);
    $command->setName('media:gc');

    return [new CommandTester($command), $restore, $tmp, $paths, $command];
}

const MEDIA_GC_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-media-gc-cmd-*') ?: [] as $dir) {
        if (! is_dir($dir)) {
            continue;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
});

it('rejects negative --max-age-days with FAILURE', function (): void {
    [$tester, $restore] = makeGcCommandTester();
    try {
        $tester->execute(['--max-age-days' => '-1']);

        expect($tester->getStatusCode())->toBe(Command::FAILURE);
        expect($tester->getDisplay())->toContain('--max-age-days must be >= 0');
    } finally {
        $restore();
    }
});

it('prints success and deletes nothing when the archive is empty', function (): void {
    [$tester, $restore] = makeGcCommandTester();
    try {
        $tester->execute([]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('0 deleted, 0 kept, 0 errors');
    } finally {
        $restore();
    }
});

it('keeps rows whose on-disk asset is present and counts them as kept', function (): void {
    [$tester, $restore, $tmp, $paths] = makeGcCommandTester();
    try {
        // Force a local-mode row whose asset_url points at a real file we
        // place in the assets dir the command will scan.
        $asset = new MediaAsset();
        $asset->id           = bin2hex(random_bytes(8));
        $asset->storage_mode = 'local';
        $asset->asset_url    = '/api/v1/assets/keep-token.png';
        $asset->media_type   = 'image';
        $asset->mime_type    = 'image/png';
        $asset->save();

        $assetsDir = $tmp . '/assets';
        @mkdir($assetsDir, 0755, recursive: true);
        file_put_contents($assetsDir . '/keep-token.png', 'png-bytes');

        $tester->execute(['--max-age-days' => '0']);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('0 deleted');
        expect($tester->getDisplay())->toContain('1 kept');
        expect(MediaAsset::query()->count())->toBe(1);
    } finally {
        $restore();
    }
});

it('deletes a row whose on-disk asset is missing', function (): void {
    [$tester, $restore] = makeGcCommandTester();
    try {
        $bytes = base64_decode(MEDIA_GC_PNG, strict: true);
        // Use the DataUrlAssetStore directly so the asset_url is a data: URI
        // — `isAssetOnDisk()` will skip it as "present" so the row is never
        // a candidate for deletion. To exercise the "asset missing" branch
        // we mutate the storage_mode on the row to "local" by hand.
        $service = (function () {
            $paths      = new Paths(BASE_PATH);
            $security   = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $dataUrl    = new DataUrlAssetStore(50 * 1024 * 1024);
            $local      = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
            return MediaArchiveTestSupport::buildService(new AutoAssetStore($dataUrl, $local, 1_048_576));
        })();
        $asset = $service->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));
        // Force local mode + a /api/v1/assets/... URL whose file does not exist.
        $asset->storage_mode = 'local';
        $asset->asset_url    = '/api/v1/assets/missing-token.png';
        $asset->save();

        $tester->execute(['--max-age-days' => '0']);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('1 deleted');
        expect($tester->getDisplay())->toContain('0 kept');
        expect(MediaAsset::query()->count())->toBe(0);
    } finally {
        $restore();
    }
});

it('dry-run reports would-delete without removing the row', function (): void {
    [$tester, $restore] = makeGcCommandTester();
    try {
        $service = (function () {
            $paths      = new Paths(BASE_PATH);
            $security   = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $dataUrl    = new DataUrlAssetStore(50 * 1024 * 1024);
            $local      = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
            return MediaArchiveTestSupport::buildService(new AutoAssetStore($dataUrl, $local, 1_048_576));
        })();
        $bytes = base64_decode(MEDIA_GC_PNG, strict: true);
        $asset = $service->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));
        $asset->storage_mode = 'local';
        $asset->asset_url    = '/api/v1/assets/missing-token.png';
        $asset->save();

        $tester->execute(['--max-age-days' => '0', '--dry-run' => true]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('[dry-run]');
        expect($tester->getDisplay())->toContain('would delete');
        // Row is still in the database.
        expect(MediaAsset::query()->count())->toBe(1);
    } finally {
        $restore();
    }
});

it('skips rows with a non-local storage_mode (external rows are never GC\'d)', function (): void {
    [$tester, $restore] = makeGcCommandTester();
    try {
        $service = (function () {
            $assetStore = new AutoAssetStore(
                new DataUrlAssetStore(50 * 1024 * 1024),
                new LocalAssetStore(
                    new Paths(BASE_PATH),
                    new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                    50 * 1024 * 1024,
                ),
                1_048_576,
            );
            return MediaArchiveTestSupport::buildService($assetStore, promoteExternal: false);
        })();
        $service->ingest(new MediaIngestRequest(url: 'https://cdn.example/external.png'));

        $tester->execute(['--max-age-days' => '0']);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect($tester->getDisplay())->toContain('0 deleted');
        expect($tester->getDisplay())->toContain('0 kept');
        expect(MediaAsset::query()->count())->toBe(1);
    } finally {
        $restore();
    }
});

it('honours --max-age-days and skips rows younger than the cutoff', function (): void {
    [$tester, $restore] = makeGcCommandTester();
    try {
        $service = (function () {
            $paths      = new Paths(BASE_PATH);
            $security   = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $dataUrl    = new DataUrlAssetStore(50 * 1024 * 1024);
            $local      = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
            return MediaArchiveTestSupport::buildService(new AutoAssetStore($dataUrl, $local, 1_048_576));
        })();
        $bytes = base64_decode(MEDIA_GC_PNG, strict: true);
        $asset = $service->ingest(new MediaIngestRequest(bytes: $bytes, mime: 'image/png'));
        $asset->storage_mode = 'local';
        $asset->asset_url    = '/api/v1/assets/missing-token.png';
        $asset->save();

        // 30-day cutoff, row was just created → kept.
        $tester->execute(['--max-age-days' => '30']);
        expect($tester->getDisplay())->toContain('0 deleted');

        // 0-day cutoff, row is older than now-0d is false; force created_at
        // into the past to exercise the comparison.
        $asset->created_at = \Illuminate\Support\Carbon::now()->subDays(5);
        $asset->save();

        $tester->execute(['--max-age-days' => '1']);
        expect($tester->getDisplay())->toContain('1 deleted');
        expect(MediaAsset::query()->count())->toBe(0);
    } finally {
        $restore();
    }
});
