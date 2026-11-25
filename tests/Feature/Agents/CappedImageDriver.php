<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use RuntimeException;
use Spora\Drivers\EncodedImageLimitAwareDriverInterface;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

/**
 * A driver whose endpoint takes a stated number of base64 bytes per image.
 *
 * The shipped drivers state their provider's real ceiling, which is
 * millions of bytes — a test that wrote an image that large to prove the
 * ceiling binds would be slow. This one states a small one, so the rule
 * can be exercised with a payload a test can afford.
 */
final class CappedImageDriver implements LLMDriverInterface, EncodedImageLimitAwareDriverInterface
{
    public function __construct(private readonly int $maxEncodedBytes) {}

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
        return $this->maxEncodedBytes;
    }
}
