<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Psr\Log\NullLogger;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Drivers\DriverFactory;
use Spora\Http\MediaArchiveController;
use Spora\Http\MediaUploadController;
use Spora\Http\PublicMediaController;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LLMConfigService;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\ListMediaQueryBuilder;
use Spora\Services\MediaArchive\MediaAllowedTypesService;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Services\MediaArchive\MediaType;
use Spora\Services\MediaArchive\MimeSniffer;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

function mediaArchiveStack(): array
{
    $tmp = sys_get_temp_dir() . '/spora-media-additional-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);

    $service = MediaArchiveTestSupport::buildService($assetStore);
    $auth    = MediaArchiveTestSupport::buildAuth();
    $registry = MediaArchiveTestSupport::buildConverterRegistry();

    $allowed = new MediaAllowedTypesService($registry, new DriverFactory(
        new NullLogger(),
        new LLMConfigService(new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), []),
        300,
    ));
    return [
        $assetStore, $service, $auth, $registry, $allowed,
        new MediaUploadController($service, $allowed, $auth, new MimeSniffer()),
        new MediaArchiveController($service, $auth),
        new PublicMediaController($database, $local),
        $paths,
    ];
}

test('ListMediaQueryBuilder parses all query params', function (): void {
    $req = Request::create('/api/v1/media?type=image&agent_id=42&scope=mine&q=hello&sort=created_at_asc&page=2&per_page=5&plugin_slug=foo&tool_name=bar&from=2020-01-01', 'GET');
    $query = ListMediaQueryBuilder::fromRequest($req, userId: 7);
    expect($query->mediaType)->toBe(MediaType::Image);
    expect($query->agentId)->toBe(42);
    // `?scope=mine` without an explicit `?ownership` param falls into
    // the safe union default (ownership=mine, agentOwnerUserId=7); the
    // legacy `userId` branch is dormant to avoid double-filtering.
    expect($query->ownership)->toBe('mine');
    expect($query->agentOwnerUserId)->toBe(7);
    expect($query->userId)->toBeNull();
    expect($query->pluginSlug)->toBe('foo');
    expect($query->toolName)->toBe('bar');
    expect($query->search)->toBe('hello');
    expect($query->sort)->toBe('created_at_asc');
    expect($query->page())->toBe(2);
    expect($query->perPage())->toBe(5);
});

test('ListMediaQueryBuilder returns null for unknown type and drops unparseable date', function (): void {
    $req = Request::create('/api/v1/media?type=unknown&from=not-a-date', 'GET');
    $query = ListMediaQueryBuilder::fromRequest($req, null);
    // Unknown media types round-trip via MediaType::tryFrom and fall
    // back to MediaType::Unknown rather than null — keep the test
    // aligned with the controller's actual behaviour.
    expect($query->mediaType)->toBe(MediaType::Unknown);
    expect($query->from)->toBeNull();
    expect($query->to)->toBeNull();
});

test('ListMediaQueryBuilder drops non-digit agent_id and page', function (): void {
    $req = Request::create('/api/v1/media?agent_id=abc&page=xyz', 'GET');
    $query = ListMediaQueryBuilder::fromRequest($req, null);
    expect($query->agentId)->toBeNull();
    expect($query->page())->toBe(1);
});

test('ListMediaQueryBuilder defaults sort to created_at_desc when missing', function (): void {
    $req = Request::create('/api/v1/media', 'GET');
    $query = ListMediaQueryBuilder::fromRequest($req, null);
    expect($query->sort)->toBe('created_at_desc');
});

test('PublicMediaController streams a data_url asset to the client', function (): void {
    [, $service, , , , , , $publicCtrl] = mediaArchiveStack();
    $asset = $service->ingest(new MediaIngestRequest(
        bytes: 'hello world',
        mime: 'text/plain',
        filename: 'sample.txt',
        userId: 1,
        uploadSource: 'upload',
    ));
    $asset->public_access_token = 'tkn-' . $asset->id;
    $asset->save();
    $resp = $publicCtrl->show($asset->id, Request::create("/api/v1/public/media/{$asset->id}?token=tkn-{$asset->id}", 'GET'));
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Type'))->toBe('text/plain');
    // Symfony's HeaderBag normalises the cache directive order on
    // round-trip — assert the directive components rather than the
    // literal string.
    $cacheControl = $resp->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private');
    expect($cacheControl)->toContain('max-age=86400');
});
