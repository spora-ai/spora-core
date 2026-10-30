<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaAssetKind;
use Spora\Services\MediaArchive\MediaFolderService;

/**
 * The `folder_id` and `kind` filters on the Media Archive listing.
 *
 * `folder_id` is a tenant boundary, not a convenience filter: a folder
 * of another principal must yield no rows rather than somebody's rows.
 * `kind` is advisory — it selects, it never refuses.
 */
function seedFilteredAsset(string $id, int $principalId, ?string $folderId, string $kind, string $filename): void
{
    Capsule::table('media_assets')->insert([
        'id'              => $id,
        'principal_id'    => $principalId,
        'folder_id'       => $folderId,
        'kind'            => $kind,
        'filename'        => $filename,
        'asset_url'       => '/api/v1/assets/' . $id . '.md',
        'storage_mode'    => 'data_url',
        'media_type'      => 'document',
        'mime_type'       => 'text/markdown',
        'asset_token'     => bin2hex(random_bytes(16)),
        'is_temporary'    => false,
        'created_at'      => date('Y-m-d H:i:s'),
        'updated_at'      => date('Y-m-d H:i:s'),
    ]);
}

function mediaArchiveServiceForFilters(): MediaArchiveService
{
    return makeMediaArchiveService()['service'];
}

it('filters the listing by folder', function (): void {
    $principalId = createUserPrincipalPublic(601);
    $service = new MediaFolderService();
    $folder = $service->create($principalId, 'Corporate');

    seedFilteredAsset('00000000-0000-4000-8000-000000000001', $principalId, (string) $folder->id, MediaAssetKind::ASSET, 'theme.yml');
    seedFilteredAsset('00000000-0000-4000-8000-000000000002', $principalId, (string) $folder->id, MediaAssetKind::TEMPLATE, 'styles.yml');
    seedFilteredAsset('00000000-0000-4000-8000-000000000003', $principalId, null, MediaAssetKind::ASSET, 'loose.md');

    $page = mediaArchiveServiceForFilters()->list(new ListMediaQuery(
        folderId: (string) $folder->id,
        principalIds: [$principalId],
    ));

    expect($page->total())->toBe(2);
});

it('yields no rows for a folder of another principal', function (): void {
    $ownerId = createUserPrincipalPublic(602);
    $otherId = createUserPrincipalPublic(603);
    $foreignFolder = (new MediaFolderService())->create($ownerId, 'Corporate');

    seedFilteredAsset('00000000-0000-4000-8000-000000000011', $ownerId, (string) $foreignFolder->id, MediaAssetKind::ASSET, 'theme.yml');

    $page = mediaArchiveServiceForFilters()->list(new ListMediaQuery(
        folderId: (string) $foreignFolder->id,
        principalIds: [$otherId],
    ));

    expect($page->total())->toBe(0);
});

it('yields no rows for a folder that does not exist', function (): void {
    $principalId = createUserPrincipalPublic(604);
    seedFilteredAsset('00000000-0000-4000-8000-000000000021', $principalId, null, MediaAssetKind::ASSET, 'loose.md');

    $page = mediaArchiveServiceForFilters()->list(new ListMediaQuery(
        folderId: '99999999-9999-4999-8999-999999999999',
        principalIds: [$principalId],
    ));

    expect($page->total())->toBe(0);
});

it('filters the listing by kind', function (): void {
    $principalId = createUserPrincipalPublic(605);
    seedFilteredAsset('00000000-0000-4000-8000-000000000031', $principalId, null, MediaAssetKind::ASSET, 'plain.md');
    seedFilteredAsset('00000000-0000-4000-8000-000000000032', $principalId, null, MediaAssetKind::TEMPLATE, 'cover.md');
    seedFilteredAsset('00000000-0000-4000-8000-000000000033', $principalId, null, MediaAssetKind::EXAMPLE, 'sample.md');

    $service = mediaArchiveServiceForFilters();

    expect($service->list(new ListMediaQuery(kind: MediaAssetKind::TEMPLATE, principalIds: [$principalId]))->total())->toBe(1);
    expect($service->list(new ListMediaQuery(kind: MediaAssetKind::EXAMPLE, principalIds: [$principalId]))->total())->toBe(1);
    expect($service->list(new ListMediaQuery(kind: MediaAssetKind::ASSET, principalIds: [$principalId]))->total())->toBe(1);
});

it('lists an asset whose kind is outside the known vocabulary when no kind filter is set', function (): void {
    // The kind is advisory: an unknown mark is stored as given and the
    // asset is still a normal asset.
    $principalId = createUserPrincipalPublic(606);
    seedFilteredAsset('00000000-0000-4000-8000-000000000041', $principalId, null, 'something-else', 'odd.md');

    $page = mediaArchiveServiceForFilters()->list(new ListMediaQuery(principalIds: [$principalId]));

    expect($page->total())->toBe(1);
});

it('applies the folder and the kind filter together', function (): void {
    $principalId = createUserPrincipalPublic(607);
    $folder = (new MediaFolderService())->create($principalId, 'Corporate');

    seedFilteredAsset('00000000-0000-4000-8000-000000000051', $principalId, (string) $folder->id, MediaAssetKind::TEMPLATE, 'cover.md');
    seedFilteredAsset('00000000-0000-4000-8000-000000000052', $principalId, (string) $folder->id, MediaAssetKind::ASSET, 'plain.md');

    $page = mediaArchiveServiceForFilters()->list(new ListMediaQuery(
        folderId: (string) $folder->id,
        kind: MediaAssetKind::TEMPLATE,
        principalIds: [$principalId],
    ));

    expect($page->total())->toBe(1);
});
