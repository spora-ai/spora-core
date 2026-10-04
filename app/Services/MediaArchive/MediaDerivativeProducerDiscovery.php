<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Services\MediaArchive\Concerns\DiscoversRegistrations;

/**
 * Static registry of {@see MediaDerivativeProducerInterface} FQCNs, read by
 * {@see MediaDerivativeProducerDiscovery}'s consumer at construction.
 *
 * Mirrors {@see MediaConverterDiscovery} exactly so plugin authors only learn
 * one registration pattern; the shared body is
 * {@see DiscoversRegistrations}.
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
