<?php

declare(strict_types=1);

namespace Spora\Services\MediaArchive;

interface MediaMimeRefinerInterface
{
    /**
     * Upgrade a coarse or wrong sniffed MIME to a more specific one.
     * Returns null to decline. Pure, never throws.
     *
     * Implementations MUST be constructible without arguments:
     * {@see MimeSniffer} instantiates them statically and has no container
     * to resolve a refiner's collaborators with — the same constraint as
     * {@see Producers\ImageDerivativeProducer}. A refiner that needs one has
     * to reach for it statically itself.
     */
    public function refine(string $bytes, ?string $filename, string $sniffedMime): ?string;
}
