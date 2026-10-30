<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\MediaAsset;
use Spora\Models\MediaFolder;
use Spora\Services\MediaArchive\Exceptions\MediaFolderNotFoundException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderUniquenessException;
use Spora\Services\MediaArchive\Exceptions\MediaFolderValidationException;
use Spora\Services\MediaArchive\MediaFolderService;

function folderService(): MediaFolderService
{
    return new MediaFolderService();
}

/**
 * A media asset row owned by `$principalId`.
 */
function folderTestAsset(int $principalId, string $filename): MediaAsset
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

describe('MediaFolderService::create', function (): void {
    it('creates a top-level folder with a root path', function (): void {
        $principalId = createUserPrincipalPublic(501);
        $folder = folderService()->create($principalId, 'Corporate');

        expect($folder->path)->toBe('/Corporate');
        expect($folder->parent_id)->toBeNull();
        expect((int) $folder->principal_id)->toBe($principalId);
    });

    it('creates a child folder under a parent', function (): void {
        $principalId = createUserPrincipalPublic(502);
        $service = folderService();
        $parent = $service->create($principalId, 'Corporate');
        $child = $service->create($principalId, '2026', (string) $parent->id);

        expect($child->path)->toBe('/Corporate/2026');
        expect((string) $child->parent_id)->toBe((string) $parent->id);
    });

    it('refuses a sibling with the same name — the path collides', function (): void {
        $principalId = createUserPrincipalPublic(503);
        $service = folderService();
        $service->create($principalId, 'Corporate');

        expect(fn() => $service->create($principalId, 'Corporate'))
            ->toThrow(MediaFolderUniquenessException::class);
    });

    it('allows the same name under a different parent', function (): void {
        $principalId = createUserPrincipalPublic(504);
        $service = folderService();
        $a = $service->create($principalId, 'A');
        $b = $service->create($principalId, 'B');

        $service->create($principalId, 'Shared', (string) $a->id);
        $service->create($principalId, 'Shared', (string) $b->id);

        expect(MediaFolder::query()->where('path', '/A/Shared')->count())->toBe(1);
        expect(MediaFolder::query()->where('path', '/B/Shared')->count())->toBe(1);
    });

    it('refuses a name carrying a path separator', function (): void {
        $principalId = createUserPrincipalPublic(505);

        expect(fn() => folderService()->create($principalId, 'a/b'))
            ->toThrow(MediaFolderValidationException::class);
    });

    it('refuses an empty name', function (): void {
        $principalId = createUserPrincipalPublic(506);

        expect(fn() => folderService()->create($principalId, '   '))
            ->toThrow(MediaFolderValidationException::class);
    });

    it('refuses a parent of another principal', function (): void {
        $ownerId = createUserPrincipalPublic(507);
        $otherId = createUserPrincipalPublic(508);
        $foreign = folderService()->create($ownerId, 'Corporate');

        expect(fn() => folderService()->create($otherId, 'Child', (string) $foreign->id))
            ->toThrow(MediaFolderNotFoundException::class);
    });
});

describe('MediaFolderService::rename', function (): void {
    it('rewrites the folder path', function (): void {
        $principalId = createUserPrincipalPublic(511);
        $service = folderService();
        $folder = $service->create($principalId, 'Old');

        $service->rename($principalId, (string) $folder->id, 'New');

        expect((string) MediaFolder::query()->find($folder->id)?->path)->toBe('/New');
    });

    it('rewrites the paths of the descendants too', function (): void {
        $principalId = createUserPrincipalPublic(512);
        $service = folderService();
        $parent = $service->create($principalId, 'Old');
        $child = $service->create($principalId, 'Child', (string) $parent->id);
        $grandchild = $service->create($principalId, 'Leaf', (string) $child->id);

        $service->rename($principalId, (string) $parent->id, 'New');

        expect((string) MediaFolder::query()->find($child->id)?->path)->toBe('/New/Child');
        expect((string) MediaFolder::query()->find($grandchild->id)?->path)->toBe('/New/Child/Leaf');
    });

    it('refuses a rename that collides with a sibling', function (): void {
        $principalId = createUserPrincipalPublic(513);
        $service = folderService();
        $service->create($principalId, 'Taken');
        $folder = $service->create($principalId, 'Free');

        expect(fn() => $service->rename($principalId, (string) $folder->id, 'Taken'))
            ->toThrow(MediaFolderUniquenessException::class);
    });

    it('refuses a rename of another principal\'s folder', function (): void {
        $ownerId = createUserPrincipalPublic(514);
        $otherId = createUserPrincipalPublic(515);
        $folder = folderService()->create($ownerId, 'Corporate');

        expect(fn() => folderService()->rename($otherId, (string) $folder->id, 'Hijacked'))
            ->toThrow(MediaFolderNotFoundException::class);
    });
});

