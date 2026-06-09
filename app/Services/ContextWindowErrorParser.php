<?php

declare(strict_types=1);

namespace Spora\Services;

/**
 * Parses context window error responses from LLM providers.
 *
 * Extracts the actual context window limit from error JSON bodies so we can
 * surface actionable information to users and auto-update configs.
 */
final class ContextWindowErrorParser
{
    /**
     * Parse context window info from a raw error body (JSON string).
     *
     * @return array{actual_context_window: int|null, error_type: string|null, message: string}
     */
    public function parse(string $rawBody): array
    {
        $data = json_decode($rawBody, true);
        if (! is_array($data)) {
            return ['actual_context_window' => null, 'error_type' => null, 'message' => $rawBody];
        }

        $message = $data['message'] ?? $data['error']['message'] ?? 'Unknown error';

        // Look for context window limit in the response
        $actualLimit = $this->extractContextWindow($data, $message);

        // Capture the error type if present
        $errorType = $data['type'] ?? $data['error']['type'] ?? null;

        return [
            'actual_context_window' => $actualLimit,
            'error_type' => is_string($errorType) ? $errorType : null,
            'message' => is_string($message) ? $message : 'Unknown error',
        ];
    }

    /**
     * Detect if an error message indicates a context window exceeded error.
     */
    public function isContextWindowError(string $rawBody): bool
    {
        $lower = strtolower($rawBody);

        // Common context window error indicators
        $indicators = [
            'context window',
            'context_window',
            'maximum context',
            'max context',
            'context_limit',
            'context length',
            'token limit',
            'maximum tokens',
            'exceeds limit',
            'input too long',
            'too many tokens',
            'maximum context window',
        ];

        foreach ($indicators as $indicator) {
            if (str_contains($lower, strtolower($indicator))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractContextWindow(array $data, mixed $message): ?int
    {
        $limit = $this->extractLimitFromData($data);
        if ($limit !== null) {
            return $limit;
        }

        if (is_string($message)) {
            return $this->extractLimitFromMessage($message);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractLimitFromData(array $data): ?int
    {
        $limit = $data['max_context_window']
            ?? $data['context_window']
            ?? $data['max_tokens']
            ?? $data['limit']
            ?? $data['error']['max_context_window']
            ?? $data['error']['context_window']
            ?? $data['error']['limit']
            ?? null;

        return is_numeric($limit) ? (int) $limit : null;
    }

    private function extractLimitFromMessage(string $message): ?int
    {
        // Try to extract from error message string: "context window exceeds limit (2013)"
        if (preg_match('/\b(\d{4,6})\s*(?:token|context|tokens)\b/i', $message, $matches)) {
            return (int) $matches[1];
        }
        // MiniMax-style: "context window exceeds limit (2013)" — the number IS the limit
        if (preg_match('/limit\s*[\(\[]?\s*(\d{4,6})\s*[\)\]]?/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
