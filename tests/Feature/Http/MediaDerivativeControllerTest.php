<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Spora\Auth\AuthService;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaDerivativeController;
use Spora\Models\MediaAsset;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetSerializer;
use Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery;
use Spora\Services\MediaArchive\MediaDerivativeService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeDerivativeProducer;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaDerivativeProducerDiscovery::reset();
});

/**
 * @return array{0: MediaDerivativeController, 1: MediaArchiveService, 2: AuthService}
 */
function buildDerivativeControllerFixture(int $userId = 42, bool $isAdmin = false): array
{
    $tmp = sys_get_temp_dir() . '/spora-deriv-ctrl-' . bin2hex(random_bytes(4));
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
    $serializer = new MediaAssetSerializer(true, $derivatives);
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
    $controller = new MediaDerivativeController($derivatives, $auth, $serializer);

    return [$controller, $service, $auth];
}

function seedDerivativeParentForController(?int $userId = null): MediaAsset
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

test('POST /media/{id}/derivatives returns 201 + serialized derivative for a supported pair', function (): void {
    [$controller] = buildDerivativeControllerFixture();
    $parent = seedDerivativeParentForController(42);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $req = Request::create(
        "/api/v1/media/{$parent->id}/derivatives",
        'POST',
        content: json_encode(['format' => 'pdf', 'options' => []]),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->create($parent->id, $req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_CREATED);
    $body = json_decode($resp->getContent(), true);
    expect($body['data']['derivative'])->not->toBeNull();
    expect($body['data']['derivative']['mime_type'])->toBe('application/pdf');
});

test('POST /media/{id}/derivatives is idempotent on the natural key', function (): void {
    [$controller] = buildDerivativeControllerFixture();
    $parent = seedDerivativeParentForController(42);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $req = Request::create(
        "/api/v1/media/{$parent->id}/derivatives",
        'POST',
        content: json_encode(['format' => 'pdf']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $first  = $controller->create($parent->id, $req);
    $firstId = json_decode($first->getContent(), true)['data']['derivative']['id'];

    $second = $controller->create($parent->id, $req);
    $secondId = json_decode($second->getContent(), true)['data']['derivative']['id'];

    expect($secondId)->toBe($firstId);
});

test('POST /media/{id}/derivatives returns 409 when no producer supports the format', function (): void {
    [$controller] = buildDerivativeControllerFixture();
    $parent = seedDerivativeParentForController(42);
    // No producers registered.

    $req = Request::create(
        "/api/v1/media/{$parent->id}/derivatives",
        'POST',
        content: json_encode(['format' => 'pdf']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->create($parent->id, $req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_CONFLICT);
});

test('POST /media/{id}/derivatives returns 422 when the producer throws', function (): void {
    [$controller] = buildDerivativeControllerFixture();
    $parent = seedDerivativeParentForController(42);
    MediaDerivativeProducerDiscovery::add(\Tests\Support\ThrowingDerivativeProducer::class);

    $req = Request::create(
        "/api/v1/media/{$parent->id}/derivatives",
        'POST',
        content: json_encode(['format' => 'pdf']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->create($parent->id, $req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('POST /media/{id}/derivatives returns 404 when the parent is not visible to the caller', function (): void {
    // Non-admin, non-owner caller — canSee() returns false.
    [$controller] = buildDerivativeControllerFixture(userId: 1, isAdmin: false);
    $parent = seedDerivativeParentForController(userId: 99);
    MediaDerivativeProducerDiscovery::add(FakeDerivativeProducer::class);

    $req = Request::create(
        "/api/v1/media/{$parent->id}/derivatives",
        'POST',
        content: json_encode(['format' => 'pdf']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->create($parent->id, $req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

test('POST /media/{id}/derivatives returns 404 when the parent does not exist', function (): void {
    [$controller] = buildDerivativeControllerFixture();
    $req = Request::create(
        '/api/v1/media/00000000-0000-0000-0000-000000000000/derivatives',
        'POST',
        content: json_encode(['format' => 'pdf']),
    );
    $req->headers->set('Content-Type', 'application/json');
    $resp = $controller->create('00000000-0000-0000-0000-000000000000', $req);

    expect($resp->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});
