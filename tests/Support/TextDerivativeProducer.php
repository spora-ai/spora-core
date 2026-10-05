<?php

declare(strict_types=1);

namespace Tests\Support;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * Test double for the `md` extraction the archive mints for a binary
 * document.
 *
 * Mirrors what a real `PdfToMarkdownProducer` / `DocxToMarkdownProducer`
 * does — read the source, return the extracted text — without a parser
 * dependency, so the LLM-facing read path (message builder, `get_source`,
 * the retention sweep, the attach-time mint) can be exercised on any
 * source mime.
 *
 * The emitted body is fixed at construction rather than read from the
 * source, so a test can pin the exact bytes it expects to find inlined.
 * Pass an empty string for the scanned-document case (extraction succeeds,
 * yields nothing). See {@see ThrowingTextDerivativeProducer} for the
 * corrupt-document case.
 */
final class TextDerivativeProducer implements MediaDerivativeProducerInterface
{
    /**
     * @param string               $body       Bytes the extraction yields.
     * @param list<string>         $sources    Source formats this double accepts.
     * @param string               $pluginSlug Attribution written to `producer_plugin`.
     * @param string               $operation  Attribution written to `producer_operation`.
     */
    public function __construct(
        private readonly string $body = 'extracted text',
        private readonly array $sources = ['application/pdf'],
        private readonly string $pluginSlug = 'tests-text-derivative',
        private readonly string $operation = 'text.extract',
    ) {}

    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return $this->sources;
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return ['md'];
    }

    public function pluginSlug(): string
    {
        return $this->pluginSlug;
    }

    public function operationName(): string
    {
        return $this->operation;
    }

    /** @param array<string, mixed> $options */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        return new DerivativeOutput(bytes: $this->body, mime: 'text/markdown');
    }
}
