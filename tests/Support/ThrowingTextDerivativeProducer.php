<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Spora\Models\MediaAsset;
use Spora\Services\MediaArchive\DerivativeOutput;
use Spora\Services\MediaArchive\MediaDerivativeProducerInterface;

/**
 * A {@see MediaDerivativeProducerInterface} whose `produce()` always
 * throws — the corrupt-document path.
 *
 * The archive's best-effort contract is that this degrades the extraction
 * and never the upload, so the tests that need it assert on the *stored
 * asset*, not on a thrown exception.
 */
final class ThrowingTextDerivativeProducer implements MediaDerivativeProducerInterface
{
    /**
     * @param list<string> $sources Source formats this double accepts.
     */
    public function __construct(
        private readonly string $message = 'corrupt document',
        private readonly array $sources = ['application/pdf'],
    ) {}

    /** @return list<string> */
    public function supportedSourceFormats(): array
    {
        return $this->sources;
    }

    /** @return list<string> */
    public function supportedDerivativeFormats(): array
    {
        return ['md'];
    }

    public function pluginSlug(): string
    {
        return 'tests-throwing-derivative';
    }

    public function operationName(): string
    {
        return 'text.extract';
    }

    /** @param array<string, mixed> $options */
    public function produce(MediaAsset $source, string $format, array $options = []): DerivativeOutput
    {
        throw new RuntimeException($this->message);
    }
}
