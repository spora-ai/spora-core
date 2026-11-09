<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MediaArchive;

use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use ReflectionProperty;
use Smalot\PdfParser\Parser;
use Spora\Services\MediaArchive\PdfMarkdownExtractor;

/**
 * A one-page PDF whose content stream carries `$text`.
 *
 * The stream is FlateDecode-compressed, which is the case the decode
 * budget exists for.
 */
function pdfWithText(string $text): string
{
    $compressed = (string) gzcompress($text);
    $objects = [
        "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
        "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
        "4 0 obj\n<< /Length " . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "\nendstream\nendobj\n",
        "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

    return $pdf;
}

test('extract reads the text out of a PDF', function (): void {
    $extractor = new PdfMarkdownExtractor(new PdfToMarkdownParser());

    $markdown = $extractor->extract(pdfWithText('BT /F1 12 Tf 100 700 Td (Hello PDF) Tj ET'));

    expect($markdown)->toContain('Hello PDF');
});

test('the extractor installs a decode budget on the parser', function (): void {
    // The parser decompresses FlateDecode streams with no limit of its
    // own, so a small stream can ask for an unbounded allocation.
    $parser = new PdfToMarkdownParser();
    (new PdfMarkdownExtractor($parser))->extract(pdfWithText('BT /F1 12 Tf 100 700 Td (Hello PDF) Tj ET'));

    $inner = (new ReflectionProperty(PdfToMarkdownParser::class, 'parser'))->getValue($parser);
    expect($inner)->toBeInstanceOf(Parser::class);
    assert($inner instanceof Parser);

    expect($inner->getConfig()->getDecodeMemoryLimit())->toBe(PdfMarkdownExtractor::MAX_DECODED_STREAM_BYTES);
});

test('extract does not decode a stream over the budget', function (): void {
    $extractor = new PdfMarkdownExtractor(new PdfToMarkdownParser(), 16);

    // The stream is longer than the budget, so it is truncated instead of
    // decoded whole and the page has no text left.
    expect($extractor->extract(pdfWithText('BT /F1 12 Tf 100 700 Td (Hello PDF) Tj ET')))->toBe('');
});
