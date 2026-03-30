<?php

declare(strict_types=1);

namespace Spora\Drivers;

use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

interface LLMDriverInterface
{
    /**
     * Send a chat completion request to the LLM and return the normalized response.
     * Intentionally synchronous — async behaviour is managed at the Messenger layer.
     *
     * @throws Exceptions\LLMProviderException   Non-recoverable API error.
     * @throws Exceptions\LLMRateLimitException  HTTP 429; caller should back off.
     */
    public function complete(LLMRequest $request): LLMResponse;

    /** e.g. "openai_compatible" or "anthropic" */
    public function getProviderName(): string;

    /** e.g. "gpt-4o" or "claude-3-5-sonnet-20241022" */
    public function getModelName(): string;
}
