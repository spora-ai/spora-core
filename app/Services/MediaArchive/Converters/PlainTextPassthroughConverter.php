<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Converters;

use Spora\Services\MediaArchive\MediaConverterInterface;

/**
 * No-op converter for text files the LLM can read directly.
 *
 * Text formats (TXT, MD, CSV, JSON, HTML, XML, YAML) need no parsing —
 * the bytes ARE the content. The converter returns the bytes as-is,
 * trimmed, ready to write into `markdown_content`.
 *
 * Listed in the upload allowlist (see {@see MediaAllowedTypesService})
 * so the upload UI accepts these MIME types and the registry can find
 * a converter for them. A future plugin could override this with a
 * markdown-flavored preprocessor.
 */
final class PlainTextPassthroughConverter implements MediaConverterInterface
{
    /** @var list<string> */
    private const SUPPORTED_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'text/html',
        'application/json',
        'application/xml',
        'text/xml',
        'application/yaml',
        'text/yaml',
    ];

    /** @var list<string> */
    private const SUPPORTED_EXTENSIONS = [
        'txt', 'md', 'markdown', 'csv', 'html', 'htm', 'json', 'xml', 'yaml', 'yml',
    ];

    /** @return list<string> */
    public function supportedMimeTypes(): array
    {
        return self::SUPPORTED_MIME_TYPES;
    }

    /** @return list<string> */
    public function supportedExtensions(): array
    {
        return self::SUPPORTED_EXTENSIONS;
    }

    public function toMarkdown(string $bytes, string $mime, ?string $filename = null): string
    {
        return trim($bytes);
    }
}
