<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\PrincipalContext;
use Spora\Tools\MediaFolderHandler;

/**
 * The assistant's folder surface.
 *
 * The assistant may discover folders, shape them (create, rename,
 * delete) and file assets in them. Every operation is scoped to the
 * calling principal: a folder or an asset of another principal is
 * reported as missing rather than as forbidden.
 */
function folderHandlerFor(int $principalId): MediaFolderHandler
{
    $archive = makeMediaArchiveService()['service'];

    return new MediaFolderHandler(new MediaFolderService(), $archive);
}

function folderHandlerContext(int $principalId): PrincipalContext
{
    return new PrincipalContext($principalId, 'user', 1, 1);
}

function folderHandlerAsset(int $principalId, string $filename): MediaAsset
{
    $id = sprintf(
        '%08x-%04x-4%03x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0xfff),
        random_int(0, 0xffff),
        random_int(0, 0xffffffffffff),
    );

    $asset = new MediaAsset();
    $asset->id = $id;
    $asset->principal_id = $principalId;
    $asset->filename = $filename;
    $asset->media_type = 'document';
    $asset->mime_type = 'text/markdown';
    $asset->storage_mode = 'data_url';
    $asset->asset_url = '/api/v1/assets/' . $id . '.md';
    $asset->asset_token = bin2hex(random_bytes(16));
    $asset->save();

    return $asset;
}

describe('MediaFolderHandler::list_folders', function (): void {
    it('lists the principal\'s folders', function (): void {
        $principalId = createUserPrincipalPublic(631);
        (new MediaFolderService())->create($principalId, 'Corporate');

        $result = folderHandlerFor($principalId)->run('list_folders', $principalId);

        expect($result->success)->toBeTrue();
        expect($result->data['folders'])->toHaveCount(1);
        expect($result->data['folders'][0]['name'])->toBe('Corporate');
    });

    it('does not list another principal\'s folders', function (): void {
        $ownerId = createUserPrincipalPublic(632);
        $otherId = createUserPrincipalPublic(633);
        (new MediaFolderService())->create($ownerId, 'Corporate');

        $result = folderHandlerFor($otherId)->run('list_folders', $otherId);

        expect($result->success)->toBeTrue();
        expect($result->data['folders'])->toBe([]);
    });
});

