<?php

declare(strict_types=1);

namespace Tests\Support;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * A producer whose extraction succeeds but yields nothing — the
 * scanned-PDF-with-no-OCR-layer case.
 *
 * Distinct from {@see ThrowingTextDerivativeProducer} (which fails) and
 * from a configured {@see TextDerivativeProducer} (which cannot be
 * configured through
 * {@see \Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery::add()},
 * since the container materialises a registered producer with
 * `new $id()` and constructor arguments cannot travel through the
 * registration).
 */
final class EmptyTextDerivativeProducer implements MediaDerivativeProducerInterface
{
    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return ['application/pdf'];
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return ['md'];
    }

    public function pluginSlug(): string
    {
        return 'tests-empty-derivative';
    }

    public function operationName(): string
    {
        return 'text.extract';
    }

    /** @param array<string, mixed> $options */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        return new DerivativeOutput(bytes: '', mime: 'text/markdown');
    }
}
