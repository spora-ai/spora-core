<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Spora\Services\MediaArchive\MediaMimeRefinerInterface;

/**
 * A refiner that blows up, standing in for a plugin shipping a broken one.
 *
 * The registry is a static list and the sniffer instantiates entries with
 * `new $class()`, so the only way to reproduce a misbehaving plugin from a
 * test is a class whose `refine()` fails unconditionally.
 */
final class ThrowingMimeRefiner implements MediaMimeRefinerInterface
{
    public const MESSAGE = 'refiner exploded';

    public function refine(string $bytes, ?string $filename, string $sniffedMime): ?string
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
