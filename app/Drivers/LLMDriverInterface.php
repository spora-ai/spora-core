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

    /**
     * Whether the *currently configured model* (not just the driver protocol)
     * accepts image content blocks. The driver protocol may support images
     * on the wire (e.g. Anthropic Messages API, OpenAI Chat Completions),
     * but a specific model — say, a smaller or older one — might not.
     *
     * Drives the upload UI's image gate (see MediaAllowedTypesService) and
     * the runtime strip in {@see \Spora\Agents\TickPhaseRunner}.
     */
    public function supportsImageInput(): bool;
}
