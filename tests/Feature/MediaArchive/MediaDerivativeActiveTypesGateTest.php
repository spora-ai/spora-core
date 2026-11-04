<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Config;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\Exceptions\DerivativeMimeNotAllowedException;
use Spora\Services\MediaArchive\MediaActiveTypesPolicy;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Tests\Support\MediaArchiveTestSupport;
use Tests\Support\SvgDerivativeProducer;

/**
 * The derivative path's share of the allowlist.
 *
 * The upload gate refused `image/svg+xml`, but the derivative path never
 * consulted it: `MediaDerivativeService::createNew()` wrote the producer's
 * MIME straight onto the row. A producer emitting SVG therefore created
 * exactly the asset the upload gate refused, and it was served inline as
 * `Content-Type: image/svg+xml` — script execution in the operator's
 * origin.
 *
 * A rule enforced at one choke point cannot cover a path that does not pass
 * through it. These tests pin the second choke point.
 */
beforeEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(SvgDerivativeProducer::class);
});

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

test('a producer emitting SVG does not create a row while active types are off', function (): void {
    $service = buildSvgDerivativeService(activeTypes: false);
    $parent = seedSvgParentAsset();

    expect(fn() => $service->createFromRequest($parent, 'svg'))
        ->toThrow(DerivativeMimeNotAllowedException::class);

    expect(MediaAsset::query()->where('mime_type', 'image/svg+xml')->count())->toBe(0);
    // The gate runs before the bytes are written, so a refused derivative
    // leaves nothing behind — not a row, and not an orphan payload.
    expect(Capsule::table('media_derivatives')->count())->toBe(0);
});

test('a producer emitting SVG creates a row once active types are on', function (): void {
    $service = buildSvgDerivativeService(activeTypes: true);
    $parent = seedSvgParentAsset();

    $derivative = $service->createFromRequest($parent, 'svg');

    expect($derivative->mime_type)->toBe('image/svg+xml');
    expect(MediaAsset::query()->where('mime_type', 'image/svg+xml')->count())->toBe(1);
});

test('a producer emitting an ordinary format is unaffected by the toggle', function (): void {
    // The gate is narrow on purpose. Producers are first-party or
    // operator-installed code whose other output types — a PDF, a PNG
    // thumbnail — the archive has always accepted.
    MediaDerivativeProducerDiscovery::reset();
    MediaDerivativeProducerDiscovery::add(\Tests\Support\MarkdownDerivativeProducer::class);

    $service = buildSvgDerivativeService(activeTypes: false);
    $parent = seedSvgParentAsset();

    $derivative = $service->createFromRequest($parent, 'md');

    expect($derivative->mime_type)->toBe('text/markdown');
});

/**
 * @return MediaDerivativeService
 */
function buildSvgDerivativeService(bool $activeTypes): MediaDerivativeService
{
    $store = new \Spora\Services\AutoAssetStore(
        new \Spora\Services\DatabaseAssetStore(50 * 1024 * 1024),
        new \Spora\Services\LocalAssetStore(
            new \Spora\Core\Paths(BASE_PATH),
            new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        ),
        1_048_576,
    );

    $policy = new MediaActiveTypesPolicy(new Config(
        ['SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES' => $activeTypes],
        ['SPORA_MEDIA_ARCHIVE_ALLOW_ACTIVE_TYPES' => Config::SOURCE_ENV],
    ));

    return MediaArchiveTestSupport::buildDerivativeService($store, null, $policy);
}

function seedSvgParentAsset(): MediaAsset
{
    return seedParentAsset('text/x-typst', 'source.typ');
}

function seedParentAsset(string $mime, string $filename): MediaAsset
{
    $userId = bootAuthLayer()->register(
        sprintf('svg-gate-%s@example.com', bin2hex(random_bytes(4))),
        'Password1!',
        'Lc',
    );

    $service = MediaArchiveTestSupport::buildService(
        new \Spora\Services\AutoAssetStore(
            new \Spora\Services\DatabaseAssetStore(50 * 1024 * 1024),
            new \Spora\Services\LocalAssetStore(
                new \Spora\Core\Paths(BASE_PATH),
                new \Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
                50 * 1024 * 1024,
            ),
            1_048_576,
        ),
    );

    return $service->ingest(new \Spora\Services\MediaArchive\MediaIngestRequest(
        bytes: '#let x = 1',
        mime: $mime,
        filename: $filename,
        userId: $userId,
        publicAccessToken: null,
        uploadSource: 'upload',
    ));
}
