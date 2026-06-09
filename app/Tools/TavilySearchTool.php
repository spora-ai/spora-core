<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Searches the web using Tavily AI, optimized for LLM agents.
 * Provides direct answers and search results with fact-checking capabilities.
 */
#[Tool(
    name: 'tavily_search',
    description: 'Search the web using Tavily AI (optimized for agents). Use this for fact-checking, recent events, research, or finding current data online. This provides direct concise answers.',
    displayName: 'Tavily Search',
    category: 'research',
)]
#[ToolOperation(name: 'search', description: 'Search the web using Tavily AI', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'core.tavily.api_key',
    label: 'Tavily API Key',
    type: 'password',
    description: 'API key for api.tavily.com/search (Optimized for LLMs)',
    required: true,
)]
#[ToolSetting(
    key: 'core.tavily.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
)]
#[ToolParameter(
    name: 'query',
    type: 'string',
    description: 'The exact research question or search query.',
    required: true,
)]
#[ToolParameter(
    name: 'search_depth',
    type: 'string',
    description: 'Either "basic" or "advanced". Advanced takes longer but traverses deeper.',
    required: false,
    enum: ['basic', 'advanced'],
)]
final class TavilySearchTool extends AbstractTool
{
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['core.tavily.http_timeout']) && (int) $settings['core.tavily.http_timeout'] > 0) {
            return (int) $settings['core.tavily.http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return $this->search($arguments, $agentId, $userId);
    }

    public function describeAction(array $arguments): string
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        return "Search the web using Tavily AI for: '{$query}'";
    }

    public function search(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $query       = trim((string) ($arguments['query'] ?? ''));
        $searchDepth = trim((string) ($arguments['search_depth'] ?? 'basic'));

        $validation = $this->validateSearchInputs($agentId, $userId, $query);
        if ($validation !== null) {
            return $validation;
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.tavily.api_key'] ?? '';

        try {
            return $this->performTavilySearch($query, $searchDepth, $apiKey, $this->effectiveTimeout($settings));
        } catch (Throwable $e) {
            $this->logger?->error('Tavily API Exception', ['exception' => $e]);
            return new ToolResult(false, 'Search tool error: ' . $e->getMessage());
        }
    }

    private function validateSearchInputs(int $agentId, ?int $userId, string $query): ?ToolResult
    {
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.tavily.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Tavily API key is not configured for this agent. Please edit the Tavily Search settings.');
        }

        return null;
    }

    private function performTavilySearch(string $query, string $searchDepth, string $apiKey, int $timeout): ToolResult
    {
        $url = 'https://api.tavily.com/search';
        $payload = [
            'api_key'        => $apiKey,
            'query'          => $query,
            'search_depth'   => $searchDepth,
            'include_answer' => true,
        ];

        $this->logger?->debug('TavilySearchTool: HTTP request', [
            'method'  => 'POST',
            'url'     => $url,
            'headers' => ['Content-Type' => 'application/json'],
            'payload' => ['api_key' => '***', 'query' => $query, 'search_depth' => $searchDepth, 'include_answer' => true],
            'timeout' => $timeout,
        ]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => $payload,
            'timeout' => $timeout,
        ]);

        $statusCode = $response->getStatusCode();
        $this->logger?->debug('TavilySearchTool: HTTP response', [
            'status_code' => $statusCode,
            'url'         => $url,
        ]);

        if ($statusCode >= 400) {
            $this->logger?->error('Tavily Search API Error', [
                'status' => $statusCode,
                'body'   => $response->getContent(false),
            ]);
            return new ToolResult(false, "Web search failed with HTTP {$statusCode}");
        }

        return new ToolResult(true, $this->formatTavilyResults($query, $response->toArray(false)));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatTavilyResults(string $query, array $data): string
    {
        $output = "Search Results for '{$query}':\n\n";
        if (!empty($data['answer'])) {
            $output .= "Summary: {$data['answer']}\n\n";
        }

        foreach (($data['results'] ?? []) as $i => $result) {
            $num = $i + 1;
            $output .= "[{$num}] {$result['title']}\n";
            $output .= "URL: {$result['url']}\n";
            $output .= "{$result['content']}\n\n";
        }

        return $output;
    }
}
