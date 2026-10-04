<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use Throwable;

/**
 * The single PDF → Markdown extraction path in core, built on
 * `iamgerwin/php-pdf-to-markdown-parser`.
 *
 * Two consumers: {@see Producers\PdfToMarkdownProducer}, which archives
 * the extraction as an `md` derivative of an uploaded PDF, and
 * {@see \Spora\Tools\ReadUrlTool::fetch_pdf}, which extracts a remote PDF
 * the LLM asked for without archiving it. They shared a
 * `MediaConverterInterface` instance before, which is why a
 * `markdown_content` grep never surfaced the second consumer — one
 * implementation is what stops them from drifting.
 *
 * A parser throw (corrupt PDF, no text layer) propagates verbatim. The
 * producer's callers decide what that means: the ingest pipeline logs a
 * warning and keeps the upload, the tool returns a `ToolResult::fail()`.
 * An empty string is the right answer for a scanned PDF with no OCR
 * layer.
 */
final class PdfMarkdownExtractor
{
    public function __construct(private readonly PdfToMarkdownParser $parser) {}

    /**
     * @throws Throwable whatever the parser raises for unparseable input.
     */
    public function extract(string $bytes): string
    {
        return trim($this->parser->parseContent($bytes));
    }
}
