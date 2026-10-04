<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

use Spora\Services\MediaArchive\Concerns\DiscoversRegistrations;

/**
 * Static registry of {@see MediaMimeRefinerInterface} FQCNs, read by
 * {@see MimeSniffer::sniffFromBytes()} on every byte sniff.
 *
 * One of the three media-archive registries, sharing the registration
 * pattern with {@see MediaDerivativeProducerDiscovery} through
 * {@see DiscoversRegistrations}. Core ships no refiner: the seam exists
 * for plugins whose formats an old libmagic reports as a coarser
 * container type (OOXML as `application/zip`).
 */
final class MediaMimeRefinerDiscovery
{
    /** @use DiscoversRegistrations<MediaMimeRefinerInterface> */
    use DiscoversRegistrations;

    /**
     * @return class-string<MediaMimeRefinerInterface>
     */
    protected static function registrationContract(): string
    {
        return MediaMimeRefinerInterface::class;
    }
}
