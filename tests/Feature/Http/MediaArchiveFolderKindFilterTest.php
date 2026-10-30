<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Core\Paths;
use Spora\Core\SecurityManager;
use Spora\Http\MediaArchiveController;
use Spora\Services\AutoAssetStore;
use Spora\Services\DatabaseAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaAssetKind;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;
use Tests\Support\MediaArchiveTestSupport;

defined('MEDIA_FOLDER_KIND_PASSWORD') || define('MEDIA_FOLDER_KIND_PASSWORD', 'Password1!');

/**
 * The `folder_id` and `kind` filters on `GET /api/v1/media`.
 *
 * The Media Archive panel always sends `principal_id[]`, so the filters
 * have to survive the controller's principal-scope rewrite — a DTO
 * rebuilt field by field that forgets one of them drops the filter on
 * the floor. These tests go through the controller for that reason; the
 * service-level suite (`MediaListFolderKindFilterTest`) cannot see the
 * rewrite at all.
 */
function buildFolderKindController(): array
{
    $tmp = sys_get_temp_dir() . '/spora-media-folder-kind-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0755, recursive: true);
    putenv("SPORA_STORAGE_DIR={$tmp}");
    $_ENV['SPORA_STORAGE_DIR']    = $tmp;
    $_SERVER['SPORA_STORAGE_DIR'] = $tmp;

    $paths     = new Paths(BASE_PATH);
    $security  = new SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $database  = new DatabaseAssetStore(50 * 1024 * 1024);
    $local     = new LocalAssetStore($paths, $security, 50 * 1024 * 1024);
    $assetStore = new AutoAssetStore($database, $local, 1_048_576);

    $service          = MediaArchiveTestSupport::buildService($assetStore);
    $principalResolver = new PrincipalResolver();
    $auth             = bootAuthLayer();

    return [
        new MediaArchiveController($service, $auth, new \Spora\Services\MediaArchive\MediaAssetSerializer(), $principalResolver),
        $auth,
        new PrincipalService($principalResolver),
    ];
}

/**
 * A media row owned by the principal, filed in `$folderId` and marked
 * with `$kind`.
 */
function seedFolderKindAsset(string $id, int $principalId, ?string $folderId, string $kind, string $filename): void
{
    $now = date('Y-m-d H:i:s');

    Capsule::table('media_assets')->insert([
        'id'           => $id,
        'principal_id' => $principalId,
        'folder_id'    => $folderId,
        'kind'         => $kind,
        'filename'     => $filename,
        'asset_url'    => '/api/v1/assets/' . $id . '.md',
        'storage_mode' => 'data_url',
        'media_type'   => 'document',
        'mime_type'    => 'text/markdown',
        'asset_token'  => bin2hex(random_bytes(16)),
        'is_temporary' => false,
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
}

beforeEach(function (): void {
    clearSession();
});

afterEach(function (): void {
    clearSession();
});

it('filters the listing by folder when the request scopes by principal', function (): void {
    [$controller, $auth, $principalService] = buildFolderKindController();
    $callerId = bootAuth($auth, 'mfk-folder@example.com', MEDIA_FOLDER_KIND_PASSWORD);
    $principalId = $principalService->ensureUserPrincipal($callerId)->id;
    $folder = (new MediaFolderService())->create($principalId, 'Corporate');

    seedFolderKindAsset('00000000-0000-4000-8000-00000000f001', $principalId, (string) $folder->id, MediaAssetKind::ASSET, 'theme.yml');
    seedFolderKindAsset('00000000-0000-4000-8000-00000000f002', $principalId, null, MediaAssetKind::ASSET, 'loose.md');

    simulateLoggedInSession($callerId, 'mfk-folder@example.com');
    $request = Request::create('/api/v1/media', 'GET', [
        'principal_id' => [$principalId],
        'folder_id'    => (string) $folder->id,
    ]);

    $response = $controller->index($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode((string) $response->getContent(), true);
    $ids  = array_column($body['data']['assets'], 'id');
    expect($ids)->toBe(['00000000-0000-4000-8000-00000000f001']);
    expect($body['data']['total'])->toBe(1);
});

it('filters the listing by kind when the request scopes by principal', function (): void {
    [$controller, $auth, $principalService] = buildFolderKindController();
    $callerId = bootAuth($auth, 'mfk-kind@example.com', MEDIA_FOLDER_KIND_PASSWORD);
    $principalId = $principalService->ensureUserPrincipal($callerId)->id;

    seedFolderKindAsset('00000000-0000-4000-8000-00000000f011', $principalId, null, MediaAssetKind::ASSET, 'plain.md');
    seedFolderKindAsset('00000000-0000-4000-8000-00000000f012', $principalId, null, MediaAssetKind::TEMPLATE, 'cover.md');

    simulateLoggedInSession($callerId, 'mfk-kind@example.com');
    $request = Request::create('/api/v1/media', 'GET', [
        'principal_id' => [$principalId],
        'kind'         => MediaAssetKind::TEMPLATE,
    ]);

    $response = $controller->index($request);

    expect($response->getStatusCode())->toBe(200);
    $body = json_decode((string) $response->getContent(), true);
    $ids  = array_column($body['data']['assets'], 'id');
    expect($ids)->toBe(['00000000-0000-4000-8000-00000000f012']);
    expect($body['data']['total'])->toBe(1);
});
