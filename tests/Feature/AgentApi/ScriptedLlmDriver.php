<?php

declare(strict_types=1);

namespace Tests\Feature\AgentApi;

use InvalidArgumentException;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

/**
 * An `LLMDriverInterface` that replays a fixed list of responses.
 *
 * A real implementation rather than a Mockery mock: `andReturnUsing` on a
 * mock returns a union type PHPStan cannot narrow, so scripted drivers are
 * easier to type-check as classes.
 *
 * The list is consumed in order and the last entry repeats once exhausted,
 * which is what a single-step test script wants — the second tick of a chat
 * that made one tool call gets the final answer, and any further tick keeps
 * getting it rather than blowing up.
 */
final class ScriptedLlmDriver implements LLMDriverInterface
{
    /** @var list<LLMResponse> */
    private array $responses;

    public int $callCount = 0;

    public function __construct(LLMResponse ...$responses)
    {
        if ($responses === []) {
            throw new InvalidArgumentException('ScriptedLlmDriver needs at least one response.');
        }

        $this->responses = array_values($responses);
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $index = min($this->callCount, count($this->responses) - 1);
        $this->callCount++;

        return $this->responses[$index];
    }

    public function getProviderName(): string
    {
        return 'scripted';
    }

    public function getModelName(): string
    {
        return 'scripted-model';
    }

    public function supportsImageInput(): bool
    {
        return true;
    }
}
