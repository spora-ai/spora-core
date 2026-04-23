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
    name: 'serper_search',
    description: 'Search the web using Google Search via Serper.dev. Use this for general queries, looking up specific websites, or finding real-time information.',
    displayName: 'Serper Search',
    category: 'research',
)]
#[ToolOperation(name: 'search', description: 'Search the web using Google via Serper.dev', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'core.serper.api_key',
    label: 'Serper.dev API Key',
    type: 'password',
    description: 'API key for google.serper.dev (Google Search)',
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
    description: 'The search query to send to Google.',
    required: true,
)]
final class SerperSearchTool implements ToolInterface
{
    use HasOperations;
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
        return $this->search($arguments, $agentId);
    }

    public function describeAction(array $arguments): string
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        return "Search Google via Serper.dev for: '{$query}'";
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
            $this->logger?->debug('SerperSearchTool: executing request', [
                'query' => $query,
                'url' => 'https://google.serper.dev/search',
            ]);

            $response = $this->httpClient->request('POST', 'https://google.serper.dev/search', [
                'headers' => [
                    'X-API-KEY'    => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'q' => $query,
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('SerperSearchTool: response received', [
                'status_code' => $statusCode,
                'query' => $query,
            ]);

            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $this->logger?->error('Serper Search API Error', ['status' => $statusCode, 'body' => $errorBody]);
                return new ToolResult(false, "Web search failed with HTTP {$statusCode}");
            }

            $data = $response->toArray(false);

            $output = "Google Search Results for '{$query}':\n\n";

            // Answer Box
            if (!empty($data['answerBox']['answer'])) {
                $output .= "Quick Answer: {$data['answerBox']['answer']}\n\n";
            } elseif (!empty($data['answerBox']['snippet'])) {
                $output .= "Quick Snippet: {$data['answerBox']['snippet']}\n\n";
            }

            // Organic Results
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
            $this->logger?->error('Serper API Exception', ['exception' => $e]);
            return new ToolResult(false, "Search tool error: " . $e->getMessage());
        }
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'q' => [
                    'type'        => 'string',
                    'description' => 'The search query to send to Google.',
                ],
            ],
            'required' => ['q'],
        ];
    }
}
