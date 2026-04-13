<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[Tool(
    name: 'tavily_search',
    description: 'Search the web using Tavily AI (optimized for agents). Use this for fact-checking, recent events, research, or finding current data online. This provides direct concise answers.',
    displayName: 'Tavily Search',
)]
#[ToolSetting(
    key: 'core.tavily.api_key',
    label: 'Tavily API Key',
    type: 'password',
    description: 'API key for api.tavily.com/search (Optimized for LLMs)',
    scope: 'agent',
    required: true,
)]
#[ToolSetting(
    key: 'core.tavily.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
    scope: 'agent',
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
final class TavilySearchTool implements InputToolInterface
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

    public function execute(array $arguments, int $agentId): ToolResult
    {
        $query       = trim((string) ($arguments['query'] ?? ''));
        $searchDepth = trim((string) ($arguments['search_depth'] ?? 'basic'));

        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.tavily.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Tavily API key is not configured for this agent. Please edit the Tavily Search settings.');
        }

        try {
            $this->logger?->debug('TavilySearchTool: executing request', [
                'query' => $query,
                'search_depth' => $searchDepth,
                'url' => 'https://api.tavily.com/search',
            ]);

            $response = $this->httpClient->request('POST', 'https://api.tavily.com/search', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'api_key'      => $apiKey,
                    'query'        => $query,
                    'search_depth' => $searchDepth,
                    'include_answer' => true,
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('TavilySearchTool: response received', [
                'status_code' => $statusCode,
                'query' => $query,
            ]);

            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('Tavily Search API Error', ['status' => $statusCode, 'body' => $errorBody]);
                return new ToolResult(false, "Web search failed with HTTP {$statusCode}");
            }

            $data = $response->toArray(false);

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

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Tavily API Exception', ['exception' => $e]);
            return new ToolResult(false, "Search tool error: " . $e->getMessage());
        }
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'The exact research question or search query.',
                ],
                'search_depth' => [
                    'type'        => 'string',
                    'description' => 'Either "basic" or "advanced". Advanced takes longer but traverses deeper.',
                    'enum'        => ['basic', 'advanced'],
                ],
            ],
            'required' => ['query'],
        ];
    }
}
