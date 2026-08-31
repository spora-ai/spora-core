<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * Test double that always throws from `produce()`. Used by
 * {@see \Tests\Feature\Http\MediaDerivativeControllerTest} to lock
 * the 422 status contract.
 */
final class ThrowingDerivativeProducer implements MediaDerivativeProducerInterface
{
    public function supportedSourceFormats(): array
    {
        return ['image/png'];
    }

    public function supportedDerivativeFormats(): array
    {
        return ['pdf'];
    }

    public function pluginSlug(): string
    {
        return 'throwing-derivative-producer';
    }

    public function operationName(): string
    {
        return 'op';
    }

    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        throw new RuntimeException('forced producer failure');
    }
}
