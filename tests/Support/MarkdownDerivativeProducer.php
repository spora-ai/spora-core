<?php

declare(strict_types=1);

namespace Tests\Support;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * Stands in for a producer that emits an ordinary, non-script-capable
 * format.
 *
 * Exists so the derivative gate can be shown to be narrow: the gate refuses
 * script-capable output the operator has not permitted, and leaves every
 * other producer output alone.
 */
final class MarkdownDerivativeProducer implements MediaDerivativeProducerInterface
{
    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return ['text/x-typst'];
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return ['md'];
    }

    public function pluginSlug(): string
    {
        return 'tests-markdown-plugin';
    }

    public function operationName(): string
    {
        return 'typst.renderMd';
    }

    /** @param array<string, mixed> $options */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        return new DerivativeOutput(bytes: '# rendered', mime: 'text/markdown');
    }
}
