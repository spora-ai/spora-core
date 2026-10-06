<?php

declare(strict_types=1);

use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Models\MediaAsset;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\DerivativePayloadStore;

/**
 * `data_url` is the BLOB column; `local` is a file under
 * `storage/assets/<token>.<ext>`. The `local` layout has to match
 * `LocalAssetStore`'s, or a derivative written here cannot be read back
 * by the original's reader.
 */
function payloadStore(): DerivativePayloadStore
{
    return new DerivativePayloadStore(BASE_PATH);
}

function payloadAsset(
    string $mode,
    ?string $token = 'tok',
    ?string $mime = 'text/markdown',
    ?string $filename = null,
): MediaAsset {
    $asset = new MediaAsset();
    $asset->storage_mode = $mode;
    $asset->asset_token  = $token;
    $asset->mime_type    = $mime;
    $asset->filename     = $filename;
    $asset->payload      = null;

    return $asset;
}

test('read() returns the BLOB payload in data_url mode', function (): void {
    $asset = payloadAsset('data_url');
    $asset->payload = "# Heading\n\nbody";

    expect(payloadStore()->read($asset))->toBe("# Heading\n\nbody");
});

test('read() returns empty when a data_url payload is not a string', function (): void {
    expect(payloadStore()->read(payloadAsset('data_url')))->toBe('');
});

test('rewrite() replaces the BLOB in place without changing the token', function (): void {
    $asset = payloadAsset('data_url', 'tok-stable');
    $asset->payload = 'v1';

    payloadStore()->rewrite($asset, 'v2');

    expect($asset->payload)->toBe('v2')
        ->and($asset->asset_token)->toBe('tok-stable');
});

test('remove() leaves a data_url row alone, the row delete drops the column', function (): void {
    $asset = payloadAsset('data_url');
    $asset->payload = 'still here';

    payloadStore()->remove($asset);

    expect($asset->payload)->toBe('still here');
});

test('read() and rewrite() round-trip bytes through a local file', function (): void {
    $token = 'payloadstore-' . bin2hex(random_bytes(4));
    $asset = payloadAsset('local', $token);
    $path  = payloadStore()->pathFor($asset);
    expect($path)->not->toBeNull();
    // `LocalAssetStore::store()` owns creating this directory; the rewrite
    // path only ever runs against a row that already has a file.
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    file_put_contents($path, 'v1');

    expect(payloadStore()->read($asset))->toBe('v1');

    payloadStore()->rewrite($asset, 'v2 longer');
    expect(file_get_contents($path))->toBe('v2 longer');

    payloadStore()->remove($asset);
    expect(file_exists($path))->toBeFalse();
});

test('pathFor() places a local asset under storage/assets with its extension', function (): void {
    $path = payloadStore()->pathFor(payloadAsset('local', 'tok-abc', 'text/markdown'));

    expect($path)->toEndWith('/storage/assets/tok-abc.md');
});

test('pathFor() returns null without a token, and pathFor() is local-mode only', function (): void {
    expect(payloadStore()->pathFor(payloadAsset('local', '')))->toBeNull()
        ->and(payloadStore()->pathFor(payloadAsset('local', null)))->toBeNull()
        ->and(payloadStore()->pathFor(payloadAsset('data_url', 'tok-abc')))->toBeNull();
});

test('read() returns empty for a local asset whose file is missing', function (): void {
    expect(payloadStore()->read(payloadAsset('local', 'never-written')))->toBe('');
});

test('rewrite() is a no-op for a local asset with no resolvable path', function (): void {
    $asset = payloadAsset('local', '');

    payloadStore()->rewrite($asset, 'v2');

    expect($asset->payload)->toBeNull();
});

test('remove() tolerates a missing file instead of warning', function (): void {
    payloadStore()->remove(payloadAsset('local', 'never-written'));

    expect(true)->toBeTrue();
});

/**
 * The regression this file existed to miss: every fixture above is
 * `text/markdown`, the one derivative format whose suffix happens to be
 * identical under both rules (`md` from the MIME, `md` from the filename),
 * so the writer/reader disagreement was invisible here.
 *
 * These cases use the real `LocalAssetStore` as the writer and assert on
 * the bytes, never on a literal path shape: `pathFor()` is asked where the
 * bytes landed and `read()` is asked to fetch them back. A reader that
 * re-derived the suffix from the MIME alone passes for `md` and fails for
 * `image/webp` + `thumbnail-256`.
 */
