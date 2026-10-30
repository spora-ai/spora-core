<?php

declare(strict_types=1);

namespace Tests\Unit\Tools;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaFolderService;
use Spora\Services\PrincipalContext;
use Spora\Tools\MediaFolderTool;

/**
 * The assistant's folder surface.
 *
 * The assistant may discover folders, file an asset into one and search
 * within one. It may not create, rename, move or delete them — there is
 * no operation for that.
 */
function folderToolFor(int $principalId): MediaFolderTool
{
    $archive = makeMediaArchiveService()['service'];

    return new MediaFolderTool(new MediaFolderService(), $archive);
}

function folderToolContext(int $principalId): PrincipalContext
{
    return new PrincipalContext($principalId, 'user', 1, 1);
}

function folderToolAsset(int $principalId, string $filename): MediaAsset
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

describe('MediaFolderTool::list_folders', function (): void {
    it('lists the principal\'s folders', function (): void {
        $principalId = createUserPrincipalPublic(631);
        (new MediaFolderService())->create($principalId, 'Corporate');

        $result = folderToolFor($principalId)->execute(
            ['action' => 'list_folders'],
            1,
            null,
            folderToolContext($principalId),
        );

        expect($result->success)->toBeTrue();
        expect($result->data['folders'])->toHaveCount(1);
        expect($result->data['folders'][0]['name'])->toBe('Corporate');
    });

    it('does not list another principal\'s folders', function (): void {
        $ownerId = createUserPrincipalPublic(632);
        $otherId = createUserPrincipalPublic(633);
        (new MediaFolderService())->create($ownerId, 'Corporate');

        $result = folderToolFor($otherId)->execute(
            ['action' => 'list_folders'],
            1,
            null,
            folderToolContext($otherId),
        );

        expect($result->success)->toBeTrue();
        expect($result->data['folders'])->toBe([]);
    });
});

describe('MediaFolderTool::file_asset', function (): void {
    it('files an asset into a folder', function (): void {
        $principalId = createUserPrincipalPublic(634);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');
        $asset = folderToolAsset($principalId, 'theme.yml');

        $result = folderToolFor($principalId)->execute(
            ['action' => 'file_asset', 'asset_id' => $asset->id, 'folder_id' => (string) $folder->id],
            1,
            null,
            folderToolContext($principalId),
        );

        expect($result->success)->toBeTrue();
        expect((string) $asset->fresh()?->folder_id)->toBe((string) $folder->id);
    });

    it('refuses an asset of another principal', function (): void {
        $ownerId = createUserPrincipalPublic(635);
        $otherId = createUserPrincipalPublic(636);
        $folder = (new MediaFolderService())->create($otherId, 'Corporate');
        $asset = folderToolAsset($ownerId, 'theme.yml');

        $result = folderToolFor($otherId)->execute(
            ['action' => 'file_asset', 'asset_id' => $asset->id, 'folder_id' => (string) $folder->id],
            1,
            null,
            folderToolContext($otherId),
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toBe('Media asset not found.');
    });

    it('reports a filename the folder already holds', function (): void {
        $principalId = createUserPrincipalPublic(637);
        $service = new MediaFolderService();
        $folder = $service->create($principalId, 'Corporate');
        $service->fileAsset($principalId, folderToolAsset($principalId, 'theme.yml'), (string) $folder->id);
        $second = folderToolAsset($principalId, 'theme.yml');

        $result = folderToolFor($principalId)->execute(
            ['action' => 'file_asset', 'asset_id' => $second->id, 'folder_id' => (string) $folder->id],
            1,
            null,
            folderToolContext($principalId),
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('theme.yml');
    });

    it('moves an asset back to the root when no folder is named', function (): void {
        $principalId = createUserPrincipalPublic(638);
        $folder = (new MediaFolderService())->create($principalId, 'Corporate');
        $asset = folderToolAsset($principalId, 'theme.yml');
        (new MediaFolderService())->fileAsset($principalId, $asset, (string) $folder->id);

        $result = folderToolFor($principalId)->execute(
            ['action' => 'file_asset', 'asset_id' => $asset->id],
            1,
            null,
            folderToolContext($principalId),
        );

        expect($result->success)->toBeTrue();
        expect($asset->fresh()?->folder_id)->toBeNull();
    });
});

describe('MediaFolderTool::search_assets', function (): void {
    it('lists the assets inside a folder', function (): void {
        $principalId = createUserPrincipalPublic(639);
        $service = new MediaFolderService();
        $folder = $service->create($principalId, 'Corporate');
        $service->fileAsset($principalId, folderToolAsset($principalId, 'theme.yml'), (string) $folder->id);
        folderToolAsset($principalId, 'loose.md');

        $result = folderToolFor($principalId)->execute(
            ['action' => 'search_assets', 'folder_id' => (string) $folder->id],
            1,
            null,
            folderToolContext($principalId),
        );

        expect($result->success)->toBeTrue();
        expect($result->data['total'])->toBe(1);
        expect($result->data['items'][0]['filename'])->toBe('theme.yml');
    });

    it('needs a folder', function (): void {
        $principalId = createUserPrincipalPublic(640);

        $result = folderToolFor($principalId)->execute(
            ['action' => 'search_assets'],
            1,
            null,
            folderToolContext($principalId),
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('folder_id is required');
    });

    it('yields nothing for a folder of another principal', function (): void {
        $ownerId = createUserPrincipalPublic(641);
        $otherId = createUserPrincipalPublic(642);
        $folder = (new MediaFolderService())->create($ownerId, 'Corporate');

        $result = folderToolFor($otherId)->execute(
            ['action' => 'search_assets', 'folder_id' => (string) $folder->id],
            1,
            null,
            folderToolContext($otherId),
        );

        expect($result->success)->toBeTrue();
        expect($result->data['total'])->toBe(0);
    });
});

describe('MediaFolderTool', function (): void {
    it('refuses an operation that does not exist', function (): void {
        $principalId = createUserPrincipalPublic(643);

        $result = folderToolFor($principalId)->execute(
            ['action' => 'create_folder'],
            1,
            null,
            folderToolContext($principalId),
        );

        expect($result->success)->toBeFalse();
        expect($result->content)->toContain('Invalid action');
    });

    it('declares only the three operations the assistant may use', function (): void {
        // The operator curates the folders, so there is deliberately no
        // create/rename/move/delete operation here.
        $operations = array_map(
            static fn($operation): string => $operation->name,
            (new MediaFolderTool(new MediaFolderService(), makeMediaArchiveService()['service']))->getOperations(),
        );

        expect($operations)->toBe(['list_folders', 'file_asset', 'search_assets']);
    });

    it('is wired into the container', function (): void {
        $kernel = new \Spora\Core\Kernel();
        $container = $kernel->getContainer();

        expect($container->get(MediaFolderTool::class))->toBeInstanceOf(MediaFolderTool::class);
        expect($container->get('tool_classes'))->toContain(MediaFolderTool::class);

        unset($kernel);
        gc_collect_cycles();
    });
});
