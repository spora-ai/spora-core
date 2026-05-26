<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\ManifestPlugin;

use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

final class ManifestDriver implements LLMDriverInterface
{
    public function complete(LLMRequest $request): LLMResponse
    {
        throw new \RuntimeException('Test driver - not implemented');
    }

    public function getProviderName(): string
    {
        return 'manifest_driver';
    }

    public function getModelName(): string
    {
        return 'test-manifest-model';
    }
}