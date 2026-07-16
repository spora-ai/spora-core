<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive\Converters;

use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use Mockery;
use Spora\Services\MediaArchive\Converters\PdfToMarkdownConverter;

/**
 * Plan §12 B2b — PdfToMarkdownConverter coverage.
 */
test('PdfToMarkdownConverter advertises application/pdf and pdf', function (): void {
    $parser = Mockery::mock(PdfToMarkdownParser::class);
    $converter = new PdfToMarkdownConverter($parser);
    expect($converter->supportedMimeTypes())->toBe(['application/pdf']);
    expect($converter->supportedExtensions())->toBe(['pdf']);
});

test('PdfToMarkdownConverter returns trimmed text from the parser', function (): void {
    $parser = Mockery::mock(PdfToMarkdownParser::class);
    $parser->shouldReceive('parseContent')
        ->once()
        ->with('PDF-BYTES')
        ->andReturn("  hello world\n\n");
    $converter = new PdfToMarkdownConverter($parser);
    expect($converter->toMarkdown('PDF-BYTES', 'application/pdf', null))->toBe('hello world');
});

test('PdfToMarkdownConverter throws when the parser throws', function (): void {
    $parser = Mockery::mock(PdfToMarkdownParser::class);
    $parser->shouldReceive('parseContent')
        ->once()
        ->andThrow(new \RuntimeException('corrupt pdf'));
    $converter = new PdfToMarkdownConverter($parser);
    expect(static fn(): string => $converter->toMarkdown('PDF-BYTES', 'application/pdf', null))
        ->toThrow(\RuntimeException::class, 'corrupt pdf');
});

test('PdfToMarkdownConverter rejects bad bytes via the parser facade', function (): void {
    $parser = Mockery::mock(PdfToMarkdownParser::class);
    $parser->shouldReceive('parseContent')
        ->once()
        ->with('NOT-A-PDF')
        ->andThrow(new \RuntimeException('not a pdf'));
    $converter = new PdfToMarkdownConverter($parser);
    expect(static fn(): string => $converter->toMarkdown('NOT-A-PDF', 'application/pdf', null))
        ->toThrow(\RuntimeException::class, 'not a pdf');
});