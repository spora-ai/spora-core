<?php

declare(strict_types=1);

use Mockery as M;
use Psr\Log\NullLogger;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaArchiveException;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * A misconfigured AssetStore ceiling above DATA_URL_MAX_BYTES would
 * still return a `data:` mode reference; the subsequent INSERT would
 * truncate with SQLSTATE 22001. The storeAsset() check rejects such
 * references up-front so the tool's catch block surfaces a clear
 * MediaArchiveException instead of a silent corruption.
 */

function mediumblobTestArchiveService(AssetStore $store): MediaArchiveService
{
    $logger  = new NullLogger();
    $sniffer = new MimeSniffer();
    $resolver = new MediaArchiveUrlResolver(
        new RemoteMediaFetcher(new MockHttpClient([]), $logger, 30, 1024 * 1024),
        $sniffer,
        $logger,
        true,
        1024 * 1024,
    );
    // `MediaConverterDiscovery` is a process-global static populated by
    // other MediaArchive tests in the same worker (e.g. registry /
    // read-url tests). Resetting here pins the fixture to the documented
    // empty-registry shape for this test class — otherwise the
    // constructor of `MediaConverterRegistry` calls `$container->get(...)`
    // on the bare Mockery container and the parallel runner trips over
    // `Mockery_…_ContainerInterface::get(), but no expectations were specified`.
    MediaConverterDiscovery::reset();
    return new MediaArchiveService(
        $store,
        $resolver,
        $sniffer,
        new MetadataExtractor($logger, false),
        new MediaConverterRegistry(M::mock(Psr\Container\ContainerInterface::class)),
        new MediaIngestDecoder(),
        $logger,
    );
}

test('data_url mode payload under DATA_URL_MAX_BYTES is accepted', function (): void {
    $bytes = str_repeat('a', 100);
    $store = M::mock(AssetStore::class);
    $store->allows('store')->andReturn(new AssetReference('data:image/png;base64,xxx', 'data_url'));

    $archive = mediumblobTestArchiveService($store);
    $request = new MediaIngestRequest(
        bytes: $bytes,
        mime: 'image/png',
        mediaType: MediaType::Image,
    );

    $asset = $archive->ingest($request);
    expect($asset->storage_mode)->toBe('data_url');
});

test('data_url mode payload above DATA_URL_MAX_BYTES throws MediaArchiveException', function (): void {
    $oversized = str_repeat('a', MediaArchiveService::DATA_URL_MAX_BYTES + 1);
    $store = M::mock(AssetStore::class);
    $store->allows('store')->andReturn(new AssetReference('data:image/png;base64,xxx', 'data_url'));

    $archive = mediumblobTestArchiveService($store);
    $request = new MediaIngestRequest(
        bytes: $oversized,
        mime: 'image/png',
        mediaType: MediaType::Image,
    );

    expect(fn() => $archive->ingest($request))
        ->toThrow(MediaArchiveException::class);
});

test('local mode payload above DATA_URL_MAX_BYTES is accepted (no DB write)', function (): void {
    // Local mode writes to disk, not the DB — the BLOB ceiling doesn't apply.
    $oversized = str_repeat('a', MediaArchiveService::DATA_URL_MAX_BYTES + 1);
    $store = M::mock(AssetStore::class);
    $store->allows('store')->andReturn(new AssetReference(
        '/api/v1/assets/abc.png',
        'local',
    ));

    $archive = mediumblobTestArchiveService($store);
    $request = new MediaIngestRequest(
        bytes: $oversized,
        mime: 'image/png',
        mediaType: MediaType::Image,
    );

    $asset = $archive->ingest($request);
    expect($asset->storage_mode)->toBe('local');
});

// `MediaConverterDiscovery::all()` is a process-global static populated by
// other MediaArchive tests in the same worker (e.g. registry / read-url
// tests). Without this guard the constructor of `MediaConverterRegistry`
// fires `$container->get($class)` on the bare Mockery container and the
// parallel runner trips over `Mockery_…_Psr_Container_ContainerInterface:
// get(), but no expectations were specified`. Resetting here pins the
// fixture to the documented empty-registry shape for this test class.
afterEach(function (): void {
    MediaConverterDiscovery::reset();
});