describe('MediaFolderHandler::create_folder', function (): void {
    it('creates a folder in the principal\'s tree', function (): void {
        $principalId = createUserPrincipalPublic(700);

        $result = folderHandlerFor($principalId)->run(
            'create_folder',
            $principalId,
            ['name' => 'Corporate'],
        );

        expect($result->success)->toBeTrue();
        expect($result->data['folder']['name'])->toBe('Corporate');
        expect($result->data['folder']['path'])->toBe('/Corporate');
    });

    it('creates a folder under a parent', function (): void {
        $principalId = createUserPrincipalPublic(701);
        $parent = (new MediaFolderService())->create($principalId, 'Corporate');

        $result = folderHandlerFor($principalId)->run(
            'create_folder',
            $principalId,
            ['name' => '2026', 'parent_id' => (string) $parent->id],
        );

        expect($result->success)->toBeTrue();
        expect($result->data['folder']['path'])->toBe('/Corporate/2026');
    });

    it('refuses a sibling name that is taken', function (): void {
        $principalId = createUserPrincipalPublic(702);
        (new MediaFolderService())->create($principalId, 'Corporate');

        $result = folderHandlerFor($principalId)->run(
            'create_folder',
            $principalId,
            ['name' => 'Corporate'],
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('Corporate');
    });

    it('refuses a parent of another principal', function (): void {
        $ownerId = createUserPrincipalPublic(703);
        $otherId = createUserPrincipalPublic(704);
        $parent = (new MediaFolderService())->create($ownerId, 'Corporate');

        $result = folderHandlerFor($otherId)->run(
            'create_folder',
            $otherId,
            ['name' => '2026', 'parent_id' => (string) $parent->id],
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('parent folder does not exist');
    });

    it('needs a name', function (): void {
        $principalId = createUserPrincipalPublic(705);

        $result = folderHandlerFor($principalId)->run('create_folder', $principalId, ['name' => '  ']);

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('name is required');
    });
});

describe('MediaFolderHandler::rename_folder', function (): void {
    it('renames a folder and its path', function (): void {
        $principalId = createUserPrincipalPublic(706);
        $service = new MediaFolderService();
        $folder = $service->create($principalId, 'Corporate');

        $result = folderHandlerFor($principalId)->run(
            'rename_folder',
            $principalId,
            ['folder_id' => (string) $folder->id, 'name' => 'Company'],
        );

        expect($result->success)->toBeTrue();
        expect($result->data['folder']['path'])->toBe('/Company');
        expect($service->find($principalId, (string) $folder->id)?->name)->toBe('Company');
    });

    it('refuses a name a sibling holds', function (): void {
        $principalId = createUserPrincipalPublic(707);
        $service = new MediaFolderService();
        $service->create($principalId, 'Corporate');
        $other = $service->create($principalId, 'Templates');

        $result = folderHandlerFor($principalId)->run(
            'rename_folder',
            $principalId,
            ['folder_id' => (string) $other->id, 'name' => 'Corporate'],
        );

        expect($result->success)->toBeFalse();
    });

    it('does not rename a folder of another principal', function (): void {
        $ownerId = createUserPrincipalPublic(708);
        $otherId = createUserPrincipalPublic(709);
        $folder = (new MediaFolderService())->create($ownerId, 'Corporate');

        $result = folderHandlerFor($otherId)->run(
            'rename_folder',
            $otherId,
            ['folder_id' => (string) $folder->id, 'name' => 'Company'],
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('does not exist');
    });
});

describe('MediaFolderHandler::delete_folder', function (): void {
    it('deletes a folder and its subfolders, and the assets fall back to the root', function (): void {
        $principalId = createUserPrincipalPublic(710);
        $service = new MediaFolderService();
        $parent = $service->create($principalId, 'Corporate');
        $child = $service->create($principalId, '2026', (string) $parent->id);
        $asset = folderHandlerAsset($principalId, 'theme.yml');
        $service->fileAsset($principalId, $asset, (string) $child->id);

        $result = folderHandlerFor($principalId)->run(
            'delete_folder',
            $principalId,
            ['folder_id' => (string) $parent->id],
        );

        expect($result->success)->toBeTrue();
        expect($service->find($principalId, (string) $parent->id))->toBeNull();
        expect($service->find($principalId, (string) $child->id))->toBeNull();
        expect($asset->fresh()?->folder_id)->toBeNull();
    });

    it('does not delete a folder of another principal', function (): void {
        $ownerId = createUserPrincipalPublic(711);
        $otherId = createUserPrincipalPublic(712);
        $folder = (new MediaFolderService())->create($ownerId, 'Corporate');

        $result = folderHandlerFor($otherId)->run(
            'delete_folder',
            $otherId,
            ['folder_id' => (string) $folder->id],
        );

        expect($result->success)->toBeFalse();
        expect((new MediaFolderService())->find($ownerId, (string) $folder->id))->not->toBeNull();
    });
});

describe('MediaFolderHandler::file_asset', function (): void {
    it('files an asset into a folder', function (): void {
        $principalId = createUserPrincipalPublic(634);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');
        $asset = folderHandlerAsset($principalId, 'theme.yml');

        $result = folderHandlerFor($principalId)->run(
            'file_asset',
            $principalId,
            ['asset_id' => $asset->id, 'folder_id' => (string) $folder->id],
        );

        expect($result->success)->toBeTrue();
        expect((string) $asset->fresh()?->folder_id)->toBe((string) $folder->id);
    });

    it('refuses an asset of another principal', function (): void {
        $ownerId = createUserPrincipalPublic(635);
        $otherId = createUserPrincipalPublic(636);
        $folder = (new MediaFolderService())->create($otherId, 'Corporate');
        $asset = folderHandlerAsset($ownerId, 'theme.yml');

        $result = folderHandlerFor($otherId)->run(
            'file_asset',
            $otherId,
            ['asset_id' => $asset->id, 'folder_id' => (string) $folder->id],
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toBe('Media asset not found.');
    });

    it('reports a filename the folder already holds', function (): void {
        $principalId = createUserPrincipalPublic(637);
        $service = new MediaFolderService();
        $folder = $service->create($principalId, 'Corporate');
        $service->fileAsset($principalId, folderHandlerAsset($principalId, 'theme.yml'), (string) $folder->id);
        $second = folderHandlerAsset($principalId, 'theme.yml');

        $result = folderHandlerFor($principalId)->run(
            'file_asset',
            $principalId,
            ['asset_id' => $second->id, 'folder_id' => (string) $folder->id],
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('theme.yml');
    });

    it('moves an asset back to the root when no folder is named', function (): void {
        $principalId = createUserPrincipalPublic(638);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');
        $asset = folderHandlerAsset($principalId, 'theme.yml');
        (new MediaFolderService())->fileAsset($principalId, $asset, (string) $folder->id);

        $result = folderHandlerFor($principalId)->run(
            'file_asset',
            $principalId,
            ['asset_id' => $asset->id],
        );

        expect($result->success)->toBeTrue();
        expect($asset->fresh()?->folder_id)->toBeNull();
    });
});

describe('MediaFolderHandler::search_assets', function (): void {
    it('lists the assets inside a folder', function (): void {
        $principalId = createUserPrincipalPublic(639);
        $service = new MediaFolderService();
        $folder = $service->create($principalId, 'Corporate');
        $service->fileAsset($principalId, folderHandlerAsset($principalId, 'theme.yml'), (string) $folder->id);
        folderHandlerAsset($principalId, 'loose.md');

        $result = folderHandlerFor($principalId)->run(
            'search_assets',
            $principalId,
            ['folder_id' => (string) $folder->id],
        );

        expect($result->success)->toBeTrue();
        expect($result->data['total'])->toBe(1);
        expect($result->data['items'][0]['filename'])->toBe('theme.yml');
    });

    it('needs a folder', function (): void {
        $principalId = createUserPrincipalPublic(640);

        $result = folderHandlerFor($principalId)->run('search_assets', $principalId);

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('folder_id is required');
    });

    it('reports a folder of another principal as missing, not as empty', function (): void {
        // The folder is resolved before the listing, so "no such folder"
        // and "an empty folder" do not read the same. Another
        // principal's folder resolves to nothing here too — it is
        // reported as missing, never as forbidden, because the
        // difference would be an oracle to probe with.
        $ownerId = createUserPrincipalPublic(641);
        $otherId = createUserPrincipalPublic(642);
        $folder = (new MediaFolderService())->create($ownerId, 'Corporate');

        $result = folderHandlerFor($otherId)->run(
            'search_assets',
            $otherId,
            ['folder_id' => (string) $folder->id],
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toBe('That media folder does not exist.');
    });

    it('reports a folder that does not exist as missing', function (): void {
        $principalId = createUserPrincipalPublic(645);

        $result = folderHandlerFor($principalId)->run(
            'search_assets',
            $principalId,
            ['folder_id' => '99999999-9999-9999-9999-999999999999'],
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toBe('That media folder does not exist.');
    });
});

describe('MediaFolderHandler', function (): void {
    it('refuses an operation that does not exist', function (): void {
        $principalId = createUserPrincipalPublic(643);

        $result = folderHandlerFor($principalId)->run('move_folder', $principalId);

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('Invalid folder action');
    });

    it('needs a resolvable principal', function (): void {
        $result = folderHandlerFor(0)->run('list_folders', 0);

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('resolvable principal');
    });
});
