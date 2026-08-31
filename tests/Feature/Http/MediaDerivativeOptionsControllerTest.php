<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaDerivativeOptionsController;
use Spora\Models\MediaAsset;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeDerivativeProducer;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

/**
 * @return array{0: MediaDerivativeOptionsController, 1: MediaArchiveService}
 */
function buildDerivativeOptionsControllerFixture(int $userId = 42, bool $isAdmin = false): array
{
    $tmp = sys_get_temp_dir() . '/spora-deriv-opts-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths    = new Paths(BASE_PATH);
    $security = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database = new DatabaseAssetStore(50 * 1024 * 1024);
    $local    = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);

    $service = MediaArchiveTestSupport::buildService($assetStore);
    $principalService = new PrincipalService(new PrincipalResolver());
    $derivatives = new MediaDerivativeService($assetStore, $principalService);
    $auth = new class ($userId, $isAdmin) extends AuthService {
        public function __construct(private readonly int $uid, private readonly bool $admin) {}
        public function currentUserId(): ?int
        {
            return $this->uid === 0 ? null : $this->uid;
        }
        public function isAdmin(): bool
        {
            return $this->admin;
        }
    };
    $controller = new MediaDerivativeOptionsController($derivatives, $auth);

    return [$controller, $service];
}

function seedOptionsParent(?int $userId = null): MediaAsset
{
    $id = sprintf(
        '%08x-aaaa-bbbb-cccc-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffffffffffff),
    );
    return MediaAsset::create([
        'id'                            => $id,
        'asset_url'                     => MediaArchiveService::OPAQUE_ASSET_URL_PREFIX . $id . '.png',
        'storage_mode'                  => 'data_url',
        'media_type'                    => 'image',
        'mime_type'                     => 'image/png',
        'byte_size'                     => 1024,
        'user_id'                       => $userId,
        'asset_token'                   => bin2hex(random_bytes(16)),
        'migrated_from_inline_data_url' => false,
    ]);
}

test('GET /media/{id}/derivatives/options returns each format with its available flag', function (): void {
    [$controller] = buildDerivativeOptionsControllerFixture();
    $parent = seedOptionsParent(42);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $resp = $controller->index($parent->id);

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data'])->toBeArray();
    // The default FakeDerivativeProducer advertises pdf from image/png
    // — exactly one candidate format, available.
    $byFormat = [];
    foreach ($body['data'] as $opt) {
        $byFormat[$opt['format']] = $opt['available'];
    }
    expect($byFormat)->toBe(['pdf' => true]);
});

test('GET /media/{id}/derivatives/options returns an empty array when no producers are registered', function (): void {
    [$controller] = buildDerivativeOptionsControllerFixture();
    $parent = seedOptionsParent(42);

    $resp = $controller->index($parent->id);

    expect($resp->getStatusCode())->toBe(Response::HTTP_OK);
    $body = json_decode($resp->getContent(), true);
    expect($body['data'])->toBe([]);
});

test('GET /media/{id}/derivatives/options returns 404 when the parent is not visible to the caller', function (): void {
    [$controller] = buildDerivativeOptionsControllerFixture(userId: 1, isAdmin: false);
    $parent = seedOptionsParent(99);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $resp = $controller->index($parent->id);

    expect($resp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

test('GET /media/{id}/derivatives/options returns 404 when the parent does not exist', function (): void {
    [$controller] = buildDerivativeOptionsControllerFixture();
    $resp = $controller->index('00000000-0000-0000-0000-000000000000');
    expect($resp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});