describe('MediaFolderService::move', function (): void {
    it('moves a folder under a new parent and rewrites the paths', function (): void {
        $principalId = createUserPrincipalPublic(521);
        $service = folderService();
        $target = $service->create($principalId, 'Target');
        $folder = $service->create($principalId, 'Moved');
        $child = $service->create($principalId, 'Child', (string) $folder->id);

        $service->move($principalId, (string) $folder->id, (string) $target->id);

        expect((string) MediaFolder::query()->find($folder->id)?->path)->toBe('/Target/Moved');
        expect((string) MediaFolder::query()->find($child->id)?->path)->toBe('/Target/Moved/Child');
    });

    it('moves a folder back to the root', function (): void {
        $principalId = createUserPrincipalPublic(522);
        $service = folderService();
        $parent = $service->create($principalId, 'Parent');
        $folder = $service->create($principalId, 'Child', (string) $parent->id);

        $service->move($principalId, (string) $folder->id, null);

        expect((string) MediaFolder::query()->find($folder->id)?->path)->toBe('/Child');
        expect(MediaFolder::query()->find($folder->id)?->parent_id)->toBeNull();
    });

    it('refuses a move into the folder\'s own subtree', function (): void {
        $principalId = createUserPrincipalPublic(523);
        $service = folderService();
        $parent = $service->create($principalId, 'Parent');
        $child = $service->create($principalId, 'Child', (string) $parent->id);

        expect(fn() => $service->move($principalId, (string) $parent->id, (string) $child->id))
            ->toThrow(MediaFolderValidationException::class);
    });

    it('refuses a move that collides with a sibling of the new parent', function (): void {
        $principalId = createUserPrincipalPublic(524);
        $service = folderService();
        $target = $service->create($principalId, 'Target');
        $service->create($principalId, 'Taken', (string) $target->id);
        $folder = $service->create($principalId, 'Taken');

        expect(fn() => $service->move($principalId, (string) $folder->id, (string) $target->id))
            ->toThrow(MediaFolderUniquenessException::class);
    });
});

describe('MediaFolderService::delete', function (): void {
    it('deletes the subfolders and leaves the assets in the root', function (): void {
        $principalId = createUserPrincipalPublic(531);
        $service = folderService();
        $parent = $service->create($principalId, 'Corporate');
        $child = $service->create($principalId, '2026', (string) $parent->id);
        $asset = folderTestAsset($principalId, 'theme.yml');
        $service->fileAsset($principalId, $asset, (string) $parent->id);

        $service->delete($principalId, (string) $parent->id);

        expect(MediaFolder::query()->find($parent->id))->toBeNull();
        expect(MediaFolder::query()->find($child->id))->toBeNull();
        expect(MediaAsset::query()->find($asset->id)?->folder_id)->toBeNull();
    });

    it('refuses to delete another principal\'s folder', function (): void {
        $ownerId = createUserPrincipalPublic(532);
        $otherId = createUserPrincipalPublic(533);
        $folder = folderService()->create($ownerId, 'Corporate');

        expect(fn() => folderService()->delete($otherId, (string) $folder->id))
            ->toThrow(MediaFolderNotFoundException::class);
        expect(MediaFolder::query()->find($folder->id))->not->toBeNull();
    });
});

