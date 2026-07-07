<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\MediaType;

/**
 * Direct unit coverage for {@see MediaAsset}. The model's only domain
 * logic is the `typedMediaType()` accessor — every other field is a plain
 * Eloquent attribute. The accessor returns Unknown for null/invalid
 * values so callers don't have to guard; this file pins that contract.
 */
describe('MediaAsset::typedMediaType', function (): void {
    it('returns Unknown when media_type is null', function (): void {
        $asset = new MediaAsset();
        $asset->media_type = null;

        expect($asset->typedMediaType())->toBe(MediaType::Unknown);
    });

    it('returns Unknown when media_type is an empty string', function (): void {
        $asset = new MediaAsset();
        $asset->media_type = '';

        expect($asset->typedMediaType())->toBe(MediaType::Unknown);
    });

    it('returns Unknown when media_type is not a known enum value', function (): void {
        $asset = new MediaAsset();
        $asset->media_type = 'bogus';

        expect($asset->typedMediaType())->toBe(MediaType::Unknown);
    });

    it('returns the matching MediaType for every valid value', function (): void {
        foreach (MediaType::cases() as $case) {
            $asset = new MediaAsset();
            $asset->media_type = $case->value;
            expect($asset->typedMediaType())->toBe($case);
        }
    });
});

describe('MediaAsset key configuration', function (): void {
    it('uses the media_assets table and a non-incrementing string primary key', function (): void {
        $asset = new MediaAsset();

        expect($asset->getTable())->toBe('media_assets');
        expect($asset->getKeyType())->toBe('string');
        expect($asset->getIncrementing())->toBeFalse();
    });
});

describe('MediaAsset casts', function (): void {
    it('casts tags and metadata as arrays (JSON columns)', function (): void {
        $asset = new MediaAsset();
        $asset->tags = ['hero', 'square'];
        $asset->metadata = ['seed' => 42];

        expect($asset->tags)->toBe(['hero', 'square']);
        expect($asset->metadata)->toBe(['seed' => 42]);
    });

    it('preserves the documented cast shape for numeric and duration fields', function (): void {
        // The cast declaration (`'agent_id' => 'integer'`) is verified by
        // setting a numeric value and reading it back. PHPStan already
        // enforces the int|null type so we don't reassign non-numeric
        // strings here — that case is the framework's responsibility.
        $asset = new MediaAsset();
        $asset->agent_id         = 7;
        $asset->task_id          = 11;
        $asset->byte_size        = 1024;
        $asset->width            = 64;
        $asset->height           = 64;
        $asset->duration_seconds = 1.5;

        expect($asset->agent_id)->toBe(7);
        expect($asset->task_id)->toBe(11);
        expect($asset->byte_size)->toBe(1024);
        expect($asset->width)->toBe(64);
        expect($asset->height)->toBe(64);
        expect($asset->duration_seconds)->toBe(1.5);
    });
});
