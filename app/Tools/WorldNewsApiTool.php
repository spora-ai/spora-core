<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[Tool(
    name: 'worldnews_search',
    description: 'Search world news from thousands of sources with no time delay. Use this to find out what is happening right now regarding a specific topic.',
    displayName: 'World News Search',
    category: 'research',
)]
#[ToolOperation(name: 'search', description: 'Search news by text, country, language, or semantic entities', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'top-news', description: 'Get top trending news for a specific country', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'core.worldnewsapi.api_key',
    label: 'WorldNewsAPI Key',
    type: 'password',
    description: 'API key for worldnewsapi.com',
    scope: 'agent',
    required: true,
)]
#[ToolSetting(
    key: 'core.worldnewsapi.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
    scope: 'agent',
)]
final class WorldNewsApiTool implements ToolInterface
{
    use HasOperations;

    private const BASE_URL = 'https://api.worldnewsapi.com';

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['core.worldnewsapi.http_timeout']) && (int) $settings['core.worldnewsapi.http_timeout'] > 0) {
            return (int) $settings['core.worldnewsapi.http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $operation = $arguments['operation'] ?? 'search';

        return match ($operation) {
            'top-news' => $this->topNews($arguments, $agentId, $userId),
            default => $this->search($arguments, $agentId, $userId),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $arguments['operation'] ?? 'search';

        return match ($operation) {
            'top-news' => "Fetch top news from WorldNewsAPI for country: '" . ($arguments['source-country'] ?? 'unknown') . "'",
            default => "Search WorldNewsAPI for: '{$arguments['q']}'",
        };
    }

    public function search(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));

        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.worldnewsapi.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'WorldNewsAPI key is not configured for this agent.');
        }

        try {
            $url = self::BASE_URL . '/search-news';
            $this->logger?->debug('WorldNewsApiTool: HTTP request', [
                'method' => 'GET',
                'url' => $url,
                'headers' => ['x-api-key' => '***'],
                'query' => [
                    'text' => $query,
                    'number' => min(100, (int) ($arguments['number'] ?? 10)),
                    'offset' => (int) ($arguments['offset'] ?? 0),
                    'source-country' => $arguments['source-country'] ?? null,
                    'language' => $arguments['language'] ?? null,
                    'category' => $arguments['category'] ?? null,
                    'earliest-publish-date' => $arguments['earliest-publish-date'] ?? null,
                    'latest-publish-date' => $arguments['latest-publish-date'] ?? null,
                    'entities' => isset($arguments['entities']) ? implode(',', $arguments['entities']) : null,
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'x-api-key' => $apiKey,
                ],
                'query' => [
                    'text' => $query,
                    'number' => min(100, (int) ($arguments['number'] ?? 10)),
                    'offset' => (int) ($arguments['offset'] ?? 0),
                    'source-country' => $arguments['source-country'] ?? null,
                    'language' => $arguments['language'] ?? null,
                    'category' => $arguments['category'] ?? null,
                    'earliest-publish-date' => $arguments['earliest-publish-date'] ?? null,
                    'latest-publish-date' => $arguments['latest-publish-date'] ?? null,
                    'entities' => isset($arguments['entities']) ? implode(',', $arguments['entities']) : null,
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('WorldNewsApiTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => $url,
            ]);

            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('WorldNewsAPI search error', ['status' => $statusCode, 'body' => $errorBody]);
                return new ToolResult(false, "News search failed with HTTP {$statusCode}");
            }

            $data = $response->toArray(false);

            return $this->formatNewsResults($query, $data['news'] ?? []);
        } catch (Throwable $e) {
            $this->logger?->error('WorldNewsAPI search exception', ['exception' => $e]);
            return new ToolResult(false, "News search error: " . $e->getMessage());
        }
    }

    public function topNews(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $country = trim((string) ($arguments['source-country'] ?? ''));
        $language = trim((string) ($arguments['language'] ?? ''));

        if ($country === '') {
            return new ToolResult(false, 'source-country is required for top-news.');
        }

        if ($language === '') {
            return new ToolResult(false, 'language is required for top-news.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = $settings['core.worldnewsapi.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'WorldNewsAPI key is not configured for this agent.');
        }

        try {
            $url = self::BASE_URL . '/top-news';
            $this->logger?->debug('WorldNewsApiTool: HTTP request', [
                'method' => 'GET',
                'url' => $url,
                'headers' => ['x-api-key' => '***'],
                'query' => [
                    'source-country' => $country,
                    'language' => $language,
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'x-api-key' => $apiKey,
                ],
                'query' => [
                    'source-country' => $country,
                    'language' => $language,
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('WorldNewsApiTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => $url,
            ]);

            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('WorldNewsAPI top-news error', ['status' => $statusCode, 'body' => $errorBody]);
                return new ToolResult(false, "Top news failed with HTTP {$statusCode}");
            }

            $data = $response->toArray(false);

            return $this->formatTopNewsResults($data['top_news'] ?? []);
        } catch (Throwable $e) {
            $this->logger?->error('WorldNewsAPI top-news exception', ['exception' => $e]);
            return new ToolResult(false, "Top news error: " . $e->getMessage());
        }
    }

    private function formatNewsResults(string $query, array $articles): ToolResult
    {
        $output = "News Results for '{$query}':\n\n";

        if (count($articles) === 0) {
            $output .= "No recent news found for this topic.\n";
            return new ToolResult(true, $output);
        }

        foreach ($articles as $i => $article) {
            $num = $i + 1;
            $title = $article['title'] ?? 'No Title';
            $source = $article['source'] ?? 'Unknown Source';
            $publishDate = $article['publish_date'] ?? 'Unknown Date';
            $summary = $article['summary'] ?? 'No description available';
            $url = $article['url'] ?? '#';

            $output .= "[{$num}] {$title} ({$source} - {$publishDate})\n";
            $output .= "{$summary}\n";
            $output .= "URL: {$url}\n\n";
        }

        return new ToolResult(true, $output);
    }

    private function formatTopNewsResults(array $topNews): ToolResult
    {
        $output = "Top News:\n\n";

        if (count($topNews) === 0) {
            $output .= "No top news available.\n";
            return new ToolResult(true, $output);
        }

        foreach ($topNews as $categoryGroup) {
            foreach ($categoryGroup['news'] ?? [] as $i => $article) {
                $num = $i + 1;
                $title = $article['title'] ?? 'No Title';
                $source = $article['source'] ?? 'Unknown Source';
                $publishDate = $article['publish_date'] ?? 'Unknown Date';
                $summary = $article['summary'] ?? 'No description available';
                $url = $article['url'] ?? '#';

                $output .= "[{$num}] {$title} ({$source} - {$publishDate})\n";
                $output .= "{$summary}\n";
                $output .= "URL: {$url}\n\n";
            }
        }

        return new ToolResult(true, $output);
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => ['search', 'top-news'],
                    'description' => 'Operation to perform: search (default) or top-news',
                ],
                'q' => [
                    'type' => 'string',
                    'description' => 'Keywords or phrases to search for (required for search).',
                ],
                'source-country' => [
                    'type' => 'string',
                    'description' => 'ISO country code, e.g. "us" or "de" (required for top-news).',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'ISO 2-letter language code, e.g. "en" or "de" (required for top-news).',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'News category, e.g. "politics", "sports", "technology".',
                ],
                'earliest-publish-date' => [
                    'type' => 'string',
                    'description' => 'Earliest publish date (ISO 8601 format, e.g. 2026-04-01).',
                ],
                'latest-publish-date' => [
                    'type' => 'string',
                    'description' => 'Latest publish date (ISO 8601 format, e.g. 2026-04-23).',
                ],
                'entities' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Semantic entities to search for: people, organizations, locations.',
                ],
                'number' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results (1-100, default 10).',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset.',
                ],
            ],
            'required' => [],
        ];
    }
}
