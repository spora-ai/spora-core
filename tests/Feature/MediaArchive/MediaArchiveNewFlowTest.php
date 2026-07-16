<?php

declare(strict_types=1);

namespace Tests\Feature\MediaArchive;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter;
use Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Tests\Support\MediaArchiveTestSupport;

/**
 * Coverage for the new media-upload / extraction / sharing surface
 * shipped alongside this PR. Each test below targets a piece of code that
 * the original MediaArchive feature set did not exercise.
 */
afterEach(function (): void {
    // Reset the discovery list so test ordering does not leak state
    // across suites.
    MediaConverterDiscovery::reset();
});

test('PdfToMarkdownConverter advertises application/pdf and pdf (via Mockery)', function (): void {
    // The real ctor requires a parser; use a mock to assert the
    // interface contract.
    $parser = \Mockery::mock(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class);
    $converter = new PdfToMarkdownConverter($parser);
    expect($converter->supportedMimeTypes())->toBe(['application/pdf']);
    expect($converter->supportedExtensions())->toBe(['pdf']);
});

test('PlainTextPassthroughConverter advertises the static text allowlist', function (): void {
    $converter = new PlainTextPassthroughConverter();
    $mimes = $converter->supportedMimeTypes();
    expect($mimes)->toContain('text/plain');
    expect($mimes)->toContain('text/csv');
    expect($mimes)->toContain('application/json');
    expect($mimes)->toContain('application/yaml');
    expect($converter->supportedExtensions())->toContain('txt');
});

test('PdfToMarkdownConverter trims and returns text from bytes (mocked parser)', function (): void {
    $parser = \Mockery::mock(\Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class);
    $parser->shouldReceive('parseContent')
        ->once()
        ->with("  hello world\n\n")
        ->andReturn("  hello world\n\n");
    $converter = new PdfToMarkdownConverter($parser);
    $result = $converter->toMarkdown("  hello world\n\n", 'application/pdf', null);
    expect($result)->toBe('hello world');
});

test('PlainTextPassthroughConverter returns bytes trimmed', function (): void {
    $converter = new PlainTextPassthroughConverter();
    $result = $converter->toMarkdown("  csv header\nvalue\n", 'text/csv', null);
    expect($result)->toBe('csv header' . PHP_EOL . 'value');
});

test('MediaConverterRegistry findFor returns the first matching converter by mime', function (): void {
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $found = $registry->findFor('text/plain', null);
    expect($found)->not->toBeNull();
    expect($found)->toBeInstanceOf(PlainTextPassthroughConverter::class);
});

test('MediaConverterRegistry findFor returns null when no converter matches', function (): void {
    MediaConverterDiscovery::reset();
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $found = $registry->findFor('application/x-unknown', 'whatever.xyz');
    expect($found)->toBeNull();
});

test('MediaConverterRegistry convert returns null when no converter matches', function (): void {
    MediaConverterDiscovery::reset();
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $result = $registry->convert('bytes', 'application/x-unknown', 'whatever.xyz');
    expect($result)->toBeNull();
});

test('MediaConverterRegistry convert returns trimmed text for matching mime', function (): void {
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $result = $registry->convert("  hello  ", 'text/plain', null);
    expect($result)->toBe('hello');
});

test('MediaConverterDiscovery is idempotent — adding the same class twice keeps the list size', function (): void {
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    expect(count(MediaConverterDiscovery::all()))->toBe(1);
});

test('MediaConverterRegistry allSupportedMimeTypes aggregates registered converters', function (): void {
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    $registry = MediaArchiveTestSupport::buildConverterRegistry();
    $mimes = $registry->allSupportedMimeTypes();
    expect($mimes)->toContain('text/plain');
    expect($mimes)->toContain('text/csv');
});