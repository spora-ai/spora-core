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