describe('MediaFolderService::listForPrincipal', function (): void {
    it('lists the principal\'s folders with their asset counts', function (): void {
        $principalId = createUserPrincipalPublic(541);
        $service = folderService();
        $folder = $service->create($principalId, 'Corporate');
        $service->create($principalId, 'Empty');
        folderTestAsset($principalId, 'theme.yml');
        $asset = folderTestAsset($principalId, 'styles.yml');
        $service->fileAsset($principalId, $asset, (string) $folder->id);

        $rows = $service->listForPrincipal($principalId);

        expect($rows)->toHaveCount(2);
        $byName = array_column($rows, 'asset_count', 'name');
        expect($byName['Corporate'])->toBe(1);
        expect($byName['Empty'])->toBe(0);
    });

    it('does not list another principal\'s folders', function (): void {
        $ownerId = createUserPrincipalPublic(542);
        $otherId = createUserPrincipalPublic(543);
        folderService()->create($ownerId, 'Corporate');

        expect(folderService()->listForPrincipal($otherId))->toBe([]);
    });

    it('carries the id, name, path and parent id of each folder', function (): void {
        $principalId = createUserPrincipalPublic(544);
        $service = folderService();
        $parent = $service->create($principalId, 'Corporate');
        $child = $service->create($principalId, '2026', (string) $parent->id);

        $rows = $service->listForPrincipal($principalId);
        $childRow = array_values(array_filter($rows, fn(array $row): bool => $row['name'] === '2026'));

        expect($childRow)->toHaveCount(1);
        expect($childRow[0]['id'])->toBe((string) $child->id);
        expect($childRow[0]['path'])->toBe('/Corporate/2026');
        expect($childRow[0]['parent_id'])->toBe((string) $parent->id);
    });
});

describe('MediaFolderService::find', function (): void {
    it('does not find another principal\'s folder', function (): void {
        $ownerId = createUserPrincipalPublic(551);
        $otherId = createUserPrincipalPublic(552);
        $folder = folderService()->create($ownerId, 'Corporate');

        expect(folderService()->find($ownerId, (string) $folder->id))->not->toBeNull();
        expect(folderService()->find($otherId, (string) $folder->id))->toBeNull();
    });
});

describe('MediaFolderService::fileAsset', function (): void {
    it('files an asset into a folder', function (): void {
        $principalId = createUserPrincipalPublic(561);
        $service = folderService();
        $folder = $service->create($principalId, 'Corporate');
        $asset = folderTestAsset($principalId, 'theme.yml');

        $service->fileAsset($principalId, $asset, (string) $folder->id);

        expect((string) $asset->fresh()?->folder_id)->toBe((string) $folder->id);
    });

    it('moves an asset back to the root', function (): void {
        $principalId = createUserPrincipalPublic(562);
        $service = folderService();
        $folder = $service->create($principalId, 'Corporate');
        $asset = folderTestAsset($principalId, 'theme.yml');
        $service->fileAsset($principalId, $asset, (string) $folder->id);

        $service->fileAsset($principalId, $asset, null);

        expect($asset->fresh()?->folder_id)->toBeNull();
    });

    it('refuses a filename the folder already holds', function (): void {
        $principalId = createUserPrincipalPublic(563);
        $service = folderService();
        $folder = $service->create($principalId, 'Corporate');
        $service->fileAsset($principalId, folderTestAsset($principalId, 'theme.yml'), (string) $folder->id);
        $second = folderTestAsset($principalId, 'theme.yml');

        expect(fn() => $service->fileAsset($principalId, $second, (string) $folder->id))
            ->toThrow(MediaFolderUniquenessException::class);
    });

    it('refuses to file an asset into another principal\'s folder', function (): void {
        $ownerId = createUserPrincipalPublic(564);
        $otherId = createUserPrincipalPublic(565);
        $folder = folderService()->create($ownerId, 'Corporate');
        $asset = folderTestAsset($otherId, 'theme.yml');

        expect(fn() => folderService()->fileAsset($otherId, $asset, (string) $folder->id))
            ->toThrow(MediaFolderNotFoundException::class);
    });

    it('reports the uniqueness violation instead of leaking the database exception', function (): void {
        // The unique index is the backstop; the service's check is what
        // turns the collision into a domain error the controller can
        // answer with a 422.
        $principalId = createUserPrincipalPublic(566);
        $service = folderService();
        $folder = $service->create($principalId, 'Corporate');
        $service->fileAsset($principalId, folderTestAsset($principalId, 'theme.yml'), (string) $folder->id);
        $second = folderTestAsset($principalId, 'theme.yml');

        try {
            $service->fileAsset($principalId, $second, (string) $folder->id);
            $this->fail('Expected a MediaFolderUniquenessException.');
        } catch (MediaFolderUniquenessException $e) {
            expect($e)->not->toBeInstanceOf(\Illuminate\Database\QueryException::class);
            expect($e->getMessage())->toContain('theme.yml');
        }
    });
});

