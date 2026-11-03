<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use InvalidArgumentException;
use Spora\Models\MediaAsset;
use Spora\Models\MediaFolder;
use Spora\Services\MediaArchive\MediaAssetKind;
use Spora\Services\MediaArchive\MediaFolderService;

describe('MediaFolder key configuration', function (): void {
    it('uses the media_folders table and a non-incrementing string primary key', function (): void {
        $folder = new MediaFolder();

        expect($folder->getTable())->toBe('media_folders');
        expect($folder->getKeyType())->toBe('string');
        expect($folder->getIncrementing())->toBeFalse();
    });

    it('declares the columns it persists', function (): void {
        expect(MediaFolder::COLUMNS)->toBe(['id', 'principal_id', 'parent_id', 'name', 'path']);
    });

    it('casts the principal id to an integer', function (): void {
        $folder = new MediaFolder();
        $folder->principal_id = 42;

        expect($folder->principal_id)->toBe(42);
    });
});

describe('MediaFolder::assertStringColumnsFit', function (): void {
    it('throws when the name exceeds VARCHAR(255)', function (): void {
        $folder = new MediaFolder();
        $folder->name = str_repeat('n', 256);

        try {
            $folder->assertStringColumnsFit();
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('media_folders.name')
                ->and($e->getMessage())->toContain('column limit is 255');
        }
    });

    it('throws when the path exceeds VARCHAR(512)', function (): void {
        $folder = new MediaFolder();
        $folder->path = '/' . str_repeat('p', 512);

        try {
            $folder->assertStringColumnsFit();
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('media_folders.path')
                ->and($e->getMessage())->toContain('column limit is 512');
        }
    });
});

describe('MediaFolder relations', function (): void {
    it('links the folder to its principal, its parent and its children', function (): void {
        $principalId = createUserPrincipalPublic(611);
        $service = new MediaFolderService();
        $parent = $service->create($principalId, 'Corporate');
        $child = $service->create($principalId, '2026', (string) $parent->id);

        expect((int) $parent->principal?->id)->toBe($principalId);
        expect($parent->parent)->toBeNull();
        expect($parent->children->pluck('id')->all())->toBe([(string) $child->id]);
        expect((string) $child->parent?->id)->toBe((string) $parent->id);
    });

    it('lists the assets filed in the folder', function (): void {
        $principalId = createUserPrincipalPublic(612);
        $service = new MediaFolderService();
        $folder = $service->create($principalId, 'Corporate');

        $asset = new MediaAsset();
        $asset->id = '22222222-2222-4222-8222-222222222222';
        $asset->principal_id = $principalId;
        $asset->folder_id = (string) $folder->id;
        $asset->filename = 'theme.yml';
        $asset->media_type = 'document';
        $asset->mime_type = 'text/markdown';
        $asset->storage_mode = 'data_url';
        $asset->asset_url = '/api/v1/assets/' . $asset->id . '.md';
        $asset->asset_token = bin2hex(random_bytes(16));
        $asset->save();

        expect($folder->assets->pluck('id')->all())->toBe([$asset->id]);
        expect((string) $asset->fresh()?->folder?->id)->toBe((string) $folder->id);
    });
});

describe('MediaAsset folder and kind columns', function (): void {
    it('declares folder_id and kind as persisted columns', function (): void {
        expect(MediaAsset::COLUMNS)->toContain('folder_id');
        expect(MediaAsset::COLUMNS)->toContain('kind');
    });

    it('bounds the kind to VARCHAR(16)', function (): void {
        expect(MediaAsset::STRING_COLUMN_MAX_LENGTHS['kind'])->toBe(16);

        $asset = new MediaAsset();
        $asset->kind = str_repeat('k', 17);

        expect(fn() => $asset->assertStringColumnsFit())
            ->toThrow(InvalidArgumentException::class, 'column limit is 16');
    });

    it('accepts an advisory kind outside the known vocabulary', function (): void {
        // The kind is a hint, never a gate: an unknown mark is stored.
        $asset = new MediaAsset();
        $asset->kind = 'something-else';

        expect(fn() => $asset->assertStringColumnsFit())->not->toThrow(InvalidArgumentException::class);
    });

    it('knows the three kinds the vocabulary defines', function (): void {
        expect(MediaAssetKind::ALL)->toBe(['asset', 'template', 'example']);
    });
});
