<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive\Converters;

use Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter;

/**
 * Plan §12 B2b — PlainTextPassthroughConverter coverage.
 */
test('PlainTextPassthroughConverter advertises the static text allowlist', function (): void {
    $converter = new PlainTextPassthroughConverter();
    expect($converter->supportedMimeTypes())->toContain('text/plain');
    expect($converter->supportedMimeTypes())->toContain('text/markdown');
    expect($converter->supportedMimeTypes())->toContain('text/csv');
    expect($converter->supportedMimeTypes())->toContain('application/json');
    expect($converter->supportedMimeTypes())->toContain('application/yaml');
});

test('PlainTextPassthroughConverter advertises text extensions', function (): void {
    $converter = new PlainTextPassthroughConverter();
    expect($converter->supportedExtensions())->toContain('txt');
    expect($converter->supportedExtensions())->toContain('md');
    expect($converter->supportedExtensions())->toContain('json');
});

test('PlainTextPassthroughConverter trims surrounding whitespace', function (): void {
    $converter = new PlainTextPassthroughConverter();
    expect($converter->toMarkdown("  hello world\n", 'text/plain', null))->toBe('hello world');
});

test('PlainTextPassthroughConverter preserves internal newlines', function (): void {
    $converter = new PlainTextPassthroughConverter();
    expect($converter->toMarkdown("header\nvalue\n", 'text/csv', null))
        ->toBe('header' . PHP_EOL . 'value');
});

test('PlainTextPassthroughConverter returns empty string for whitespace-only bytes', function (): void {
    $converter = new PlainTextPassthroughConverter();
    expect($converter->toMarkdown("   \n\n  \n", 'text/plain', null))->toBe('');
});