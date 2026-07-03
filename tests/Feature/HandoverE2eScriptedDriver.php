<?php

declare(strict_types=1);

namespace Tests\Feature;

use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

/**
 * Scripted LLM driver: returns queued responses in order, recycling the
 * last response if the queue is exhausted. Avoids Mockery's `andReturnUsing`
 * (which PHPStan cannot resolve on the mock type union) by being a real
 * implementation of LLMDriverInterface.
 */
final class HandoverE2eScriptedDriver implements LLMDriverInterface
{
    /** @var list<LLMResponse> */
    private array $responses;

    public int $callCount = 0;

    public function __construct(LLMResponse ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $this->callCount++;
        $idx = min($this->callCount - 1, count($this->responses) - 1);
        return $this->responses[$idx];
    }

    public function getProviderName(): string
    {
        return 'mock';
    }

    public function getModelName(): string
    {
        return 'mock-model';
    }
}
