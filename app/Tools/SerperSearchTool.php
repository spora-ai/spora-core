<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use RuntimeException;
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
    name: 'serper_search',
    description: 'Search the web using Google Search via Serper.dev. Use this for general queries, looking up specific websites, or finding real-time information.',
    displayName: 'Serper Search',
    category: 'research',
)]
#[ToolOperation(name: 'search', description: 'Search the web using Google via Serper.dev', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'image_search', description: 'Search for images using Google Images', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'news_search', description: 'Search for news articles using Google News', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'video_search', description: 'Search for videos using Google Video', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'scholar_search', description: 'Search Google Scholar for academic papers and citations', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'shopping_search', description: 'Search for products and shopping results', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'patents_search', description: 'Search patent records and filings', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'maps_search', description: 'Search Google Maps for places and locations', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'places_search', description: 'Search for specific places with detailed information', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'core.serper.api_key',
    label: 'Serper.dev API Key',
    type: 'password',
    description: 'API key for serper.dev',
    scope: 'agent',
    required: true,
)]
#[ToolSetting(
    key: 'core.serper.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
    scope: 'agent',
)]
#[ToolParameter(
    name: 'q',
    type: 'string',
    description: 'The search query.',
    required: true,
)]
final class SerperSearchTool implements ToolInterface
{
    use HasOperations;

