<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Mockery as M;
use Psr\Log\NullLogger;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MediaIngestDecoder;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * Regression for the v0.11.0 auto-archive bug: a tool that calls
 * `MediaArchiveService::ingest()` with a URL that the resolver can fetch
 * was getting back an `external`-mode row (preserving the raw CDN URL
 * as `asset_url`) instead of the local `/api/v1/assets/<uuid>` opaque
 * URL, because downstream pipeline steps threw on synthetic test payloads
 * with new columns the test fixture didn't define.
 *
 * The fix: `MediaArchiveService::insertNew()` now probes the schema
 * before writing optional columns. Test fixtures and pre-existing
 * installations without the new columns keep working.
 */
test('URL ingest returns the local archive URL even when optional columns are missing', function (): void {
    $tmp = sys_get_temp_dir() . '/spora-regression-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");

    $capsule = new Capsule();
    $capsule->addConnection([
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    // The minimal pre-#137 schema — does NOT include user_id, filename,
    // upload_source, tags, metadata, public_access_token, asset_token.
    // The fix's Schema::hasColumn() probes must skip these so the
    // insert doesn't throw.
    $capsule->schema()->create('media_assets', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->unsignedBigInteger('agent_id')->nullable();
        $table->unsignedBigInteger('task_id')->nullable();
        $table->unsignedBigInteger('tool_call_id')->nullable();
        $table->string('plugin_slug', 64)->nullable();
        $table->string('tool_name', 64)->nullable();
        $table->string('media_type', 16)->nullable();
        $table->string('mime_type', 127)->nullable();
        $table->bigInteger('byte_size')->nullable();
        $table->unsignedInteger('width')->nullable();
        $table->unsignedInteger('height')->nullable();
        $table->decimal('duration_seconds', 8, 2)->nullable();
        $table->text('prompt')->nullable();
        $table->string('asset_url', 512);
        $table->string('source_url', 512)->nullable();
        $table->string('storage_mode', 16);
        $table->timestamps();
    });

    $logger   = new NullLogger();
    $sniffer  = new MimeSniffer();
    $meta     = new MetadataExtractor($logger, false);
    $http     = new MockHttpClient([
        new MockResponse(
            '',
            ['response_headers' => ['content-type: audio/mpeg', 'content-length: 32']],
        ),
        new MockResponse(
            str_repeat("\x00", 32),
            ['response_headers' => ['content-type: audio/mpeg']],
        ),
    ]);
    $fetcher  = new RemoteMediaFetcher($http, $logger, 30, 100 * 1024 * 1024);
    $resolver = new MediaArchiveUrlResolver($fetcher, $sniffer, $logger, true, 100 * 1024 * 1024);

    $paths    = new Paths($tmp);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $store    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $container = M::mock(\Psr\Container\ContainerInterface::class);
    $service  = new MediaArchiveService(
        $store,
        $resolver,
        $sniffer,
        $meta,
        new MediaConverterRegistry($container),
        new MediaIngestDecoder(),
        $logger,
    );

    $asset = $service->ingest(new MediaIngestRequest(
        url: 'https://cdn.example/song.mp3',
        mime: 'audio/mpeg',
        pluginSlug: 'test',
        toolName: 'regression',
    ));

    expect($asset->asset_url)->toStartWith('/api/v1/assets/')
        ->and($asset->storage_mode)->toBe('local')
        ->and($asset->source_url)->toBe('https://cdn.example/song.mp3');
});
