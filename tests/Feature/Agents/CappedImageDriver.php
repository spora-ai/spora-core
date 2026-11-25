<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use RuntimeException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\RequestLimitAwareDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

/**
 * A driver whose endpoint takes a stated number of bytes: a ceiling for
 * one base64 image, and one for the whole request.
 *
 * The shipped drivers state their provider's real ceilings, which are
 * millions of bytes — a test that wrote images that large to prove the
 * ceilings bind would be slow. This one states small ones, so the rules
 * can be exercised with payloads a test can afford.
 */
final class CappedImageDriver implements LLMDriverInterface, RequestLimitAwareDriverInterface
{
    public function __construct(
        private readonly int $maxEncodedImageBytes,
        private readonly int $maxRequestBytes = 1_000_000_000,
        private readonly int $maxImagesPerRequest = 1_000,
    ) {}

    public function complete(LLMRequest $request): LLMResponse
    {
        throw new RuntimeException('This driver is never asked to complete a request.');
    }

    public function getProviderName(): string
    {
        return 'test_capped';
    }

    public function getModelName(): string
    {
        return 'test-capped-model';
    }

    public function supportsImageInput(): bool
    {
        return true;
    }

    public function maxEncodedImageBytes(): int
    {
        return $this->maxEncodedImageBytes;
    }

    public function maxRequestBytes(): int
    {
        return $this->maxRequestBytes;
    }

    public function maxImagesPerRequest(): int
    {
        return $this->maxImagesPerRequest;
    }
}
