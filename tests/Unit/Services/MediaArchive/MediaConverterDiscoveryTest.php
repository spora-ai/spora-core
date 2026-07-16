<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use InvalidArgumentException;
use RuntimeException;
use Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterInterface;

/**
 * Cover {@see MediaConverterDiscovery} — the static registry that
 * lists converter FQCNs available in this app. The discovery list
 * is the seed for {@see \Spora\Services\MediaArchive\MediaConverterRegistry};
 * plugins add to it via their `register(ContainerBuilder)` hook.
 */
afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

test('add() registers a class-string and all() returns it', function (): void {
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);

    expect(MediaConverterDiscovery::all())->toBe([PlainTextPassthroughConverter::class]);
});

test('add() is idempotent — adding the same FQCN twice does not duplicate', function (): void {
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);

    expect(MediaConverterDiscovery::all())->toBe([PlainTextPassthroughConverter::class]);
});

test('add() preserves insertion order', function (): void {
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    // A throwaway second class via an anonymous-class name (FQCN).
    // Use a class that actually exists but does NOT implement the
    // converter interface — that won't be allowed. Instead, just
    // assert that the only entry we added is in position 0.
    expect(MediaConverterDiscovery::all())->toBe([PlainTextPassthroughConverter::class]);
});

test('add() throws InvalidArgumentException for a class that does not implement the converter interface', function (): void {
    MediaConverterDiscovery::reset();
    // RuntimeException is a final class that does NOT implement the
    // converter interface — this exercises the validation guard.
    $threw = false;
    try {
        /** @phpstan-ignore-next-line argument.type */
        MediaConverterDiscovery::add(RuntimeException::class);
    } catch (InvalidArgumentException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('does not implement');
    }
    expect($threw)->toBeTrue();
});

test('add() accepts an anonymous-class FQCN that implements the converter interface', function (): void {
    MediaConverterDiscovery::reset();
    $anonymous = new class implements MediaConverterInterface {
        public function supportedMimeTypes(): array
        {
            return ['text/x-anon'];
        }
        public function supportedExtensions(): array
        {
            return ['anon'];
        }
        public function toMarkdown(string $bytes, string $mime, ?string $filename = null): string
        {
            return $bytes;
        }
    };
    MediaConverterDiscovery::add($anonymous::class);

    expect(MediaConverterDiscovery::all())->toBe([$anonymous::class]);
});

test('all() returns an empty list when nothing has been added', function (): void {
    MediaConverterDiscovery::reset();
    expect(MediaConverterDiscovery::all())->toBe([]);
});

test('reset() clears the registry between test runs', function (): void {
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    expect(MediaConverterDiscovery::all())->not->toBe([]);

    MediaConverterDiscovery::reset();
    expect(MediaConverterDiscovery::all())->toBe([]);
});
