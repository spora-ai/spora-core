<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins;

use InvalidArgumentException;
use LogicException;
use Mockery;
use Spora\Plugins\Concerns\StoresBinaryAssets;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;

/**
 * Anonymous-class host for the trait so we can exercise its protected
 * methods directly. Mocking AssetStore is cheaper than building a real one
 * because the trait's job is delegation, not store internals.
 */
function traitHost(AssetStore $store): object
{
    return new class ($store) {
        use StoresBinaryAssets;

        public function __construct(AssetStore $store)
        {
            $this->setAssetStore($store);
        }

        public function callEmbedHex(string $hex, string $mime, string $filename): array
        {
            return $this->embedHex($hex, $mime, $filename);
        }

        public function callAssetStore(): AssetStore
        {
            return $this->assetStore();
        }
    };
}

test('embedHex() decodes hex and routes through AssetStore', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $store->shouldReceive('store')
        ->once()
        ->with('AB', 'audio/mpeg', 'speech.mp3')
        ->andReturn(new AssetReference('data:audio/mpeg;base64,', 'data_url'));

    $host = traitHost($store);

    [$url, $mode] = $host->callEmbedHex('4142', 'audio/mpeg', 'speech.mp3');

    expect($url)->toBe('data:audio/mpeg;base64,');
    expect($mode)->toBe('data_url');
});

test('embedHex() throws on odd-length hex', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $store->shouldNotReceive('store');

    $host = traitHost($store);

    expect(static fn() => $host->callEmbedHex('414', 'audio/mpeg', 'speech.mp3'))
        ->toThrow(InvalidArgumentException::class, 'odd length');
});

test('embedHex() propagates AssetStoreTooLargeException', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $store->shouldReceive('store')
        ->once()
        ->andThrow(new \Spora\Services\AssetTooLargeException('too big'));

    $host = traitHost($store);

    expect(static fn() => $host->callEmbedHex('4142', 'audio/mpeg', 'speech.mp3'))
        ->toThrow(\Spora\Services\AssetTooLargeException::class);
});

test('assetStore() throws if not injected', function (): void {
    $host = new class {
        use StoresBinaryAssets;

        public function callAssetStore(): AssetStore
        {
            return $this->assetStore();
        }
    };

    expect(static fn() => $host->callAssetStore())
        ->toThrow(LogicException::class, 'AssetStore has not been injected');
});

test('assetStore() returns the injected store', function (): void {
    $store = Mockery::mock(AssetStore::class);
    $host  = traitHost($store);

    expect($host->callAssetStore())->toBe($store);
});
