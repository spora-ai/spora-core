<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * Test double for {@see MediaDerivativeProducerInterface}.
 *
 * Emits deterministic bytes (a 6-byte PNG header marker so the wire
 * format's mime detection picks the right extension) and tags itself
 * with the `fake-derivative-producer` / `render` attribution so
 * tests can assert on the {@see \Spora\Services\MediaArchive\MediaDerivativeService}'s
 * `media_derivatives` join row contents.
 */
final class FakeDerivativeProducer implements MediaDerivativeProducerInterface
{
    public function __construct(
        private readonly string $pluginSlug = 'fake-derivative-producer',
        private readonly string $operation = 'render',
        private readonly array $sources = ['image/png'],
        private readonly array $outputs = ['pdf'],
        private readonly string $outMime = 'application/pdf',
        private readonly bool $throwOnProduce = false,
    ) {}

    public function supportedSourceFormats(): array
    {
        return $this->sources;
    }

    public function supportedDerivativeFormats(): array
    {
        return $this->outputs;
    }

    public function pluginSlug(): string
    {
        return $this->pluginSlug;
    }

    public function operationName(): string
    {
        return $this->operation;
    }

    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        if ($this->throwOnProduce) {
            throw new RuntimeException('forced producer failure');
        }
        return new DerivativeOutput(
            bytes: "%PDF-fake-{$source->id}-{$format}",
            mime: $this->outMime,
        );
    }
}
