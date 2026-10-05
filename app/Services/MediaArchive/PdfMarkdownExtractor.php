<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use Throwable;

/**
 * The single PDF → Markdown extraction path in core.
 *
 * Two consumers: {@see Producers\PdfToMarkdownProducer}, which archives the
 * extraction as an `md` derivative of an uploaded PDF, and
 * {@see \Spora\Tools\ReadUrlTool::fetch_pdf}, which extracts a remote PDF
 * without archiving it. They used to share a `MediaConverterInterface`
 * instance, which is why a `markdown_content` grep never surfaced the
 * second consumer — one implementation is what stops them drifting.
 *
 * A parser throw (corrupt PDF, no text layer) propagates verbatim; callers
 * decide what it means (ingest logs a warning, the tool returns a
 * `ToolResult::fail()`). An empty string is correct for a scanned PDF with
 * no OCR layer.
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
