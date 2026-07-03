<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use RuntimeException;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Services\AssetTooLargeException;
use Spora\Services\AutoAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;

function tmpAssetsDir(): string
{
    $dir = sys_get_temp_dir() . '/spora-asset-store-' . bin2hex(random_bytes(4));
    if (! mkdir($dir, 0755, recursive: true) && ! is_dir($dir)) {
        throw new RuntimeException("Could not create {$dir}");
    }
    return $dir;
}

function buildLocalStore(int $maxBytes = 50 * 1024 * 1024): array
{
    $dir = tmpAssetsDir();
    // Paths::storage() respects SPORA_STORAGE_DIR ahead of the base path,
    // so we route the asset directory to a tmp dir without modifying the
    // readonly $basePath property.
    $previous = getenv('SPORA_STORAGE_DIR');
    putenv("SPORA_STORAGE_DIR={$dir}");
    $_ENV['SPORA_STORAGE_DIR']    = $dir;
    $_SERVER['SPORA_STORAGE_DIR'] = $dir;

    $paths = new Paths(BASE_PATH, null);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $store = new LocalAssetStore($paths, $security, $maxBytes);

    // restoreEnv() runs from afterEach, but store the snapshot here too so
    // explicit restores work mid-test if needed.
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

    return [$store, $dir, $restore];
}

// Cleanup is the responsibility of buildLocalStore()'s $restore closure,
// which removes exactly the tmp dir it created and restores the original
// SPORA_STORAGE_DIR snapshot. Per-test ownership keeps parallel test runs
// from deleting each other's tmp dirs (which a broad glob would do).
afterEach(function () {
    // Defensive: if a test forgot to call $restore(), still clear the
    // env so subsequent tests start from a known state. The tmp-dir glob
    // sweep is intentionally absent — see comment above.
    if (getenv('SPORA_STORAGE_DIR') !== false && str_contains((string) getenv('SPORA_STORAGE_DIR'), '/spora-asset-store-')) {
        putenv('SPORA_STORAGE_DIR');
        unset($_ENV['SPORA_STORAGE_DIR'], $_SERVER['SPORA_STORAGE_DIR']);
    }
});

test('DataUrlAssetStore returns a base64 data URI with the provided mime', function (): void {
    $store = new DataUrlAssetStore();
    $bytes = "\x00\x01\x02hello";

    $ref = $store->store($bytes, mime: 'audio/mpeg');

    expect($ref->mode)->toBe('data_url');
    expect($ref->url)->toStartWith('data:audio/mpeg;base64,');
    expect($ref->url)->toContain(base64_encode($bytes));
    expect($ref->token)->toBeNull();
});

test('DataUrlAssetStore falls back to application/octet-stream when mime is missing', function (): void {
    $store = new DataUrlAssetStore();
    $ref = $store->store('hello');

    expect($ref->url)->toStartWith('data:application/octet-stream;base64,');
});

test('DataUrlAssetStore rejects payloads above the configured max', function (): void {
    $store = new DataUrlAssetStore(maxBytes: 16);

    $store->store(str_repeat('a', 16)); // exactly at the limit — OK

    expect(static fn() => $store->store(str_repeat('a', 17)))
        ->toThrow(AssetTooLargeException::class);
});

test('LocalAssetStore writes a file and returns a local URL', function (): void {
    [$store, $dir, $restore] = buildLocalStore();
    try {
        $bytes = 'binary payload';

        $ref = $store->store($bytes, mime: 'audio/mpeg', filename: 'speech.mp3');

        expect($ref->mode)->toBe('local');
        expect($ref->token)->not->toBeNull();
        expect($ref->url)->toStartWith('/api/v1/assets/');
        expect($ref->url)->toEndWith('.mp3');
        // File must exist on disk under <SPORA_STORAGE_DIR>/assets.
        $onDisk = glob($dir . '/assets/*') ?: [];
        expect($onDisk)->toHaveCount(1);
        expect(file_get_contents($onDisk[0]))->toBe($bytes);
    } finally {
        $restore();
    }
});

test('LocalAssetStore::resolve() returns path+mime for a valid token', function (): void {
    [$store, $dir, $restore] = buildLocalStore();
    try {
        $ref = $store->store('payload', mime: 'audio/mpeg', filename: 'speech.mp3');

        $filename = basename($ref->url);
        $resolved = $store->resolve($filename);

        expect($resolved)->not->toBeNull();
        expect($resolved['mime'])->toBe('audio/mpeg');
        expect(file_get_contents($resolved['path']))->toBe('payload');
    } finally {
        $restore();
    }
});

