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
    name: 'serper_search',
    description: 'Search the web using Google Search via Serper.dev. Use this for general queries, looking up specific websites, or finding real-time information.',
    displayName: 'Serper Search',
)]
#[ToolSetting(
    key: 'core.serper.api_key',
    label: 'Serper.dev API Key',
    type: 'password',
    description: 'API key for google.serper.dev (Google Search)',
    scope: 'agent',
)]
#[ToolParameter(
    name: 'q',
    type: 'string',
    description: 'The search query to send to Google.',
    required: true,
)]
final class SerperSearchTool implements InputToolInterface
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
        $apiKey = $settings['core.serper.api_key'] ?? '';
        if (empty($apiKey)) {
            return new ToolResult(false, 'Serper API key is not configured for this agent. Please edit the Serper Search settings.');
        }

        try {
            $response = $this->httpClient->request('POST', 'https://google.serper.dev/search', [
                'headers' => [
                    'X-API-KEY'    => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'q' => $query,
                ],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
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
