<?php

declare(strict_types=1);

namespace Spora\Services;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Fetches model context window sizes from provider APIs where available.
 *
 * Currently supports OpenAI's `/models/{model}` endpoint.
 * For other providers, returns null and the caller falls back to the configured value.
 */
final class ModelContextWindowService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Get context window for a model via API introspection.
     * Returns null if the information cannot be determined.
     *
     * @param class-string $driverClass FQCN of the LLM driver
     * @param string $model Model identifier (e.g. gpt-4o)
     * @param string $apiKey API key for the provider
     * @param string $baseUrl Base URL of the API endpoint
     */
    public function getContextWindow(
        string $driverClass,
        string $model,
        string $apiKey,
        string $baseUrl,
    ): ?int {
        if ($driverClass === 'Spora\Drivers\OpenAICompatibleDriver') {
            return $this->fetchOpenAIContextWindow($model, $apiKey, $baseUrl);
        }

        // Other providers don't support introspection — caller must use configured/default value
        return null;
    }

    private function fetchOpenAIContextWindow(string $model, string $apiKey, string $baseUrl): ?int
    {
        $url = rtrim($baseUrl, '/') . '/models/' . ltrim($model, '/');

        $headers = ['Content-Type' => 'application/json'];
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'timeout' => 10,
            ]);

            $data = $this->parseOpenAIContextResponse($response);

            // OpenAI returns context_window in the response
            $contextWindow = $data['context_window'] ?? $data['max_tokens'] ?? null;

            return is_numeric($contextWindow) ? (int) $contextWindow : null;
        } catch (Throwable $e) {
            $this->logger?->debug('Failed to fetch OpenAI model context window', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOpenAIContextResponse(ResponseInterface $response): array
    {
        if ($response->getStatusCode() !== 200) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray();

        return $data;
    }
}