describe('MediaFolderService::fileTarget', function (): void {
    it('returns the folder when the filename is free', function (): void {
        $principalId = createUserPrincipalPublic(572);
        $folder = folderService()->create($principalId, 'Corporate');

        expect((string) folderService()->fileTarget($principalId, (string) $folder->id, 'theme.yml')->id)
            ->toBe((string) $folder->id);
    });

    it('refuses a filename the folder already holds', function (): void {
        $principalId = createUserPrincipalPublic(573);
        $service = folderService();
        $folder = $service->create($principalId, 'Corporate');
        $service->fileAsset($principalId, folderTestAsset($principalId, 'theme.yml'), (string) $folder->id);

        expect(fn() => $service->fileTarget($principalId, (string) $folder->id, 'theme.yml'))
            ->toThrow(MediaFolderUniquenessException::class);
    });

    it('accepts the asset that already sits in the folder', function (): void {
        $principalId = createUserPrincipalPublic(574);
        $service = folderService();
        $folder = $service->create($principalId, 'Corporate');
        $asset = folderTestAsset($principalId, 'theme.yml');
        $service->fileAsset($principalId, $asset, (string) $folder->id);

        expect((string) $service->fileTarget(
            $principalId,
            (string) $folder->id,
            'theme.yml',
            (string) $asset->id,
        )->id)->toBe((string) $folder->id);
    });
});

describe('the database indexes back the service rules', function (): void {
    it('rejects a duplicate (folder_id, filename) row at the database level', function (): void {
        // The service's check is the friendly path; the index is what
        // makes the rule true when two writers race.
        $principalId = createUserPrincipalPublic(581);
        $service = folderService();
        $folder = $service->create($principalId, 'Corporate');
        $service->fileAsset($principalId, folderTestAsset($principalId, 'theme.yml'), (string) $folder->id);
        $second = folderTestAsset($principalId, 'theme.yml');
        $second->folder_id = (string) $folder->id;

        expect(fn() => $second->save())->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('rejects a duplicate (principal_id, path) folder at the database level', function (): void {
        $principalId = createUserPrincipalPublic(582);
        $service = folderService();
        $service->create($principalId, 'Corporate');

        $duplicate = new MediaFolder();
        $duplicate->id = '99999999-9999-4999-8999-999999999999';
        $duplicate->principal_id = $principalId;
        $duplicate->name = 'Corporate';
        $duplicate->path = '/Corporate';

        expect(fn() => $duplicate->save())->toThrow(\Illuminate\Database\QueryException::class);
    });
});

describe('MediaFolderService rows are persisted', function (): void {
    it('keeps the folder id a UUID string', function (): void {
        $principalId = createUserPrincipalPublic(591);
        $folder = folderService()->create($principalId, 'Corporate');

        expect((string) $folder->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
        expect(Capsule::table('media_folders')->where('id', $folder->id)->count())->toBe(1);
    });
});
