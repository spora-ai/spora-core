<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use ReflectionProperty;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
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
    /**
     * The ceiling on one decoded PDF stream.
     *
     * The parser decompresses FlateDecode streams with no limit of its
     * own, so a small stream can ask for an unbounded allocation inside
     * the request that uploaded the PDF. The limit is the parser
     * library's own decode-memory limit: a stream over the ceiling is
     * truncated rather than decoded whole, and the document loses that
     * stream instead of the request losing its memory budget.
     */
    public const MAX_DECODED_STREAM_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly PdfToMarkdownParser $parser,
        private readonly int $maxDecodedStreamBytes = self::MAX_DECODED_STREAM_BYTES,
    ) {}

    /**
     * @throws Throwable whatever the parser raises for unparseable input.
     */
    public function extract(string $bytes): string
    {
        $this->applyDecodeLimit();

        // A stream the budget refused is reported by the parser library
        // as a warning on its way to a failed parse. The caller sees the
        // failed parse; the warning is the library's own bookkeeping.
        set_error_handler(static function (int $severity, string $message): bool {
            return str_contains($message, 'gzuncompress');
        });

        try {
            return trim($this->parser->parseContent($bytes));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Give the parser a decode-memory limit.
     *
     * The markdown wrapper builds its PDF parser in its own constructor
     * and offers no seam for a configured one, and the wrapper is a
     * locked dependency — so the limit is installed on the parser it
     * keeps. If the wrapper stops exposing that parser, the reflection
     * fails and the extraction fails with it: no PDF is parsed without a
     * limit.
     */
    private function applyDecodeLimit(): void
    {
        $config = new Config();
        $config->setDecodeMemoryLimit($this->maxDecodedStreamBytes);

        (new ReflectionProperty(PdfToMarkdownParser::class, 'parser')) // NOSONAR php:S3011 — see comment above
            ->setValue($this->parser, new Parser([], $config));
    }
}
