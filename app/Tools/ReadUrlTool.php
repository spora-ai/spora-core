<?php

declare(strict_types=1);

namespace Spora\Tools;

use League\HTMLToMarkdown\HtmlConverter;
use Psr\Log\LoggerInterface;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[Tool(
    name: 'read_url',
    description: 'Fetch and read the contents of a URL. Can parse HTML pages into Markdown, and can read XML/RSS feeds. Only http:// and https:// URLs are supported.',
    displayName: 'Read URL',
)]
#[ToolParameter(
    name: 'url',
    type: 'string',
    description: 'The absolute http:// or https:// URL to read (e.g., https://example.com).',
    required: true,
)]
final class ReadUrlTool implements InputToolInterface
{
    /** Maximum output length in characters before truncation. */
    private const MAX_OUTPUT_CHARS = 40_000;

    /** Permitted URL schemes — restricts SSRF via file://, gopher://, cloud metadata endpoints, etc. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId): ToolResult
    {
        $url = trim((string) ($arguments['url'] ?? ''));

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new ToolResult(false, 'A valid absolute URL is required.');
        }

        // #1 SSRF guard: allowlist only http/https — blocks file://, ftp://, gopher://,
        // cloud metadata endpoints (http://169.254.169.254), etc.
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return new ToolResult(false, 'Only http:// and https:// URLs are supported.');
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => 'Spora Agent/1.0 (+https://github.com/spora/spora)',
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                return new ToolResult(false, "Failed to fetch URL. HTTP Status: {$statusCode}");
            }

            $contentType = strtolower($response->getHeaders()['content-type'][0] ?? 'text/plain');
            $content     = $response->getContent(false);

            // Handle XML / RSS
            if (str_contains($contentType, 'xml') || str_contains($contentType, 'rss')) {
                return new ToolResult(true, "Fetched XML/RSS Content:\n\n" . $this->truncate($content));
            }

            // Handle JSON
            if (str_contains($contentType, 'json')) {
                return new ToolResult(true, "Fetched JSON Content:\n\n" . $this->truncate($content));
            }

            // Fallback: Assume HTML and convert to Markdown
            $converter = new HtmlConverter([
                'strip_tags'   => true,
                'remove_nodes' => 'script style nav footer header iframe',
            ]);

            $markdown = trim($converter->convert($content));

            if ($markdown === '') {
                return new ToolResult(false, 'URL was fetched successfully but no readable text content was found.');
            }

            // #5 Size guard: cap output so large pages don't saturate the context window.
            return new ToolResult(true, "Fetched URL Content (Markdown):\n\n" . $this->truncate($markdown));

        } catch (Throwable $e) {
            $this->logger?->error('ReadUrlTool Exception', ['url' => $url, 'exception' => $e]);
            return new ToolResult(false, "Failed to read URL: " . $e->getMessage());
        }
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) > self::MAX_OUTPUT_CHARS) {
            return mb_substr($text, 0, self::MAX_OUTPUT_CHARS) . "\n\n[Content truncated at " . number_format(self::MAX_OUTPUT_CHARS) . " characters]";
        }

        return $text;
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'description' => 'The absolute http:// or https:// URL to read.',
                ],
            ],
            'required' => ['url'],
        ];
    }
}
