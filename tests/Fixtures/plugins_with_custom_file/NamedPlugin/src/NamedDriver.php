<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\NamedPlugin;

use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

final class NamedDriver implements LLMDriverInterface
{
    public function complete(LLMRequest $request): LLMResponse
    {
        throw new \RuntimeException('Test driver - not implemented');
    }

    public function getProviderName(): string
    {
        return 'named_driver';
    }

    public function getModelName(): string
    {
        return 'test-named-model';
    }
}