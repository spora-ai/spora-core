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
    name: 'newsapi_search',
    description: 'Fetch the latest news headlines from NewsAPI.org. Use this to find out what is happening in the world right now regarding a specific topic.',
    displayName: 'NewsAPI Search',
)]
#[ToolSetting(
    key: 'core.newsapi.api_key',
    label: 'NewsAPI.org Key',
    type: 'password',
    description: 'API key for NewsAPI.org',
    scope: 'agent',
)]
#[ToolParameter(
    name: 'q',
    type: 'string',
    description: 'Keywords or phrases to search for in the news.',
    required: true,
)]
final class NewsApiTool implements InputToolInterface
{
    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));

        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.newsapi.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'NewsAPI.org key is not configured for this agent.');
        }

        try {
            $response = $this->httpClient->request('GET', 'https://newsapi.org/v2/everything', [
                'headers' => [
                    'X-Api-Key'  => $apiKey,
                    'User-Agent' => 'Spora Agent/1.0',
                ],
                'query' => [
                    'q'        => $query,
                    'pageSize' => 10,
                    'sortBy'   => 'publishedAt',
                ],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('NewsAPI Error', ['status' => $statusCode, 'body' => $errorBody]);
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

            if (($data['totalResults'] ?? 0) === 0) {
                $output .= "No recent news found for this topic.\n";
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('NewsAPI Exception', ['exception' => $e]);
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
