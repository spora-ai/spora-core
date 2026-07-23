<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use DateTimeImmutable;
use Spora\Services\MediaArchive\ListMediaQuery;
use Spora\Services\MediaArchive\MediaType;

/**
 * Direct unit coverage for the {@see ListMediaQuery} readonly DTO.
 * Hits the constructor (all defaults + explicit values), the
 * `perPage()` / `page()` clamps, the `toArray()` projection, and the
 * `appendsForPagination()` helper that drops nulls/empties and
 * prepends the `from`/`to` ATOM timestamps when set.
 */
describe('ListMediaQuery defaults', function (): void {
    it('uses the documented defaults when constructed with no arguments', function (): void {
        $query = new ListMediaQuery();

        expect($query->mediaType)->toBeNull();
        expect($query->agentId)->toBeNull();
        expect($query->pluginSlug)->toBeNull();
        expect($query->toolName)->toBeNull();
        expect($query->from)->toBeNull();
        expect($query->to)->toBeNull();
        expect($query->search)->toBeNull();
        expect($query->sort)->toBe(ListMediaQuery::SORT_CREATED_DESC);
        expect($query->uploadSource)->toBeNull();
        expect($query->page)->toBe(1);
        expect($query->perPage)->toBe(ListMediaQuery::PER_PAGE_DEFAULT);
    });

    it('exposes the canonical upload-source filter constants', function (): void {
        expect(ListMediaQuery::UPLOAD_SOURCE_UPLOAD)->toBe('upload');
        expect(ListMediaQuery::UPLOAD_SOURCE_TOOL)->toBe('tool');
        expect(ListMediaQuery::UPLOAD_SOURCE_ALL)->toBe('all');
        expect(ListMediaQuery::ALLOWED_UPLOAD_SOURCES)->toBe([
            ListMediaQuery::UPLOAD_SOURCE_UPLOAD,
            ListMediaQuery::UPLOAD_SOURCE_TOOL,
            ListMediaQuery::UPLOAD_SOURCE_ALL,
        ]);
    });

    it('exposes the canonical sort keys and their ALLOWED_SORTS list', function (): void {
        expect(ListMediaQuery::SORT_CREATED_DESC)->toBe('created_at_desc');
        expect(ListMediaQuery::SORT_CREATED_ASC)->toBe('created_at_asc');
        expect(ListMediaQuery::SORT_SIZE_DESC)->toBe('size_desc');
        expect(ListMediaQuery::ALLOWED_SORTS)->toBe([
            ListMediaQuery::SORT_CREATED_DESC,
            ListMediaQuery::SORT_CREATED_ASC,
            ListMediaQuery::SORT_SIZE_DESC,
        ]);
    });
});

describe('ListMediaQuery::perPage()', function (): void {
    it('clamps values above PER_PAGE_MAX down to the ceiling', function (): void {
        expect((new ListMediaQuery(perPage: 999_999))->perPage())->toBe(ListMediaQuery::PER_PAGE_MAX);
    });

    it('clamps values below 1 up to 1 (defensive — service should never pass zero/negative)', function (): void {
        expect((new ListMediaQuery(perPage: 0))->perPage())->toBe(1);
        expect((new ListMediaQuery(perPage: -7))->perPage())->toBe(1);
    });

    it('passes through in-range values unchanged', function (): void {
        expect((new ListMediaQuery(perPage: 50))->perPage())->toBe(50);
        expect((new ListMediaQuery(perPage: 100))->perPage())->toBe(100);
    });
});

describe('ListMediaQuery::page()', function (): void {
    it('clamps non-positive page values up to 1', function (): void {
        expect((new ListMediaQuery(page: 0))->page())->toBe(1);
        expect((new ListMediaQuery(page: -3))->page())->toBe(1);
    });

    it('passes through positive page values unchanged', function (): void {
        expect((new ListMediaQuery(page: 5))->page())->toBe(5);
    });
});

describe('ListMediaQuery::toArray()', function (): void {
    it('projects the filter fields and nulls the ones that were not set', function (): void {
        $query = new ListMediaQuery(
            mediaType: MediaType::Image,
            agentId: 42,
            pluginSlug: 'foo',
            toolName: 'tavily',
            search: 'hello',
            sort: ListMediaQuery::SORT_CREATED_ASC,
        );

        expect($query->toArray())->toBe([
            'mediaType'    => 'image',
            'mediaTypes'   => null,
            'agentId'      => 42,
            'userId'       => null,
            'pluginSlug'   => 'foo',
            'toolName'     => 'tavily',
            'search'       => 'hello',
            'sort'         => ListMediaQuery::SORT_CREATED_ASC,
            'uploadSource' => null,
        ]);
    });

    it('always emits the documented keys (null when not set)', function (): void {
        $arr = (new ListMediaQuery())->toArray();
        expect($arr)->toHaveKey('sort');
        expect($arr)->toHaveKey('mediaType');
        expect($arr)->toHaveKey('agentId');
        expect($arr)->toHaveKey('uploadSource');
        // Values are null when the caller didn't supply them.
        expect($arr['mediaType'])->toBeNull();
        expect($arr['agentId'])->toBeNull();
        expect($arr['pluginSlug'])->toBeNull();
        expect($arr['search'])->toBeNull();
        expect($arr['uploadSource'])->toBeNull();
    });

    it('projects the uploadSource filter through toArray() when set', function (): void {
        $arr = (new ListMediaQuery(uploadSource: ListMediaQuery::UPLOAD_SOURCE_UPLOAD))->toArray();
        expect($arr['uploadSource'])->toBe('upload');
    });
});

describe('ListMediaQuery::appendsForPagination()', function (): void {
    it('drops null and empty-string filter fields', function (): void {
        $arr = (new ListMediaQuery(
            mediaType: MediaType::Video,
            search: '',
        ))->appendsForPagination();

        // `mediaType` survives because it's set; `search` is dropped (empty).
        expect($arr)->toHaveKey('mediaType');
        expect($arr)->not->toHaveKey('search');
        expect($arr['mediaType'])->toBe('video');
    });

    it('emits from/to as ATOM strings when they are set', function (): void {
        $from = new DateTimeImmutable('2024-01-02T03:04:05+00:00');
        $to   = new DateTimeImmutable('2024-02-02T03:04:05+00:00');

        $arr = (new ListMediaQuery(from: $from, to: $to))->appendsForPagination();

        expect($arr)->toHaveKey('from');
        expect($arr)->toHaveKey('to');
        // ATOM format: 2024-01-02T03:04:05+00:00
        expect($arr['from'])->toMatch('/^2024-01-02T03:04:05/');
        expect($arr['to'])->toMatch('/^2024-02-02T03:04:05/');
    });

    it('omits from/to keys entirely when they are null', function (): void {
        $arr = (new ListMediaQuery(mediaType: MediaType::Image))->appendsForPagination();

        expect($arr)->not->toHaveKey('from');
        expect($arr)->not->toHaveKey('to');
    });

    it('preserves the sort key verbatim (used by paginator for nextPageUrl)', function (): void {
        $arr = (new ListMediaQuery(sort: ListMediaQuery::SORT_SIZE_DESC))->appendsForPagination();
        expect($arr['sort'])->toBe(ListMediaQuery::SORT_SIZE_DESC);
    });
});
