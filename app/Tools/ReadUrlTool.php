<?php

declare(strict_types=1);

namespace Spora\Tools;

use League\HTMLToMarkdown\HtmlConverter;
use Psr\Log\LoggerInterface;
use Spora\Services\MediaArchive\PdfMarkdownExtractor;
use Spora\Services\SsrfGuard;
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
 *   - `fetch_pdf`  — fetches a remote PDF and returns its text as
 *                    Markdown via
 *                    {@see \Spora\Services\MediaArchive\PdfMarkdownExtractor}
 *                    — the same extraction the archive's `md`
 *                    derivative producer uses, so a remote PDF and an
 *                    uploaded one read identically. Uses the same
 *                    HttpClient and `validateUrl()` as `fetch`.
 *
 * Every operation passes {@see \Spora\Services\SsrfGuard} before a request
 * goes out: an agent may name any URL, and the guard refuses the ones that
 * resolve inside the deployment's own network. Both operations share the
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

    /** Permitted URL schemes — restricts SSRF via file://, gopher://, etc. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** Hard cap on PDF bytes — protects against multi-hundred-MB PDFs. */
    private const MAX_PDF_BYTES = 50 * 1024 * 1024;

    /** PDF MIME type — sent as the `Accept` header on `fetch_pdf`. */
    private const PDF_MIME = 'application/pdf';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ToolConfigService   $configService,
        private readonly HttpTimeoutResolver $timeoutResolver,
        private readonly ?LoggerInterface    $logger = null,
        private readonly ?PdfMarkdownExtractor $pdfExtractor = null,
        // The container injects the shared guard; the default keeps a
        // directly constructed tool screened too.
        private readonly SsrfGuard           $ssrfGuard = new SsrfGuard(SsrfGuard::WEB_FETCH_POLICY),
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?\Spora\Services\PrincipalContext $context = null,
    ): ToolResult {
        $userId = $context?->ownerUserId;
        return $this->dispatch($arguments, $agentId, $userId);
    }

    public function describeAction(array $arguments): string
    {
        $url = trim((string) ($arguments['url'] ?? ''));
        $op  = (string) ($arguments['op'] ?? 'fetch');
        return sprintf('%s URL: %s', $op, $url);
    }

    private function dispatch(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $url = trim((string) ($arguments['url'] ?? ''));
        $op = (string) ($arguments['op'] ?? 'fetch');

        $validation = $this->validateUrl($url);
        if ($validation instanceof ToolResult) {
            return $validation;
        }

        // Every operation is screened, not just the PDF one: an agent names
        // the URL, and an internal endpoint's body would reach the model.
        $rejection = $this->ssrfGuard->rejectReason($url);
        if ($rejection !== null) {
            return new ToolResult(false, $rejection);
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

        // The scheme allowlist stops file://, ftp:// and gopher:// — it does
        // not stop an http:// URL pointing at a private address. That screen
        // is a separate layer, in SsrfGuard, and applies to every operation.
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
        $timeout = $this->timeoutResolver->resolve($settings['http_timeout'] ?? null);
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
            // The guard screened the URL as given; a redirect target is a
            // host nobody screened.
            'max_redirects' => 0,
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
        if ($this->pdfExtractor === null) {
            return new ToolResult(false, 'PDF fetching is unavailable: no PDF extractor is wired.');
        }

        $payload = $this->fetchPdfBytes($url, $settings);
        if ($payload instanceof ToolResult) {
            return $payload;
        }

        return $this->convertPdf($payload, $url);
    }

    /** @param array<string, mixed> $settings */
    private function fetchPdfBytes(string $url, array $settings): string|ToolResult
    {
        $timeout = $this->timeoutResolver->resolve($settings['http_timeout'] ?? null);
        $this->logger?->debug('ReadUrlTool: fetching PDF', ['url' => $url, 'timeout' => $timeout]);

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => $timeout,
            'headers' => [
                'User-Agent' => 'Spora Agent/1.0 (+https://github.com/spora/spora)',
                'Accept'     => self::PDF_MIME,
            ],
            // The guard screened the URL as given; a redirect target is a
            // host nobody screened.
            'max_redirects' => 0,
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

    private function convertPdf(string $bytes, string $url): ToolResult
    {
        try {
            $markdown = $this->pdfExtractor?->extract($bytes) ?? '';
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
