<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Converters;

use Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser;
use Spora\Services\MediaArchive\MediaConverterInterface;

/**
 * PDF → Markdown converter built on `iamgerwin/php-pdf-to-markdown-parser`.
 *
 * The library returns clean Markdown from a PDF byte stream. Errors
 * (corrupt PDF, missing OCR layer on scanned documents) propagate as
 * `Throwable`; the caller in MediaArchiveService logs and leaves
 * `markdown_content` NULL — the upload still succeeds.
 */
final class PdfToMarkdownConverter implements MediaConverterInterface
{
    public function __construct(private readonly PdfToMarkdownParser $parser)
    {
    }

    /** @return list<string> */
    public function supportedMimeTypes(): array
    {
        return ['application/pdf'];
    }

    /** @return list<string> */
    public function supportedExtensions(): array
    {
        return ['pdf'];
    }

    public function toMarkdown(string $bytes, string $mime, ?string $filename = null): string
    {
        return trim($this->parser->parseContent($bytes));
    }
}
