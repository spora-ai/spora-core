<?php

declare(strict_types=1);

namespace Tests\Support;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * Stands in for a plugin's Typst producer in the upload-allowlist tests.
 *
 * Exists as a named class rather than a configured
 * {@see TextDerivativeProducer} because
 * {@see \Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery::add()}
 * takes a class-string and the container materialises it with
 * `new $id()` — constructor arguments cannot travel through the
 * registration.
 *
 * Registering a producer is what makes a document type uploadable: the
 * allowlist unions every registered producer's `supportedSourceFormats()`.
 */
final class TypstSourceDerivativeProducer implements MediaDerivativeProducerInterface
{
    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return ['text/x-typst'];
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return ['pdf', 'png'];
    }

    public function pluginSlug(): string
    {
        return 'tests-typst-plugin';
    }

    public function operationName(): string
    {
        return 'typst.render';
    }

    /** @param array<string, mixed> $options */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        return new DerivativeOutput(bytes: '%PDF-typst', mime: 'application/pdf');
    }
}
