<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use InvalidArgumentException;
use LogicException;
use Mockery;
use Spora\Plugins\Concerns\StoresBinaryAssets;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\AssetTooLargeException;

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

    public function __construct(?AssetStore $store = null)
    {
        if ($store !== null) {
            $this->setAssetStore($store);
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
