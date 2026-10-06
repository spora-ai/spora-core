<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Services\MediaArchive\Concerns\DiscoversRegistrations;

/**
 * Static registry of {@see MediaDerivativeProducerInterface} FQCNs, re-read
 * per call by {@see MediaDerivativeService} — `findProducer()`,
 * `producerSourceMimeTypes()` and `availableOptionsFor()` each walk the list
 * again, so a plugin that registers late is still picked up mid-request.
 *
 * Shares the registration pattern with
 * {@see MediaMimeRefinerDiscovery} — one body,
 * {@see DiscoversRegistrations} — so plugin authors only learn one shape.
 */
final class MediaDerivativeProducerDiscovery
{
    /** @use DiscoversRegistrations<MediaDerivativeProducerInterface> */
    use DiscoversRegistrations;

    /**
     * @return class-string<MediaDerivativeProducerInterface>
     */
    protected static function registrationContract(): string
    {
        return MediaDerivativeProducerInterface::class;
    }
}
