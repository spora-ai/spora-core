<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use InvalidArgumentException;
use LogicException;
use Mockery;
use Spora\Models\MediaAsset;
use Spora\Plugins\Concerns\StoresBinaryAssets;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\AssetTooLargeException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaType;

/**
 * Concrete host for the trait so we can exercise its protected methods
 * directly. Mocking {@see AssetStore} is cheaper than building a real one
 * because the trait's job is delegation, not store internals.
 *
 * Named class (not anonymous) so PHPStan can resolve the return type
 * across the helper methods.
 */
final class StoresBinaryAssetsTraitHost
{
    use StoresBinaryAssets;

    public function __construct(?AssetStore $store = null, ?MediaArchiveService $archive = null)
    {
        if ($store !== null) {
            $this->setAssetStore($store);
        }
        if ($archive !== null) {
            $this->setMediaArchive($archive);
        }
    }

    /** @return array{0: string, 1: string} */
    public function callEmbedHex(string $hex, string $mime, string $filename): array
    {
        return $this->embedHex($hex, $mime, $filename);
    }

    public function callAssetStore(): AssetStore
    {
        return $this->assetStore();
    }

    public function callMediaArchive(): MediaArchiveService
    {
        return $this->mediaArchive();
    }

    public function callArchiveMedia(
        string $bytes,
        string $mime,
        ?string $filename,
        MediaType $mediaType,
        array $context = [],
    ): MediaAsset {
        return $this->archiveMedia($bytes, $mime, $filename, $mediaType, $context);
    }
}

test('embedHex() decodes hex and routes through AssetStore', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $store->shouldReceive('store')
        ->once()
        ->with('AB', 'audio/mpeg', 'speech.mp3')
        ->andReturn(new AssetReference('data:audio/mpeg;base64,', 'data_url'));

    $host = new StoresBinaryAssetsTraitHost($store);

    [$url, $mode] = $host->callEmbedHex('4142', 'audio/mpeg', 'speech.mp3');

    expect($url)->toBe('data:audio/mpeg;base64,');
    expect($mode)->toBe('data_url');
});

test('embedHex() throws on odd-length hex', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $store->shouldNotReceive('store');

    $host = new StoresBinaryAssetsTraitHost($store);

    expect(static fn(): array => $host->callEmbedHex('414', 'audio/mpeg', 'speech.mp3'))
        ->toThrow(InvalidArgumentException::class, 'odd length');
});

test('embedHex() rejects an empty payload as odd-length', function (): void {
    // The trait treats `strlen % 2 !== 0` as the empty-payload guard — an
    // empty string has length 0, which is even, so the odd-length guard does
    // not fire. Instead, hex2bin('') returns "" which the empty-bytes guard
    // catches. Either way the trait refuses empty input.
    $store = Mockery::mock(AssetStore::class);
    $store->shouldNotReceive('store');
    $host = new StoresBinaryAssetsTraitHost($store);

    expect(static fn(): array => $host->callEmbedHex('', 'audio/mpeg', 'x.mp3'))
        ->toThrow(InvalidArgumentException::class);
});

test('embedHex() propagates AssetTooLargeException', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $store->shouldReceive('store')
        ->once()
        ->andThrow(new AssetTooLargeException('too big'));

    $host = new StoresBinaryAssetsTraitHost($store);

    expect(static fn(): array => $host->callEmbedHex('4142', 'audio/mpeg', 'speech.mp3'))
        ->toThrow(AssetTooLargeException::class);
});

test('assetStore() throws if not injected', function (): void {
    $host = new StoresBinaryAssetsTraitHost();

    expect(static fn(): AssetStore => $host->callAssetStore())
        ->toThrow(LogicException::class, 'AssetStore has not been injected');
});

test('assetStore() returns the injected store', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $host  = new StoresBinaryAssetsTraitHost($store);

    expect($host->callAssetStore())->toBe($store);
});

