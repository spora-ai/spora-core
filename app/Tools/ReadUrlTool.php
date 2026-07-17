<?php

declare(strict_types=1);

namespace Spora\Tools;

use League\HTMLToMarkdown\HtmlConverter;
use Psr\Log\LoggerInterface;
use Spora\Services\MediaArchive\MediaConverterInterface;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Fetches and parses web content from HTTP(S) URLs.
 *
 * Two operations:
 *   - `fetch`      — HTML pages converted to Markdown, plus raw XML/RSS
 *                    and JSON passthroughs.
 *   - `fetch_pdf`  — fetches a remote PDF, runs the registered PDF
 *                    converter (`PdfToMarkdownConverter` by default),
 *                    returns the markdown text. Uses the same
 *                    HttpClient and `validateUrl()` as `fetch`.
 *
 * Both share the URL-validation guard (http/https only) and the
 * 40 000-character output cap (`MAX_OUTPUT_CHARS`).
 */
#[Tool(
    name: 'read_url',
    description: 'Fetch and read the contents of a URL. Parses HTML pages into Markdown, can read XML/RSS/JSON, and can fetch remote PDFs and convert them to Markdown. Only http:// and https:// URLs are supported.',
    displayName: 'Read URL',
    category: 'data',
    icon: 'globe',
)]
#[ToolOperation(name: 'fetch', description: 'Fetch and read the contents of a URL', enabledByDefault: true, requiresApprovalByDefault: false, discriminatorKey: 'op')]
#[ToolOperation(name: 'fetch_pdf', description: 'Fetch a remote PDF and return its text as Markdown', enabledByDefault: true, requiresApprovalByDefault: false, discriminatorKey: 'op')]
#[ToolSetting(
    key: 'http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',
)]
#[ToolParameter(
    name: 'url',
    type: 'string',
    description: 'The absolute http:// or https:// URL to read.',
    required: true,
)]
final class ReadUrlTool extends AbstractTool
{
    /** Maximum output length in characters before truncation. */
    private const MAX_OUTPUT_CHARS = 40_000;

    /** Permitted URL schemes — restricts SSRF via file://, gopher://, cloud metadata endpoints, etc. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** Hard cap on PDF bytes — protects against multi-hundred-MB PDFs. */
    private const MAX_PDF_BYTES = 50 * 1024 * 1024;

    /** PDF MIME type — referenced by the registry lookup and Accept header. */
    private const PDF_MIME = 'application/pdf';

    /**
     * RFC1918 / loopback / link-local ranges — denied at fetch_pdf to
     * prevent SSRF pivots through the tool. `validateUrl()` already
     * blocks non-http(s) schemes; this layer blocks http(s) URLs whose
     * hostname resolves into a private range.
     */
    private const SSRF_DENY_PREFIXES = [
        '10.',
        '192.168.',
        '169.254.',
        '127.',
        '0.',
        '172.16.', '172.17.', '172.18.', '172.19.',
        '172.20.', '172.21.', '172.22.', '172.23.',
        '172.24.', '172.25.', '172.26.', '172.27.',
        '172.28.', '172.29.', '172.30.', '172.31.',
    ];

