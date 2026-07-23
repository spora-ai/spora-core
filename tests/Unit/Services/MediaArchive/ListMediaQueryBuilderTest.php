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
        expect($query->userId)->toBeNull();
    });

    it('default with no ownership param scopes to the caller (security: must not leak full table)', function (): void {
        // The default is ownership=mine so an authenticated user can
        // never get an unfiltered list via a missing or empty ownership
        // query string.
        $request = Request::create('/');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBe('mine');
        expect($query->agentOwnerUserId)->toBe(7);
        expect($query->userId)->toBeNull();
    });

    it('ownership=all is rejected to mine (no way for an authed user to dump every row)', function (): void {
        // The historical `?ownership=all` was a no-op sentinel, which
        // would have let any authenticated user dump every media row
        // in the system. It is no longer a valid value: the builder
        // normalises it to `mine` so the caller still gets a scoped
        // response.
        $request = Request::create('/?ownership=all');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBe('mine');
        expect($query->agentOwnerUserId)->toBe(7);
        expect($query->userId)->toBeNull();
    });

    it('ownership=mine with null auth userId leaves agentOwnerUserId null', function (): void {
        $request = Request::create('/?ownership=mine');
        $query = ListMediaQueryBuilder::fromRequest($request, null);
        expect($query->ownership)->toBe('mine');
        expect($query->agentOwnerUserId)->toBeNull();
    });

    it('silently drops unknown ownership values to the mine default (typo tolerance)', function (): void {
        $request = Request::create('/?ownership=bogus');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBe('mine');
        expect($query->agentOwnerUserId)->toBe(7);
    });

    it('treats empty ownership as missing (defaults to mine)', function (): void {
        $request = Request::create('/?ownership=');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBe('mine');
        expect($query->agentOwnerUserId)->toBe(7);
    });

    it('ownership wins over scope when both are present', function (): void {
        $request = Request::create('/?scope=mine&ownership=mine');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        expect($query->ownership)->toBe('mine');
        expect($query->agentOwnerUserId)->toBe(7);
        expect($query->userId)->toBeNull();
    });

    it('legacy scope=mine with explicit ownership=bogus still falls through to the union default', function (): void {
        $request = Request::create('/?scope=mine&ownership=bogus');
        $query = ListMediaQueryBuilder::fromRequest($request, 7);
        // `bogus` is dropped and the parser falls back to the union
        // default (mine) — legacy scope is dormant.
        expect($query->ownership)->toBe('mine');
        expect($query->agentOwnerUserId)->toBe(7);
        expect($query->userId)->toBeNull();
    });
});
