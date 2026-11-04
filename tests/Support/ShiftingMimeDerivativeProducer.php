<?php

declare(strict_types=1);

namespace Tests\Support;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * A producer whose MIME changes between calls, so the same natural key
 * refreshes an existing derivative with a different type.
 *
 * The derivative's natural key is `(parent_id, format, producer_plugin,
 * producer_operation)`, so a re-render only reaches the refresh path when
 * the producer answers for the *same* format. A producer whose output type
 * changes between calls is what makes that reachable — a producer that
 * always emitted the same MIME could never refresh a row's type.
 */
final class ShiftingMimeDerivativeProducer implements MediaDerivativeProducerInterface
{
    /** @var list<string> The MIMEs to emit, in order; the last one repeats. */
    private static array $mimes = [];

    /** @var list<string> */
    private static array $sources = [];

    /**
     * @param list<string> $mimes     The MIMEs to emit, in order.
     * @param list<string> $sources   The source formats to accept.
     */
    public static function configure(array $mimes, array $sources = ['text/x-typst']): void
    {
        self::$mimes = $mimes;
        self::$sources = $sources;
    }

    public static function reset(): void
    {
        self::$mimes = [];
        self::$sources = [];
    }

    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return self::$sources === [] ? ['text/x-typst'] : self::$sources;
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return ['render'];
    }

    public function pluginSlug(): string
    {
        return 'tests-shifting-plugin';
    }

    public function operationName(): string
    {
        return 'render';
    }

    /** @param array<string, mixed> $options */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        $mime = self::$mimes === [] ? 'text/markdown' : self::$mimes[0];

        return new DerivativeOutput(bytes: 'bytes for ' . $mime, mime: $mime);
    }
}