    /** `localhost` and the IPv6 loopback `::1` are likewise denied. */
    private const SSRF_DENY_HOSTS = ['localhost', '::1', '0.0.0.0'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ToolConfigService   $configService,
        private readonly ?LoggerInterface    $logger = null,
        private readonly ?MediaConverterRegistry $converters = null,
    ) {}

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['http_timeout']) && (int) $settings['http_timeout'] > 0) {
            return (int) $settings['http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return $this->dispatch($arguments, $agentId, $userId);
    }

    public function describeAction(array $arguments): string
    {
        $url = trim((string) ($arguments['url'] ?? ''));
        $op  = (string) ($arguments['op'] ?? 'fetch');
        return "{$op} URL: {$url}";
    }

    private function dispatch(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $url = trim((string) ($arguments['url'] ?? ''));
        $op = (string) ($arguments['op'] ?? 'fetch');

        $validation = $this->validateUrl($url);
        if ($validation instanceof ToolResult) {
            return $validation;
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);

        try {
            return match ($op) {
                'fetch_pdf' => $this->processFetchedPdfContent($url, $settings),
                default     => $this->processFetchedContent($url, $settings),
            };
        } catch (Throwable $e) {
            $this->logger?->error('ReadUrlTool Exception', ['url' => $url, 'op' => $op, 'exception' => $e]);
            return new ToolResult(false, 'Failed to read URL: ' . $e->getMessage());
        }
    }

    private function validateUrl(string $url): ?ToolResult
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new ToolResult(false, 'A valid absolute URL is required.');
        }

        // Allowlist only http/https — blocks file://, ftp://, gopher://,
        // cloud metadata endpoints (http://169.254.169.254), etc.
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return new ToolResult(false, 'Only http:// and https:// URLs are supported.');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function processFetchedContent(string $url, array $settings): ToolResult
    {
        $timeout = $this->effectiveTimeout($settings);
        $headers = [
            'User-Agent' => 'Spora Agent/1.0 (+https://github.com/spora/spora)',
            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8',
        ];

        $this->logger?->debug('ReadUrlTool: HTTP request', [
            'method'  => 'GET',
            'url'     => $url,
            'headers' => $headers,
            'timeout' => $timeout,
        ]);

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => $timeout,
            'headers' => $headers,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            return new ToolResult(false, "Failed to fetch URL. HTTP Status: {$statusCode}");
        }

        $contentType = strtolower($response->getHeaders()['content-type'][0] ?? 'text/plain');
        $content     = $response->getContent(false);

        return $this->buildContentResult($contentType, $content);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function processFetchedPdfContent(string $url, array $settings): ToolResult
    {
        $converter = $this->resolvePdfConverter($url);
        if ($converter instanceof ToolResult) {
            return $converter;
        }

        $payload = $this->fetchPdfBytes($url, $settings);
        if ($payload instanceof ToolResult) {
            return $payload;
        }

        return $this->convertPdf($converter, $payload, $url);
    }

    private function resolvePdfConverter(string $url): MediaConverterInterface|ToolResult
    {
        if ($this->converters === null) {
            return new ToolResult(false, 'PDF fetching is unavailable: no converter registry is wired.');
        }
        $converter = $this->converters->findFor(self::PDF_MIME, basename(parse_url($url, PHP_URL_PATH) ?? ''));
        if ($converter === null) {
            return new ToolResult(false, 'No PDF converter is registered. Install a plugin that provides one.');
        }

        return $converter;
    }

    /** @param array<string, mixed> $settings */
    private function fetchPdfBytes(string $url, array $settings): string|ToolResult
    {
        $denied = $this->ssrfCheck($url);
        if ($denied !== null) {
            return $denied;
        }
        $timeout = $this->effectiveTimeout($settings);
        $this->logger?->debug('ReadUrlTool: fetching PDF', ['url' => $url, 'timeout' => $timeout]);

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => $timeout,
            'headers' => [
                'User-Agent' => 'Spora Agent/1.0 (+https://github.com/spora/spora)',
                'Accept'     => self::PDF_MIME,
            ],
        ]);

        return $this->validatePdfResponse($response);
    }

    private function validatePdfResponse(ResponseInterface $response): string|ToolResult
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            return new ToolResult(false, "Failed to fetch PDF. HTTP Status: {$statusCode}");
        }

        $bytes = $response->getContent(false);
        if (strlen($bytes) > self::MAX_PDF_BYTES) {
            return new ToolResult(false, sprintf(
                'PDF too large (%.1f MiB; cap is %d MiB).',
                strlen($bytes) / 1024 / 1024,
                self::MAX_PDF_BYTES / 1024 / 1024,
            ));
        }

        return $bytes;
    }

    private function ssrfCheck(string $url): ?ToolResult
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return new ToolResult(false, 'Refusing to fetch a URL with no hostname.');
        }
        $lower = strtolower($host);
        if (in_array($lower, self::SSRF_DENY_HOSTS, true)) {
            return new ToolResult(false, 'Refusing to fetch a loopback or link-local hostname.');
        }

        return $this->checkResolvedIps($lower, $host);
    }

    /**
     * Resolve the host (or treat the input as a literal IP, including
     * bracketed IPv6) and deny the URL when any resolved address falls
     * inside a private / loopback prefix.
     *
     * @return ?ToolResult null when the host cannot be resolved or no
     *                    resolved address matches the deny-list — letting
     *                    the request proceed to the HTTP layer.
     */
    private function checkResolvedIps(string $lowerHost, string $host): ?ToolResult
    {
        // Bare IPv6 hostnames may include brackets — strip them.
        $ip = trim($lowerHost, '[]');
        $resolved = filter_var($ip, FILTER_VALIDATE_IP) ? [$ip] : @gethostbynamel($host);
        if (!is_array($resolved)) {
            return null;
        }
        foreach ($resolved as $candidate) {
            foreach (self::SSRF_DENY_PREFIXES as $prefix) {
                if (str_starts_with((string) $candidate, $prefix)) {
                    return new ToolResult(false, 'Refusing to fetch a private or loopback IP.');
                }
            }
        }

        return null;
    }

    private function convertPdf(MediaConverterInterface $converter, string $bytes, string $url): ToolResult
    {
        try {
            $markdown = $converter->toMarkdown($bytes, self::PDF_MIME, basename(parse_url($url, PHP_URL_PATH) ?? null));
        } catch (Throwable $e) {
            $this->logger?->error('ReadUrlTool: PDF conversion failed', ['url' => $url, 'exception' => $e]);
            return new ToolResult(false, 'PDF conversion failed: ' . $e->getMessage());
        }
        if (trim($markdown) === '') {
            return new ToolResult(false, 'URL was fetched but no readable text was extracted from the PDF.');
        }

        return new ToolResult(true, "Fetched PDF Content (Markdown):\n\n" . $this->truncate($markdown));
    }

    private function buildContentResult(string $contentType, string $content): ToolResult
    {
        if (str_contains($contentType, 'xml') || str_contains($contentType, 'rss')) {
            return new ToolResult(true, "Fetched XML/RSS Content:\n\n" . $this->truncate($content));
        }
        if (str_contains($contentType, 'json')) {
            return new ToolResult(true, "Fetched JSON Content:\n\n" . $this->truncate($content));
        }
        return $this->convertHtmlToMarkdownResult($content);
    }

    private function convertHtmlToMarkdownResult(string $content): ToolResult
    {
        $converter = new HtmlConverter([
            'strip_tags'   => true,
            'remove_nodes' => 'script style nav footer header iframe',
        ]);

        $markdown = trim($converter->convert($content));

        if ($markdown === '') {
            return new ToolResult(false, 'URL was fetched successfully but no readable text content was found.');
        }

        // Size guard: cap output so large pages don't saturate the context window.
        return new ToolResult(true, "Fetched URL Content (Markdown):\n\n" . $this->truncate($markdown));
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) > self::MAX_OUTPUT_CHARS) {
            return mb_substr($text, 0, self::MAX_OUTPUT_CHARS) . "\n\n[Content truncated at " . number_format(self::MAX_OUTPUT_CHARS) . " characters]";
        }

        return $text;
    }
}
