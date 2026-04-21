<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[Tool(
    name: 'gnews_search',
    description: 'Fetch the latest news headlines from GNews.io. Use this to find out what is happening in the world right now regarding a specific topic.',
    displayName: 'GNews Search',
    category: 'research',
)]
#[ToolOperation(name: 'search', description: 'Fetch latest news from GNews.io', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'core.gnews.api_key',
    label: 'GNews.io API Key',
    type: 'password',
    description: 'API key for GNews.io',
    scope: 'agent',
    required: true,
)]
#[ToolSetting(
    key: 'core.gnews.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
    scope: 'agent',
)]
#[ToolParameter(
    name: 'q',
    type: 'string',
    description: 'Keywords or phrases to search for in the news.',
    required: true,
)]
final class GNewsTool implements ToolInterface
{
    use HasOperations;
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['core.gnews.http_timeout']) && (int) $settings['core.gnews.http_timeout'] > 0) {
            return (int) $settings['core.gnews.http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    public function execute(array $arguments, int $agentId): ToolResult
    {
        return $this->search($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        return "Fetch news from GNews.io for: '{$query}'";
    }

    public function search(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));

        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.gnews.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'GNews.io key is not configured for this agent.');
        }

        try {
            $this->logger?->debug('GNewsTool: executing request', [
                'query' => $query,
                'url' => 'https://gnews.io/api/v4/search',
            ]);

            $response = $this->httpClient->request('GET', 'https://gnews.io/api/v4/search', [
                'query' => [
                    'q'      => $query,
                    'apikey' => $apiKey,
                    'max'    => 10,
                    'lang'   => 'en',
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('GNewsTool: response received', [
                'status_code' => $statusCode,
                'query' => $query,
            ]);

            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('GNews Error', ['status' => $statusCode, 'body' => $errorBody]);
                return new ToolResult(false, "News fetching failed with HTTP {$statusCode}");
            }

            $data = $response->toArray(false);

            $output = "Latest News Results for '{$query}':\n\n";

            foreach (($data['articles'] ?? []) as $i => $article) {
                $num = $i + 1;
                $title       = $article['title'] ?? 'No Title';
                $source      = $article['source']['name'] ?? 'Unknown Source';
                $publishedAt = $article['publishedAt'] ?? 'Unknown Date';
                $description = $article['description'] ?? 'No Description';
                $url         = $article['url'] ?? '#';

                $output .= "[{$num}] {$title} ({$source} - {$publishedAt})\n";
                $output .= "{$description}\n";
                $output .= "URL: {$url}\n\n";
            }

            if (($data['totalArticles'] ?? 0) === 0) {
                $output .= "No recent news found for this topic.\n";
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('GNews Exception', ['exception' => $e]);
            return new ToolResult(false, "News tool error: " . $e->getMessage());
        }
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'q' => [
                    'type'        => 'string',
                    'description' => 'Keywords or phrases to search for in the news.',
                ],
            ],
            'required' => ['q'],
        ];
    }
}
