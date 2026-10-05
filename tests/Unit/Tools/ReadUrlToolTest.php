<?php

declare(strict_types=1);

use Mockery;
use Psr\Log\LoggerInterface;
use Spora\Services\MediaArchive\PdfMarkdownExtractor;
use Spora\Services\ToolConfigService;
use Spora\Tools\ReadUrlTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

function makeReadUrlToolConfig(): ToolConfigService
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);
    return $config;
}

/**
 * The PDF extractor backed by a parser mock, which is what
 * {@see ReadUrlTool::fetch_pdf} extracts through — the same helper the
 * archive's `md` derivative producer uses, so a remote PDF and an
 * uploaded one read identically.
 *
 * @param string|Throwable $result A Throwable makes the parser throw.
 */
function makePdfExtractor(string|Throwable $result): PdfMarkdownExtractor
{
    $parser = Mockery::mock(Iamgerwin\PdfToMarkdownParser\PdfToMarkdownParser::class);
    $parser->shouldReceive('parseContent')->andReturnUsing(
        static function () use ($result): string {
            if ($result instanceof Throwable) {
                throw $result;
            }
            return $result;
        },
    );
    return new PdfMarkdownExtractor($parser);
}

it('fetches valid html and converts to markdown', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getHeaders')->andReturn(['content-type' => ['text/html; charset=utf-8']]);
    $response->allows('getContent')->andReturn(
        '<html><body><h1>Hello World</h1><script>alert("bad")</script><p>Test</p></body></html>',
    );

    $client->expects('request')->with('GET', 'https://example.com', Mockery::any())->andReturn($response);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig());
    $result = $tool->execute(['url' => 'https://example.com'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Hello World')
        ->and($result->content)->toContain('Test')
        ->and($result->content)->not->toContain('alert');
});

it('fetches xml or rss directly without converting to markdown', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getHeaders')->andReturn(['content-type' => ['application/rss+xml']]);
    $response->allows('getContent')->andReturn('<rss><channel><title>RSS Feed</title></channel></rss>');

    $client->expects('request')->with('GET', 'https://example.com/feed.xml', Mockery::any())->andReturn($response);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig());
    $result = $tool->execute(['url' => 'https://example.com/feed.xml'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<title>RSS Feed</title>')
        ->and($result->content)->not->toContain('Markdown'); // Just raw fetch
});

it('returns error on invalid url', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new ReadUrlTool($client, makeReadUrlToolConfig());

    $result = $tool->execute(['url' => 'not-a-valid-url'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('valid absolute URL is required');
});

it('blocks non-http schemes to prevent SSRF', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new ReadUrlTool($client, makeReadUrlToolConfig());

    foreach (['file:///etc/passwd', 'ftp://internal.host/file', 'gopher://evil.com'] as $url) {
        $result = $tool->execute(['url' => $url], 1);
        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('Only http:// and https://');
    }
});

it('truncates very large responses to protect the context window', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getHeaders')->andReturn(['content-type' => ['text/plain']]);
    $response->allows('getContent')->andReturn(str_repeat('A', 50_000));

    $client->expects('request')->andReturn($response);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig());
    $result = $tool->execute(['url' => 'https://example.com/huge'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('[Content truncated at')
        ->and(mb_strlen($result->content))->toBeLessThan(45_000);
});

it('gracefully handles http error response', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(404);

    $client->expects('request')->with('GET', 'https://example.com/404', Mockery::any())->andReturn($response);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig());
    $result = $tool->execute(['url' => 'https://example.com/404'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('HTTP Status: 404');
});

it('gracefully handles http client exceptions', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->allows('error');
    $logger->allows('debug');

    $client->expects('request')->andThrows(new Exception('Network timeout'));

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), $logger);
    $result = $tool->execute(['url' => 'https://example.com/timeout'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Network timeout');
});

// ---------------------------------------------------------------------
// fetch_pdf: extraction + the SSRF / size guards on the way in
// ---------------------------------------------------------------------

it('fetches a PDF and returns its text as markdown', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getContent')->andReturn('%PDF-1.4 fake-bytes');

    $client->expects('request')->with('GET', 'https://example.com/doc.pdf', Mockery::any())->andReturn($response);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, makePdfExtractor("# Heading\n\nbody text"));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/doc.pdf'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Fetched PDF Content')
        ->and($result->content)->toContain('# Heading');
});

it('returns an error when no PDF extractor is wired', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new ReadUrlTool($client, makeReadUrlToolConfig());
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/doc.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('no PDF extractor is wired');
});

it('returns an error when the extractor throws during conversion', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getContent')->andReturn('%PDF-1.4 corrupt');

    $client->expects('request')->andReturn($response);

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->allows('error');
    $logger->allows('debug');

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), $logger, makePdfExtractor(new RuntimeException('corrupt pdf')));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/doc.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('PDF conversion failed')
        ->and($result->content)->toContain('corrupt pdf');
});

it('returns an error when the PDF fetch itself fails (HTTP 4xx)', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(404);

    $client->expects('request')->andReturn($response);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, makePdfExtractor('unused'));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/missing.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Failed to fetch PDF')
        ->and($result->content)->toContain('404');
});

it('returns an error when the PDF is over the 50 MiB cap', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    // 51 MiB of payload — just over the cap.
    $response->allows('getContent')->andReturn(str_repeat('A', 51 * 1024 * 1024));

    $client->expects('request')->andReturn($response);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, makePdfExtractor('unused'));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/big.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('PDF too large');
});

it('returns an error when the extractor yields no readable text', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getContent')->andReturn('%PDF-1.4 scanned');

    $client->expects('request')->andReturn($response);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, makePdfExtractor('   '));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/scanned.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('no readable text was extracted');
});

it('refuses to fetch a PDF over the cloud-metadata IP (SSRF guard)', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, makePdfExtractor('unused'));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'http://169.254.169.254/latest/meta-data/'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('private or loopback IP');
});

it('refuses to fetch a PDF from a loopback hostname', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, makePdfExtractor('unused'));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'http://localhost/secret.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('loopback or link-local hostname');
});

it('refuses to fetch a PDF from a private RFC1918 IP', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, makePdfExtractor('unused'));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'http://10.0.0.5/internal.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('private or loopback IP');
});

it('returns a helpful error when the fetch_pdf URL has no hostname after validation', function () {
    // `http://` with a URL-encoded whitespace host (`%20`) passes
    // `filter_var`/`parse_url` but `gethostbynamel` returns false; the
    // SSRF guard must short-circuit rather than issuing the request.
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, makePdfExtractor('unused'));
    // `http://%20/path` is a syntactically valid URL whose host part
    // (%20) cannot resolve; verify it isn't fetched.
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'http://%20/path.pdf'], 1);

    expect($result->success)->toBeFalse();
});