test('LocalAssetStore::resolve() returns null for an unknown filename', function (): void {
    [$store, , $restore] = buildLocalStore();
    try {
        expect($store->resolve('garbage.mp3'))->toBeNull();
        expect($store->resolve(''))->toBeNull();
    } finally {
        $restore();
    }
});

test('LocalAssetStore::resolve() rejects path-traversal attempts', function (): void {
    [$store, , $restore] = buildLocalStore();
    try {
        // Each of these would resolve outside <storage>/assets/ if the
        // strict filename check were absent. The router URL-decodes the
        // path segment, so %2F here is already a literal `/`.
        expect($store->resolve('token.mp3/../../../config.php'))->toBeNull();
        expect($store->resolve('token.mp3\\..\\..\\config.php'))->toBeNull();
        expect($store->resolve('token.mp3' . str_repeat('/..', 20)))->toBeNull();
        expect($store->resolve('token.mp3' . "\0../config.php"))->toBeNull();
        expect($store->resolve('a' . str_repeat('x', 200) . '.mp3'))->toBeNull(); // length DoS
        expect($store->resolve(''))->toBeNull();
        // Plain non-hex content also fails the regex before the HMAC check.
        expect($store->resolve('not-hex-at-all.mp3'))->toBeNull();
    } finally {
        $restore();
    }
});

test('LocalAssetStore::resolve() returns null when the HMAC does not match', function (): void {
    [$store, $dir, $restore] = buildLocalStore();
    try {
        // Plant a file with a syntactically-valid-but-bogus token — HMAC
        // check must reject it.
        @mkdir($dir . '/assets', 0755, recursive: true);
        file_put_contents($dir . '/assets/deadbeefdeadbeefdeadbeefdeadbeef.cafe.mp3', 'forged');

        expect($store->resolve('deadbeefdeadbeefdeadbeefdeadbeef.cafe.mp3'))->toBeNull();
    } finally {
        $restore();
    }
});

test('LocalAssetStore::resolve() returns null for a token missing the random suffix', function (): void {
    [$store, , $restore] = buildLocalStore();
    try {
        // Valid HMAC prefix but no random suffix → must reject.
        $token = substr(
            hash_hmac('sha256', 'mp3|' . gmdate('Ymd'), str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            0,
            32,
        );
        expect($store->resolve("{$token}.mp3"))->toBeNull();
    } finally {
        $restore();
    }
});

test('LocalAssetStore rejects payloads above maxBytes', function (): void {
    [$store, , $restore] = buildLocalStore(maxBytes: 8);
    try {
        expect(static fn() => $store->store(str_repeat('a', 9)))
            ->toThrow(AssetTooLargeException::class);
    } finally {
        $restore();
    }
});

test('AutoAssetStore dispatches small payloads to DataUrlAssetStore', function (): void {
    [$local, , $restore] = buildLocalStore();
    try {
        $auto = new AutoAssetStore(new DataUrlAssetStore(), $local, thresholdBytes: 1024);

        $ref = $auto->store('small', mime: 'audio/mpeg');

        expect($ref->mode)->toBe('data_url');
    } finally {
        $restore();
    }
});

test('AutoAssetStore dispatches large payloads to LocalAssetStore', function (): void {
    [$local, , $restore] = buildLocalStore();
    try {
        $auto = new AutoAssetStore(new DataUrlAssetStore(), $local, thresholdBytes: 8);

        $ref = $auto->store(str_repeat('b', 64), mime: 'video/mp4');

        expect($ref->mode)->toBe('local');
        expect($ref->url)->toEndWith('.mp4');
    } finally {
        $restore();
    }
});

test('AutoAssetStore dispatches payloads exactly at the threshold to DataUrlAssetStore (inclusive)', function (): void {
    [$local, , $restore] = buildLocalStore();
    try {
        $auto = new AutoAssetStore(new DataUrlAssetStore(), $local, thresholdBytes: 4);

        $ref = $auto->store('abcd', mime: 'audio/mpeg'); // exactly 4 bytes

        expect($ref->mode)->toBe('data_url');
    } finally {
        $restore();
    }
});
