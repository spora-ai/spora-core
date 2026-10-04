<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive\Producers;

use InvalidArgumentException;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaAssetReader;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;
use Spora\Services\MediaArchive\PdfMarkdownExtractor;
use Throwable;

/**
 * PDF → `md` derivative producer.
 *
 * Ships in core rather than a plugin so a fresh install accepts PDFs and
 * can read them back without installing anything: `application/pdf` is
 * allowlisted at the upload gate precisely because this producer declares
 * it (see {@see \Spora\Services\MediaArchive\MediaAllowedTypesService}),
 * and moving the producer to a package would make that allowlist
 * conditional on an extra install.
 *
 * The extraction itself is delegated to
 * {@see \Spora\Services\MediaArchive\PdfMarkdownExtractor}, the same
 * helper `ReadUrlTool::fetch_pdf` uses — a second extractor here would be
 * the exact duplication this producer replaced.
 *
 * The `md` format is the whole point: the archive's invariant is that a
 * text-ish source within the inline budget is its own text and anything
 * else gets an `md` derivative. A PDF is not text-ish, so this producer
 * is what turns "the LLM cannot see this PDF" into "the LLM sees its
 * text" — see `AttachmentRowRenderer::buildTextBlock()`.
 */
final class PdfToMarkdownProducer implements MediaDerivativeProducerInterface
{
    /** The one derivative format this producer emits. */
    private const MARKDOWN = 'md';

    private const TARGET_MIME = 'text/markdown';

    public function __construct(
        private readonly PdfMarkdownExtractor $extractor,
        private readonly MediaAssetReader $reader,
    ) {}

    public function pluginSlug(): string
    {
        return 'spora-core';
    }

    public function operationName(): string
    {
        return 'pdf.to_markdown';
    }

    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return ['application/pdf', 'pdf'];
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return [self::MARKDOWN];
    }

    /**
     * @param array<string, mixed> $options
     * @throws InvalidArgumentException when the format is not `md`.
     * @throws Throwable from the parser on unparseable input — the
     *         caller decides whether that is a logged warning (ingest) or
     *         a 422 (HTTP controller).
     */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        if (strtolower($format) !== self::MARKDOWN) {
            throw new InvalidArgumentException(sprintf(
                'PdfToMarkdownProducer: only the "%s" derivative format is supported, got "%s"',
                self::MARKDOWN,
                $format,
            ));
        }

        $payload = $this->reader->readAsset($source->id, null);
        if ($payload === null || !isset($payload['bytes'])) {
            throw new InvalidArgumentException(sprintf(
                'PdfToMarkdownProducer: MediaAsset %s has no readable payload (storage_mode=%s)',
                $source->id,
                (string) $source->storage_mode,
            ));
        }

        return new DerivativeOutput(
            bytes: $this->extractor->extract($payload['bytes']),
            mime: self::TARGET_MIME,
        );
    }
}
