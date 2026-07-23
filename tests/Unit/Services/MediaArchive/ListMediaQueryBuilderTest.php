<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Spora\Services\MediaArchive\ListMediaQueryBuilder;
use Spora\Services\MediaArchive\MediaType;
use Symfony\Component\HttpFoundation\Request;

describe('ListMediaQueryBuilder::parseMediaTypes', function (): void {
    it('returns null for missing or empty input', function (): void {
        $request = Request::create('/?other=1');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->mediaTypes)->toBeNull();
    });

    it('parses a single token', function (): void {
        $request = Request::create('/?types=image');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->mediaTypes)->toBe([MediaType::Image]);
        // Singular `mediaType` is cleared when `types` is present so we
        // don't double-filter.
        expect($query->mediaType)->toBeNull();
    });

    it('parses a comma-separated list, preserving order and dropping unknowns', function (): void {
        $request = Request::create('/?types=image,document,unknown,audio');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->mediaTypes)->toBe([MediaType::Image, MediaType::Document, MediaType::Audio]);
    });

    it('falls back to singular `type` when `types` is absent', function (): void {
        $request = Request::create('/?type=image');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->mediaType)->toBe(MediaType::Image);
        expect($query->mediaTypes)->toBeNull();
    });

    it('trims whitespace around tokens', function (): void {
        $request = Request::create('/?types=%20image%20,%20document%20');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->mediaTypes)->toBe([MediaType::Image, MediaType::Document]);
    });

    it('silently drops unknown tokens (typo tolerance)', function (): void {
        $request = Request::create('/?types=image,imge,document');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->mediaTypes)->toBe([MediaType::Image, MediaType::Document]);
    });
});

describe('ListMediaQueryBuilder search field', function (): void {
    it('passes `q` through as the search field', function (): void {
        $request = Request::create('/?q=alpine%20sunset');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->search)->toBe('alpine sunset');
    });
});
describe('ListMediaQueryBuilder upload_source field', function (): void {
    it('returns null when source is missing or empty', function (): void {
        $request = Request::create('/media');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->uploadSource)->toBeNull();
    });

    it("parses source=upload into the 'upload' constant", function (): void {
        $request = Request::create('/?source=upload');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->uploadSource)->toBe('upload');
    });

    it("parses source=tool into the 'tool' constant", function (): void {
        $request = Request::create('/?source=tool');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->uploadSource)->toBe('tool');
    });

    it("maps source=all to null (no filter applied)", function (): void {
        $request = Request::create('/?source=all');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->uploadSource)->toBeNull();
    });

    it('silently drops unknown source values (typo tolerance)', function (): void {
        $request = Request::create('/?source=bogus');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->uploadSource)->toBeNull();
    });

    it('lowercases the value before comparing', function (): void {
        $request = Request::create('/?source=UPLOAD');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->uploadSource)->toBe('upload');
    });
});


describe('ListMediaQueryBuilder ownership field', function (): void {
    it('ownership=mine populates agentOwnerUserId and clears userId', function (): void {
        $request = Request::create('/?ownership=mine');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBe('mine');
        expect($query->agentOwnerUserId)->toBe(7);
        // Ownership supersedes the legacy `userId` branch — the service
        // applies the union and skips the upload-only WHERE.
        expect($query->userId)->toBeNull();
    });

    it('ownership=all maps to no filter (mirrors ?source=all sentinel)', function (): void {
        $request = Request::create('/?ownership=all');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBe('all');
        expect($query->agentOwnerUserId)->toBeNull();
        expect($query->userId)->toBeNull();
    });

    it('ownership=mine with null auth userId leaves agentOwnerUserId null', function (): void {
        $request = Request::create('/?ownership=mine');
        $query = ListMediaQueryBuilder::fromRequest($request, null);
        expect($query->ownership)->toBe('mine');
        // No authenticated user → no ownership filter is applied; the
        // service would refuse the request via AuthMiddleware anyway.
        expect($query->agentOwnerUserId)->toBeNull();
    });

    it('silently drops unknown ownership values (typo tolerance)', function (): void {
        $request = Request::create('/?ownership=bogus');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBeNull();
        expect($query->agentOwnerUserId)->toBeNull();
    });

    it('treats empty ownership as missing', function (): void {
        $request = Request::create('/?ownership=');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBeNull();
        expect($query->agentOwnerUserId)->toBeNull();
    });

    it('ownership wins over scope when both are present', function (): void {
        $request = Request::create('/?scope=mine&ownership=mine');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        // Ownership union takes precedence; legacy userId branch is
        // dormant so the WHERE doesn't double-apply.
        expect($query->ownership)->toBe('mine');
        expect($query->agentOwnerUserId)->toBe(7);
        expect($query->userId)->toBeNull();
    });

    it('legacy scope=mine with no ownership keeps the upload-only path', function (): void {
        $request = Request::create('/?scope=mine');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBeNull();
        expect($query->agentOwnerUserId)->toBeNull();
        // Pre-existing behaviour preserved for callers that haven't
        // migrated to the union.
        expect($query->userId)->toBe(7);
    });
});
