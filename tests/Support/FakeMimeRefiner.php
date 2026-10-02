<?php

declare(strict_types=1);

namespace Tests\Support;

use Spora\Services\MediaArchive\MediaMimeRefinerInterface;

/**
 * Test double for {@see MediaMimeRefinerInterface}.
 *
 * Unlike {@see FakeDerivativeProducer} its collaborators are *static*:
 * {@see \Spora\Services\MediaArchive\MimeSniffer} instantiates every
 * registered refiner with `new $class()`, so the test has no seam
 * through which to inject a response. {@see respondWith()} therefore
 * configures the double and {@see $calls} captures what
 * {@see self::refine()} was handed, which is how the tests assert both
 * the returned MIME and the arguments the refiner was given.
 */
final class FakeMimeRefiner implements MediaMimeRefinerInterface
{
    /**
     * Arguments of every `refine()` call, oldest first.
     *
     * @var list<array{bytes: string, filename: ?string, sniffedMime: string}>
     */
    public static array $calls = [];

    private static ?string $response = null;

    /** Set what the next (and every subsequent) `refine()` call returns. */
    public static function respondWith(?string $mime): void
    {
        self::$response = $mime;
    }

    /** Forget the configured response and the recorded calls. */
    public static function reset(): void
    {
        self::$response = null;
        self::$calls = [];
    }

    public function refine(string $bytes, ?string $filename, string $sniffedMime): ?string
    {
        self::$calls[] = [
            'bytes' => $bytes,
            'filename' => $filename,
            'sniffedMime' => $sniffedMime,
        ];

        return self::$response;
    }
}
