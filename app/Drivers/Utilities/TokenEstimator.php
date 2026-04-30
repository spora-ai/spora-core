<?php

declare(strict_types=1);

namespace Spora\Drivers\Utilities;

use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;

/**
 * Rough token estimation for message truncation decisions.
 *
 * Uses a simple character-count approximation (~4 chars/token for English).
 * Not accurate enough for billing or tight budgets — only for truncation thresholds.
 */
final class TokenEstimator
{
    /**
     * Estimate tokens for a single message.
     */
    public function estimateMessage(array $message): int
    {
        $content = $message['content'] ?? '';
        $baseEstimate = (int) ceil(mb_strlen($content, 'UTF-8') / 4);

        // Tool calls have significant overhead
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                if (is_array($tc) && isset($tc['function']['arguments'])) {
                    $args = $tc['function']['arguments'];
                    if (is_string($args)) {
                        $baseEstimate += (int) ceil(mb_strlen($args, 'UTF-8') / 4);
                    }
                    $baseEstimate += 30; // overhead per tool call
                }
            }
        }

        // Tool result messages
        if (($message['role'] ?? '') === 'tool') {
            $baseEstimate += 20; // tool result overhead
        }

        // Role and name overhead
        $baseEstimate += 10;

        return max(1, $baseEstimate);
    }

    /**
     * Estimate tokens for system prompt.
     */
    public function estimateSystemPrompt(string $systemPrompt): int
    {
        return max(1, (int) ceil(mb_strlen($systemPrompt, 'UTF-8') / 4));
    }

    /**
     * Estimate tokens for tool definitions.
     *
     * @param list<array{type: string, function: array{name?: string, description?: string, parameters?: array}}> $tools
     */
    public function estimateTools(array $tools): int
    {
        $total = 0;
        foreach ($tools as $tool) {
            $name = $tool['function']['name'] ?? null;
            if ($name !== null) {
                $total += (int) ceil(mb_strlen($name, 'UTF-8') / 4);
            }
            $description = $tool['function']['description'] ?? null;
            if ($description !== null) {
                $total += (int) ceil(mb_strlen($description, 'UTF-8') / 4);
            }
            $parameters = $tool['function']['parameters'] ?? null;
            if ($parameters !== null) {
                $total += (int) ceil(mb_strlen(json_encode($parameters), 'UTF-8') / 4);
            }
            $total += 20; // overhead per tool definition
        }
        return $total;
    }

    /**
     * Estimate total tokens for a full LLMRequest.
     */
    public function estimateRequest(LLMRequest $request): int
    {
        $total = $this->estimateSystemPrompt($request->systemPrompt);
        $total += $this->estimateTools($request->tools);

        foreach ($request->messages as $message) {
            $total += $this->estimateMessage($message);
        }

        return $total;
    }

    /**
     * Estimate input tokens for an LLMResponse (from the usage field if available).
     */
    public function estimateResponseTokens(LLMResponse $response): int
    {
        return $response->inputTokens + $response->outputTokens;
    }
}
