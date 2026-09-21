<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\MediaArchiveTestSupport;

/**
 * `media:gc --temporary` extends the orphan sweep with an age-based
 * TTL over `is_temporary=TRUE` rows. Defaults to `--older-than-hours=24`
 * so a freshly-uploaded temp row survives a full user session; older
 * rows (the ones the (user, agent) retention sweep missed, plus the
 * long-lived orphans the operator wants to clear out in bulk) are
 * reaped on the next overnight cron.
 */

function withTempGcStorageDir(string $tmp): Closure
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

function makeTempGcCommandTester(): array
{
    $tmp = sys_get_temp_dir() . '/spora-temp-gc-' . bin2hex(random_bytes(4));
    if (!mkdir($tmp, 0755, recursive: true) && !is_dir($tmp)) {
        throw new RuntimeException("Could not create {$tmp}");
    }
    $restore = withTempGcStorageDir($tmp);

    $paths = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $dataUrl = new DataUrlAssetStore(50 * 1024 * 1024);
    $local = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($dataUrl, $local, 1_048_576);

    $service = MediaArchiveTestSupport::buildService($assetStore);

    $command = new MediaArchiveGcCommand($service, $paths);
    $command->setName('media:gc');

    return [new CommandTester($command), $restore, $tmp];
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/spora-temp-gc-*') ?: [] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
});

function seedTempAsset(bool $isTemp, ?DateTimeInterface $createdAt = null): MediaAsset
{
    $asset = new MediaAsset();
    $asset->id = testGenerateUuidV4();
    $asset->user_id = 1;
    $asset->is_temporary = $isTemp;
    $asset->asset_url = '/api/v1/assets/' . $asset->id;
    $asset->storage_mode = 'local';
    if ($createdAt !== null) {
        $asset->created_at = \Illuminate\Support\Carbon::instance($createdAt);
        $asset->updated_at = $asset->created_at;
    }
    $asset->save();
    return $asset;
}

it('rejects negative --older-than-hours with FAILURE', function (): void {
    [$tester, $restore] = makeTempGcCommandTester();
    try {
        $tester->execute(['--temporary' => true, '--older-than-hours' => '-1']);

        expect($tester->getStatusCode())->toBe(Command::FAILURE);
        expect($tester->getDisplay())->toContain('--older-than-hours must be >= 0');
    } finally {
        $restore();
    }
});

it('with --temporary --older-than-hours N deletes only temp rows older than N hours', function (): void {
    [$tester, $restore] = makeTempGcCommandTester();
    try {
        // 2h-old temp row, 30min-old temp row, fresh temp row.
        $oldTemp = seedTempAsset(true, (new DateTimeImmutable())->sub(new DateInterval('PT2H')));
        $recentTemp = seedTempAsset(true, (new DateTimeImmutable())->sub(new DateInterval('PT30M')));
        $freshTemp = seedTempAsset(true, new DateTimeImmutable());

        $tester->execute(['--temporary' => true, '--older-than-hours' => '1']);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(MediaAsset::query()->count())->toBe(2);
        expect(MediaAsset::find($oldTemp->id))->toBeNull();
        expect(MediaAsset::find($recentTemp->id))->not->toBeNull();
        expect(MediaAsset::find($freshTemp->id))->not->toBeNull();
    } finally {
        $restore();
    }
});

it('defaults --older-than-hours to 24 when --temporary is set', function (): void {
    [$tester, $restore] = makeTempGcCommandTester();
    try {
        // 2h-old (within the 24h default) survives.
        $recent = seedTempAsset(true, (new DateTimeImmutable())->sub(new DateInterval('PT2H')));
        // 25h-old falls outside the default — gets reaped.
        $stale = seedTempAsset(true, (new DateTimeImmutable())->sub(new DateInterval('PT25H')));

        $tester->execute(['--temporary' => true]);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(MediaAsset::find($recent->id))->not->toBeNull();
        expect(MediaAsset::find($stale->id))->toBeNull();
    } finally {
        $restore();
    }
});

it('--temporary does NOT touch permanent rows (is_temporary=FALSE)', function (): void {
    [$tester, $restore] = makeTempGcCommandTester();
    try {
        $perm = seedTempAsset(false, (new DateTimeImmutable())->sub(new DateInterval('PT48H')));
        $temp = seedTempAsset(true, (new DateTimeImmutable())->sub(new DateInterval('PT48H')));

        $tester->execute(['--temporary' => true, '--older-than-hours' => '24']);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(MediaAsset::find($perm->id))->not->toBeNull();
        expect(MediaAsset::find($temp->id))->toBeNull();
    } finally {
        $restore();
    }
});

it('without --temporary the orphan-sweep path applies unchanged — orphan rows still reaped', function (): void {
    [$tester, $restore] = makeTempGcCommandTester();
    try {
        // A local-mode row whose on-disk file is missing gets reaped
        // by the orphan sweep regardless of `is_temporary` — the two
        // sweeps are orthogonal axes. This is the unchanged behaviour
        // the contract promises for the no-`--temporary` invocation.
        $orphan = seedTempAsset(false, (new DateTimeImmutable())->sub(new DateInterval('PT48H')));
        $orphan->asset_url = '/api/v1/assets/missing-orphan.png';
        $orphan->save();

        $tester->execute(['--max-age-days' => '1']);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS);
        expect(MediaAsset::find($orphan->id))->toBeNull();
        expect($tester->getDisplay())->toContain('1 deleted');
    } finally {
        $restore();
    }
});
