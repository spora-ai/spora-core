<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

/**
 * Contract for file-to-markdown converters.
 *
 * Plugins ship their own implementations by tagging the class with
 * `media_converter` in their `register(ContainerBuilder)` hook — see
 * `docs/07_plugins.md` and the "Shipping a media converter" section
 * of the Media Archive upload feature plan. Core ships a PDF
 * converter and a text-passthrough converter; everything else
 * (DOCX, XLSX, …) is plug-in territory.
 *
 * Contract details:
 * - `supportedMimeTypes()` and `supportedExtensions()` together define what
 *   the converter handles. The registry prefers MIME-type matches; extensions
 *   are a fallback when MIME sniffing is ambiguous.
 * - `toMarkdown()` is a pure function: same bytes in → same markdown out. The
 *   upload pipeline reuses the bytes after conversion, so do not mutate.
 * - Throw on unrecoverable parsing errors. The caller (MediaArchiveService)
 *   catches, logs, and leaves `markdown_content` NULL — the upload still
 *   succeeds. Returning an empty string is the right answer for files with
 *   no extractable text (e.g. scanned PDFs without an OCR layer).
 */
interface MediaConverterInterface
{
    /**
     * @return list<string> MIME types this converter handles (lowercase).
     */
    public function supportedMimeTypes(): array;

    /**
     * @return list<string> File extensions (without dot) this converter handles.
     */
    public function supportedExtensions(): array;

    /**
     * Convert raw bytes into clean Markdown text.
     *
     * @param string      $bytes    The file contents (already in memory).
     * @param string      $mime     The sniffed MIME type (never the client header).
     * @param string|null $filename The original filename, if known. Useful for
     *                              format hints when MIME sniffing is ambiguous
     *                              (e.g. ".doc" vs ".docx").
     */
    public function toMarkdown(string $bytes, string $mime, ?string $filename = null): string;
}
