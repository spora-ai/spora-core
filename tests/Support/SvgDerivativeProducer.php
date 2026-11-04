<?php

declare(strict_types=1);

namespace Tests\Support;

use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * Stands in for a producer that emits SVG — the Typst plugin's
 * `renderSvg()` does exactly this.
 *
 * The upload gate refuses `image/svg+xml`, so a producer emitting it used
 * to be a way around that refusal: the derivative path never consulted the
 * allowlist, and the row it wrote was served inline as
 * `Content-Type: image/svg+xml` with no `Content-Disposition` and no
 * `X-Content-Type-Options`.
 */
final class SvgDerivativeProducer implements MediaDerivativeProducerInterface
{
    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return ['text/x-typst'];
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return ['svg'];
    }

    public function pluginSlug(): string
    {
        return 'tests-svg-plugin';
    }

    public function operationName(): string
    {
        return 'typst.renderSvg';
    }

    /** @param array<string, mixed> $options */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        return new DerivativeOutput(
            bytes: '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            mime: 'image/svg+xml',
        );
    }
}