    private const VALID_OPERATIONS = [
        'search',
        'image_search',
        'news_search',
        'video_search',
        'scholar_search',
        'shopping_search',
        'patents_search',
        'maps_search',
        'places_search',
    ];

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['core.serper.http_timeout']) && (int) $settings['core.serper.http_timeout'] > 0) {
            return (int) $settings['core.serper.http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    public function execute(array $arguments, int $agentId): ToolResult
    {
        $operation = $this->getOperationName($arguments);
        return match ($operation) {
            'search'           => $this->search($arguments, $agentId),
            'image_search'     => $this->imageSearch($arguments, $agentId),
            'news_search'      => $this->newsSearch($arguments, $agentId),
            'video_search'     => $this->videoSearch($arguments, $agentId),
            'scholar_search'   => $this->scholarSearch($arguments, $agentId),
            'shopping_search'  => $this->shoppingSearch($arguments, $agentId),
            'patents_search'   => $this->patentsSearch($arguments, $agentId),
            'maps_search'      => $this->mapsSearch($arguments, $agentId),
            'places_search'    => $this->placesSearch($arguments, $agentId),
            default            => new ToolResult(false, "Unknown operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $this->getOperationName($arguments);
        $query = trim((string) ($arguments['q'] ?? ''));

        $descriptions = [
            'search'          => "Search Google via Serper.dev for: '{$query}'",
            'image_search'    => "Search Google Images for: '{$query}'",
            'news_search'     => "Search Google News for: '{$query}'",
            'video_search'    => "Search Google Videos for: '{$query}'",
            'scholar_search'  => "Search Google Scholar for: '{$query}'",
            'shopping_search' => "Search shopping results for: '{$query}'",
            'patents_search'  => "Search patents for: '{$query}'",
            'maps_search'     => "Search Google Maps for: '{$query}'",
            'places_search'   => "Search places for: '{$query}'",
        ];

        return $descriptions[$operation] ?? "Serper search: '{$query}'";
    }

    private function getApiKey(int $agentId): string
    {
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        return $settings['core.serper.api_key'] ?? '';
    }

    private function makeSerperRequest(string $endpoint, array $payload, array $settings): array
    {
        $url = "https://google.serper.dev/{$endpoint}";
        $this->logger?->debug('SerperSearchTool: HTTP request', [
            'method' => 'POST',
            'url' => $url,
            'headers' => ['X-API-KEY' => '***'],
            'payload' => $payload,
            'timeout' => $this->effectiveTimeout($settings),
        ]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'X-API-KEY'    => $settings['core.serper.api_key'],
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => $this->effectiveTimeout($settings),
        ]);

        $statusCode = $response->getStatusCode();
        $this->logger?->debug('SerperSearchTool: HTTP response', [
            'status_code' => $statusCode,
            'url' => $url,
        ]);

        if ($statusCode >= 400) {
            $errorBody = $response->getContent(false);
            throw new RuntimeException("HTTP {$statusCode}: {$errorBody}");
        }

        return $response->toArray(false);
    }

    public function search(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured for this agent. Please edit the Serper Search settings.');
        }

        try {
            $this->logger?->debug('SerperSearchTool: executing search request', [
                'query' => $query,
                'endpoint' => 'search',
            ]);

            $data = $this->makeSerperRequest('search', ['q' => $query], $settings);

            $output = "Google Search Results for '{$query}':\n\n";

            if (!empty($data['answerBox']['answer'])) {
                $output .= "Quick Answer: {$data['answerBox']['answer']}\n\n";
            } elseif (!empty($data['answerBox']['snippet'])) {
                $output .= "Quick Snippet: {$data['answerBox']['snippet']}\n\n";
            }

            foreach (($data['organic'] ?? []) as $i => $result) {
                $num = $i + 1;
                $output .= "[{$num}] {$result['title']}\n";
                $output .= "URL: {$result['link']}\n";
                if (!empty($result['snippet'])) {
                    $output .= "{$result['snippet']}\n";
                }
                $output .= "\n";
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Serper Search Exception', ['exception' => $e]);
            return new ToolResult(false, 'Search tool error: ' . $e->getMessage());
        }
    }

    public function imageSearch(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured. Please edit the Serper Search settings.');
        }

        try {
            $this->logger?->debug('SerperSearchTool: executing image search', ['query' => $query]);

            $data = $this->makeSerperRequest('images', ['q' => $query], $settings);

            $output = "Google Image Results for '{$query}':\n\n";

            foreach (($data['images'] ?? []) as $i => $image) {
                $num = $i + 1;
                $output .= "[{$num}] {$image['title']}\n";
                $output .= "Image URL: {$image['imageUrl']}\n";
                if (!empty($image['sourceUrl'])) {
                    $output .= "Source: {$image['sourceUrl']}";
                    if (!empty($image['sourceName'])) {
                        $output .= " ({$image['sourceName']})";
                    }
                    $output .= "\n";
                }
                $output .= "\n";
            }

            if (empty($data['images'])) {
                $output .= 'No image results found.';
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Serper Image Search Exception', ['exception' => $e]);
            return new ToolResult(false, 'Image search error: ' . $e->getMessage());
        }
    }

    public function newsSearch(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured. Please edit the Serper Search settings.');
        }

        try {
            $this->logger?->debug('SerperSearchTool: executing news search', ['query' => $query]);

            $data = $this->makeSerperRequest('news', ['q' => $query], $settings);

            $output = "Google News Results for '{$query}':\n\n";

            foreach (($data['news'] ?? []) as $i => $article) {
                $num = $i + 1;
                $output .= "[{$num}] {$article['title']}\n";
                $output .= "Source: {$article['source']}\n";
                if (!empty($article['date'])) {
                    $output .= "Date: {$article['date']}\n";
                }
                $output .= "URL: {$article['link']}\n";
                if (!empty($article['snippet'])) {
                    $output .= "{$article['snippet']}\n";
                }
                $output .= "\n";
            }

            if (empty($data['news'])) {
                $output .= 'No news results found.';
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Serper News Search Exception', ['exception' => $e]);
            return new ToolResult(false, 'News search error: ' . $e->getMessage());
        }
    }

    public function videoSearch(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured. Please edit the Serper Search settings.');
        }

        try {
            $this->logger?->debug('SerperSearchTool: executing video search', ['query' => $query]);

            $data = $this->makeSerperRequest('videos', ['q' => $query], $settings);

            $output = "Google Video Results for '{$query}':\n\n";

            foreach (($data['videos'] ?? []) as $i => $video) {
                $num = $i + 1;
                $output .= "[{$num}] {$video['title']}\n";
                if (!empty($video['source'])) {
                    $output .= "Source: {$video['source']}\n";
                }
                if (!empty($video['date'])) {
                    $output .= "Date: {$video['date']}\n";
                }
                $output .= "URL: {$video['link']}\n";
                if (!empty($video['snippet'])) {
                    $output .= "{$video['snippet']}\n";
                }
                $output .= "\n";
            }

            if (empty($data['videos'])) {
                $output .= 'No video results found.';
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Serper Video Search Exception', ['exception' => $e]);
            return new ToolResult(false, 'Video search error: ' . $e->getMessage());
        }
    }

    public function scholarSearch(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured. Please edit the Serper Search settings.');
        }

        try {
            $this->logger?->debug('SerperSearchTool: executing scholar search', ['query' => $query]);

            $data = $this->makeSerperRequest('scholar', ['q' => $query], $settings);

            $output = "Google Scholar Results for '{$query}':\n\n";

            foreach (($data['organic'] ?? []) as $i => $result) {
                $num = $i + 1;
                $output .= "[{$num}] {$result['title']}\n";
                $output .= "URL: {$result['link']}\n";
                if (!empty($result['snippet'])) {
                    $output .= "{$result['snippet']}\n";
                }
                if (!empty($result['publicationInfo'])) {
                    $output .= "Publication: {$result['publicationInfo']}\n";
                }
                $output .= "\n";
            }

            if (empty($data['organic'])) {
                $output .= 'No scholar results found.';
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Serper Scholar Search Exception', ['exception' => $e]);
            return new ToolResult(false, 'Scholar search error: ' . $e->getMessage());
        }
    }

    public function shoppingSearch(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured. Please edit the Serper Search settings.');
        }

        try {
            $this->logger?->debug('SerperSearchTool: executing shopping search', ['query' => $query]);

            $data = $this->makeSerperRequest('shopping', ['q' => $query], $settings);

            $output = "Shopping Results for '{$query}':\n\n";

            foreach (($data['shopping'] ?? []) as $i => $item) {
                $num = $i + 1;
                $output .= "[{$num}] {$item['title']}\n";
                if (!empty($item['source'])) {
                    $output .= "Source: {$item['source']}\n";
                }
                if (!empty($item['price'])) {
                    $output .= "Price: {$item['price']}\n";
                }
                $output .= "URL: {$item['link']}\n";
                if (!empty($item['snippet'])) {
                    $output .= "{$item['snippet']}\n";
                }
                $output .= "\n";
            }

            if (empty($data['shopping'])) {
                $output .= 'No shopping results found.';
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Serper Shopping Search Exception', ['exception' => $e]);
            return new ToolResult(false, 'Shopping search error: ' . $e->getMessage());
        }
    }

    public function patentsSearch(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured. Please edit the Serper Search settings.');
        }

        try {
            $this->logger?->debug('SerperSearchTool: executing patents search', ['query' => $query]);

            $data = $this->makeSerperRequest('patents', ['q' => $query], $settings);

            $output = "Patent Results for '{$query}':\n\n";

            foreach (($data['patents'] ?? []) as $i => $patent) {
                $num = $i + 1;
                $output .= "[{$num}] {$patent['title']}\n";
                if (!empty($patent['patentId'])) {
                    $output .= "Patent ID: {$patent['patentId']}\n";
                }
                if (!empty($patent['date'])) {
                    $output .= "Date: {$patent['date']}\n";
                }
                $output .= "URL: {$patent['link']}\n";
                if (!empty($patent['snippet'])) {
                    $output .= "{$patent['snippet']}\n";
                }
                $output .= "\n";
            }

            if (empty($data['patents'])) {
                $output .= 'No patent results found.';
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Serper Patents Search Exception', ['exception' => $e]);
            return new ToolResult(false, 'Patents search error: ' . $e->getMessage());
        }
    }

    public function mapsSearch(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured. Please edit the Serper Search settings.');
        }

        try {
            $this->logger?->debug('SerperSearchTool: executing maps search', ['query' => $query]);

            $data = $this->makeSerperRequest('maps', ['q' => $query], $settings);

            $output = "Google Maps Results for '{$query}':\n\n";

            foreach (($data['places'] ?? $data['localResults'] ?? []) as $i => $place) {
                $num = $i + 1;
                $output .= "[{$num}] {$place['title']}\n";
                if (!empty($place['address'])) {
                    $output .= "Address: {$place['address']}\n";
                }
                if (!empty($place['phone'])) {
                    $output .= "Phone: {$place['phone']}\n";
                }
                if (!empty($place['rating'])) {
                    $output .= "Rating: {$place['rating']}\n";
                }
                if (!empty($place['hours'])) {
                    $output .= "Hours: {$place['hours']}\n";
                }
                if (!empty($place['website'])) {
                    $output .= "Website: {$place['website']}\n";
                }
                $output .= "\n";
            }

            if (empty($data['places']) && empty($data['localResults'])) {
                $output .= 'No map results found.';
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Serper Maps Search Exception', ['exception' => $e]);
            return new ToolResult(false, 'Maps search error: ' . $e->getMessage());
        }
    }

    public function placesSearch(array $arguments, int $agentId): ToolResult
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId);
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured. Please edit the Serper Search settings.');
        }

        try {
            $this->logger?->debug('SerperSearchTool: executing places search', ['query' => $query]);

            $data = $this->makeSerperRequest('places', ['q' => $query], $settings);

            $output = "Place Results for '{$query}':\n\n";

            foreach (($data['places'] ?? []) as $i => $place) {
                $num = $i + 1;
                $output .= "[{$num}] {$place['title']}\n";
                if (!empty($place['address'])) {
                    $output .= "Address: {$place['address']}\n";
                }
                if (!empty($place['phone'])) {
                    $output .= "Phone: {$place['phone']}\n";
                }
                if (!empty($place['rating'])) {
                    $output .= "Rating: {$place['rating']}\n";
                }
                if (!empty($place['type'])) {
                    $output .= "Type: {$place['type']}\n";
                }
                if (!empty($place['openingHours'])) {
                    $hours = is_array($place['openingHours']) ? implode(', ', $place['openingHours']) : $place['openingHours'];
                    $output .= "Hours: {$hours}\n";
                }
                if (!empty($place['website'])) {
                    $output .= "Website: {$place['website']}\n";
                }
                if (!empty($place['URL'])) {
                    $output .= "URL: {$place['URL']}\n";
                }
                $output .= "\n";
            }

            if (empty($data['places'])) {
                $output .= 'No place results found.';
            }

            return new ToolResult(true, $output);
        } catch (Throwable $e) {
            $this->logger?->error('Serper Places Search Exception', ['exception' => $e]);
            return new ToolResult(false, 'Places search error: ' . $e->getMessage());
        }
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'description' => 'The type of search to perform.',
                    'enum'        => self::VALID_OPERATIONS,
                ],
                'q' => [
                    'type'        => 'string',
                    'description' => 'The search query.',
                ],
            ],
            'required' => ['action', 'q'],
        ];
    }
}