test('setAssetStore() can be re-invoked to swap the store (last write wins)', function (): void {
    $a = Mockery::mock(AssetStore::class);
    $b = Mockery::mock(AssetStore::class);

    $host = new StoresBinaryAssetsTraitHost($a);
    expect($host->callAssetStore())->toBe($a);

    $host->setAssetStore($b);
    expect($host->callAssetStore())->toBe($b);
});

test('mediaArchive() throws if not injected', function (): void {
    $host = new StoresBinaryAssetsTraitHost();

    expect(static fn(): MediaArchiveService => $host->callMediaArchive())
        ->toThrow(LogicException::class, 'MediaArchiveService has not been injected');
});

test('mediaArchive() returns the injected service', function (): void {
    // MediaArchiveService is `final`, so Mockery can't subclass it. Use a
    // real service built with stubbed dependencies instead.
    $paths    = new \Spora\Core\Paths(BASE_PATH);
    $security = new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $archive  = new MediaArchiveService(
        new \Spora\Services\AutoAssetStore(
            new \Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore($paths, $security, 50 * 1024 * 1024),
            1_048_576,
        ),
        new \Spora\Services\MediaArchive\RemoteMediaFetcher(
            new \Symfony\Component\HttpClient\MockHttpClient([]),
            new \Psr\Log\NullLogger(),
            30,
            100 * 1024 * 1024,
        ),
        new \Spora\Services\MediaArchive\MimeSniffer(),
        new \Spora\Services\MediaArchive\MetadataExtractor(new \Psr\Log\NullLogger(), false),
        new \Psr\Log\NullLogger(),
    );
    $host = new StoresBinaryAssetsTraitHost(null, $archive);

    expect($host->callMediaArchive())->toBe($archive);
});

test('setMediaArchive() can be re-invoked to swap the archive service', function (): void {
    $paths    = new \Spora\Core\Paths(BASE_PATH);
    $security = new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $a = new MediaArchiveService(
        new \Spora\Services\AutoAssetStore(
            new \Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore($paths, $security, 50 * 1024 * 1024),
            1_048_576,
        ),
        new \Spora\Services\MediaArchive\RemoteMediaFetcher(
            new \Symfony\Component\HttpClient\MockHttpClient([]),
            new \Psr\Log\NullLogger(),
            30,
            100 * 1024 * 1024,
        ),
        new \Spora\Services\MediaArchive\MimeSniffer(),
        new \Spora\Services\MediaArchive\MetadataExtractor(new \Psr\Log\NullLogger(), false),
        new \Psr\Log\NullLogger(),
    );
    $b = new MediaArchiveService(
        new \Spora\Services\AutoAssetStore(
            new \Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore($paths, $security, 50 * 1024 * 1024),
            1_048_576,
        ),
        new \Spora\Services\MediaArchive\RemoteMediaFetcher(
            new \Symfony\Component\HttpClient\MockHttpClient([]),
            new \Psr\Log\NullLogger(),
            30,
            100 * 1024 * 1024,
        ),
        new \Spora\Services\MediaArchive\MimeSniffer(),
        new \Spora\Services\MediaArchive\MetadataExtractor(new \Psr\Log\NullLogger(), false),
        new \Psr\Log\NullLogger(),
    );

    $host = new StoresBinaryAssetsTraitHost(null, $a);
    expect($host->callMediaArchive())->toBe($a);

    $host->setMediaArchive($b);
    expect($host->callMediaArchive())->toBe($b);
});

