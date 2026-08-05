<?php

declare(strict_types=1);

namespace Tests\Feature;

use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

/**
 * Shared scripted LLM driver for the sub-agent end-to-end tests.
 *
 * Mirrors {@see HandoverE2eScriptedDriver} but lives in the same namespace
 * (`Tests\Feature`) so the SubAgent* test files don't import across
 * files. A clean factory would be better; this is the smallest diff
 * to keep the tests independent.
 */
final class SubAgentE2eScriptedDriver implements LLMDriverInterface
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

    public function supportsImageInput(): bool
    {
        return false;
    }
}
