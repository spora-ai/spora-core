<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use RuntimeException;
use Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Tests\Support\MediaArchiveTestSupport;

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

/**
 * Plan §12 B2b — pinning MediaConverterRegistry behaviour.
 */
test('findFor matches MIME case-insensitively', function (): void {
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    expect($registry->findFor('TEXT/PLAIN', null))->toBeInstanceOf(PlainTextPassthroughConverter::class);
});

test('findFor falls back to filename extension when MIME misses', function (): void {
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    // application/x-text is not in the allowlist, but .txt is
    expect($registry->findFor('application/x-text', 'note.txt'))
        ->toBeInstanceOf(PlainTextPassthroughConverter::class);
});

test('findFor returns null when no converter matches', function (): void {
    MediaConverterDiscovery::reset();
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    expect($registry->findFor('application/x-unknown', 'whatever.xyz'))->toBeNull();
});

test('findFor returns null when the registry is empty', function (): void {
    MediaConverterDiscovery::reset();
    // MediaConverterRegistry reads MediaConverterDiscovery::all() at
    // construction. With reset() having cleared it, the registry
    // iterates over zero converters. The container is irrelevant here
    // — no FQCN is ever asked for.
    $registry = new MediaConverterRegistry(new class implements \Psr\Container\ContainerInterface {
        public function get(string $id): mixed
        {
            throw new RuntimeException("Not registered: {$id}");
        }
        public function has(string $id): bool
        {
            return false;
        }
    });
    expect($registry->findFor('text/plain', null))->toBeNull();
    expect($registry->findFor('application/pdf', null))->toBeNull();
    expect($registry->allSupportedMimeTypes())->toBe([]);
});

test('convert returns null when no converter matches', function (): void {
    MediaConverterDiscovery::reset();
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    expect($registry->convert('bytes', 'application/x-unknown', 'whatever.xyz'))->toBeNull();
});
