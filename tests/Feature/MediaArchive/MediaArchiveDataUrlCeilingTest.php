<?php

declare(strict_types=1);

use Mockery as M;
use Psr\Log\NullLogger;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaArchiveException;
use Spora\Services\MediaArchive\MediaArchiveIngestPipeline;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Tests\Support\MediaArchiveTestSupport;

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
    // `MediaDerivativeProducerDiscovery` is a process-global static
    // populated by other MediaArchive tests in the same worker. Resetting
    // here pins the fixture to the documented empty-registry shape for
    // this test class — otherwise the derivative service calls
    // `$container->get(...)` on the bare Mockery container and the
    // parallel runner trips over `Mockery_…_ContainerInterface::get(),
    // but no expectations were specified`. None of these fixtures is a
    // binary document, so no producer would be consulted anyway.
    MediaDerivativeProducerDiscovery::reset();
    $derivatives = MediaArchiveTestSupport::buildDerivativeService($store, $logger);
    $pipeline = new MediaArchiveIngestPipeline(
        new MediaIngestDecoder(),
        $resolver,
        $sniffer,
        new MetadataExtractor($logger, false),
        $store,
        $derivatives,
        new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver()),
    );
    return new MediaArchiveService($pipeline, $derivatives);
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

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});