describe('local-mode agreement with the writer', function (): void {
    /**
     * A `LocalAssetStore` rooted at its own tmp dir, with the env restored
     * afterwards. Per-test ownership of the tmp dir keeps parallel runs
     * from deleting each other's files.
     *
     * @return array{0: LocalAssetStore, 1: string, 2: Closure(): void}
     */
    function agreementStore(): array
    {
        $dir = sys_get_temp_dir() . '/spora-payload-store-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, recursive: true);

        $previous = getenv('SPORA_STORAGE_DIR');
        putenv("SPORA_STORAGE_DIR={$dir}");
        $_ENV['SPORA_STORAGE_DIR']    = $dir;
        $_SERVER['SPORA_STORAGE_DIR'] = $dir;

        $store = new LocalAssetStore(
            new Paths(BASE_PATH, null),
            new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        );

        $restore = static function () use ($dir, $previous): void {
            if ($previous === false) {
                putenv('SPORA_STORAGE_DIR');
                unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
            } else {
                putenv("SPORA_STORAGE_DIR={$previous}");
                $_ENV['SPORA_STORAGE_DIR']    = $previous;
                $_SERVER['SPORA_STORAGE_DIR'] = $previous;
            }
            array_map('unlink', glob($dir . '/assets/*') ?: []);
            @rmdir($dir . '/assets');
            @rmdir($dir);
        };

        return [$store, $dir, $restore];
    }

    afterEach(function (): void {
        if (getenv('SPORA_STORAGE_DIR') !== false && str_contains((string) getenv('SPORA_STORAGE_DIR'), '/spora-payload-store-')) {
            putenv('SPORA_STORAGE_DIR');
            unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
        }
    });

    /**
     * Every (mime, format) pair whose two candidate suffixes differ, i.e.
     * every one that used to 404. `md` is the control: the pair that always
     * agreed, kept so a "fix" that simply stops using the filename cannot
     * pass.
     */
    $cases = [
        'the exact regression: webp bytes named by a thumbnail-256 derivative' => ['image/webp', 'thumbnail-256'],
        'a format-jpeg derivative'                                           => ['image/jpeg', 'format-jpeg'],
        'a format-png derivative'                                            => ['image/png', 'format-png'],
        'the md control that always agreed'                                  => ['text/markdown', 'md'],
    ];

    it('round-trips the bytes the writer stored for a non-markdown derivative', function (string $mime, string $format): void {
        [$store, $dir, $restore] = agreementStore();
        try {
            $bytes = 'RIFF....WEBP-' . $format;
            $filename = 'holiday.' . $format;

            $ref = $store->store($bytes, mime: $mime, filename: $filename);
            expect($ref->token)->not->toBeNull();

            // The row exactly as `MediaDerivativeService::createNew()` writes it.
            $derivative = payloadAsset('local', $ref->token, $mime, $filename);

            $path = payloadStore()->pathFor($derivative);

            // pathFor() must name the file the writer actually produced.
            expect($path)->toBe($dir . '/assets/' . $ref->token . '.' . pathinfo($filename, PATHINFO_EXTENSION));
            expect(is_file($path))->toBeTrue();
            expect(file_get_contents($path))->toBe($bytes);

            // ... and the reader must fetch those same bytes back.
            expect(payloadStore()->read($derivative))->toBe($bytes);
        } finally {
            $restore();
        }
    })->with($cases);

    it('resolves the same path after a rewrite, leaving no second file behind', function (): void {
        [$store, $dir, $restore] = agreementStore();
        try {
            $ref = $store->store('FIRST', mime: 'image/webp', filename: 'holiday.thumbnail-256');
            $derivative = payloadAsset('local', $ref->token, 'image/webp', 'holiday.thumbnail-256');
            $before = payloadStore()->pathFor($derivative);

            payloadStore()->rewrite($derivative, 'SECOND');

            expect(payloadStore()->pathFor($derivative))->toBe($before);
            expect(payloadStore()->read($derivative))->toBe('SECOND');
            expect(glob($dir . '/assets/*') ?: [])->toHaveCount(1);

            payloadStore()->remove($derivative);
            expect(glob($dir . '/assets/*') ?: [])->toHaveCount(0);
        } finally {
            $restore();
        }
    });

    it('falls back to the MIME suffix when the row carries no filename', function (): void {
        [$store, $dir, $restore] = agreementStore();
        try {
            $ref = $store->store('bytes', mime: 'image/webp');

            $derivative = payloadAsset('local', $ref->token, 'image/webp', null);

            expect(payloadStore()->pathFor($derivative))
                ->toBe($dir . '/assets/' . $ref->token . '.webp');
            expect(payloadStore()->read($derivative))->toBe('bytes');
        } finally {
            $restore();
        }
    });
});