test('archiveMedia() builds a MediaIngestRequest and delegates to the archive service', function (): void {
    $paths    = new \Spora\Core\Paths(BASE_PATH);
    $security = new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $service  = new MediaArchiveService(
        new \Spora\Services\AutoAssetStore(
            new \Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore($paths, $security, 50 * 1024 * 1024),
            1_048_576,
        ),
        new \Spora\Services\MediaArchive\RemoteMediaFetcher(
            new \Symfony\Component\HttpClient\MockHttpClient([]),
            new \Psr\Log\NullLogger(),
            30,
            100 * 1024 * 1024,
        ),
        new \Spora\Services\MediaArchive\MimeSniffer(),
        new \Spora\Services\MediaArchive\MetadataExtractor(new \Psr\Log\NullLogger(), false),
        new \Psr\Log\NullLogger(),
    );

    $host = new StoresBinaryAssetsTraitHost(null, $service);
    // No agent_id (FK to agents table) — pass everything else.
    $asset = $host->callArchiveMedia('PNGDATA', 'image/png', 'pixel.png', MediaType::Image, [
        'pluginSlug' => 'demo',
        'toolName'   => 'tavily',
        'prompt'     => 'a pixel',
        'tags'       => ['hero'],
        'metadata'   => ['seed' => 42],
        'width'      => 64,
        'height'     => 64,
    ]);

    expect($asset)->toBeInstanceOf(MediaAsset::class);
    expect($asset->plugin_slug)->toBe('demo');
    expect($asset->tool_name)->toBe('tavily');
    expect($asset->prompt)->toBe('a pixel');
    expect($asset->width)->toBe(64);
    expect($asset->height)->toBe(64);
    expect($asset->byte_size)->toBe(7);
});

test('archiveMedia() defaults the optional context fields to null when omitted', function (): void {
    $paths    = new \Spora\Core\Paths(BASE_PATH);
    $security = new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $service  = new MediaArchiveService(
        new \Spora\Services\AutoAssetStore(
            new \Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore($paths, $security, 50 * 1024 * 1024),
            1_048_576,
        ),
        new \Spora\Services\MediaArchive\RemoteMediaFetcher(
            new \Symfony\Component\HttpClient\MockHttpClient([]),
            new \Psr\Log\NullLogger(),
            30,
            100 * 1024 * 1024,
        ),
        new \Spora\Services\MediaArchive\MimeSniffer(),
        new \Spora\Services\MediaArchive\MetadataExtractor(new \Psr\Log\NullLogger(), false),
        new \Psr\Log\NullLogger(),
    );

    $host = new StoresBinaryAssetsTraitHost(null, $service);
    $asset = $host->callArchiveMedia('XX', 'image/png', null, MediaType::Image);

    // agent_id / task_id / etc. should be null because the context array
    // was empty.
    expect($asset->agent_id)->toBeNull();
    expect($asset->task_id)->toBeNull();
    expect($asset->tool_call_id)->toBeNull();
    expect($asset->plugin_slug)->toBeNull();
    expect($asset->tool_name)->toBeNull();
    expect($asset->prompt)->toBeNull();
    expect($asset->width)->toBeNull();
    expect($asset->height)->toBeNull();
    // byteSize auto-derived from strlen($bytes).
    expect($asset->byte_size)->toBe(2);
});

test('archiveMedia() coerces non-array tags/metadata context keys to null', function (): void {
    $paths    = new \Spora\Core\Paths(BASE_PATH);
    $security = new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $service  = new MediaArchiveService(
        new \Spora\Services\AutoAssetStore(
            new \Spora\Services\DataUrlAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore($paths, $security, 50 * 1024 * 1024),
            1_048_576,
        ),
        new \Spora\Services\MediaArchive\RemoteMediaFetcher(
            new \Symfony\Component\HttpClient\MockHttpClient([]),
            new \Psr\Log\NullLogger(),
            30,
            100 * 1024 * 1024,
        ),
        new \Spora\Services\MediaArchive\MimeSniffer(),
        new \Spora\Services\MediaArchive\MetadataExtractor(new \Psr\Log\NullLogger(), false),
        new \Psr\Log\NullLogger(),
    );

    $host = new StoresBinaryAssetsTraitHost(null, $service);
    // Non-array tags/metadata context keys should be coerced to null —
    // the trait guards each access with is_array() and treats anything
    // else as "not provided".
    $asset = $host->callArchiveMedia('XX', 'image/png', null, MediaType::Image, [
        'tags'     => 'not-an-array',
        'metadata' => 'also-not-an-array',
    ]);

    expect($asset->tags)->toBeNull();
    expect($asset->metadata)->toBeNull();
});
