<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use RuntimeException;
use Spora\Services\MediaArchive\Converters\PlainTextPassthroughConverter;
use Spora\Services\MediaArchive\MediaConverterDiscovery;
use Spora\Services\MediaArchive\MediaConverterInterface;
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\ToolConfigService;
use Spora\Tools\ReadUrlTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

function makeReadUrlToolConfig(): ToolConfigService
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);
    return $config;
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

afterEach(function (): void {
    MediaConverterDiscovery::reset();
});

// ---------------------------------------------------------------------
// fetch_pdf operation + SSRF deny-list (plan §12 m2-m3 + m2-m9)
// ---------------------------------------------------------------------

function pdfRegistry(MediaConverterInterface $converter): MediaConverterRegistry
{
    MediaConverterDiscovery::reset();
    MediaConverterDiscovery::add(PlainTextPassthroughConverter::class);
    $stub = new class($converter) implements \Psr\Container\ContainerInterface {
        public function __construct(private readonly MediaConverterInterface $converter) {}
        public function get(string $id): mixed
        {
            return $this->converter;
        }
        public function has(string $id): bool
        {
            return true;
        }
    };
    return new MediaConverterRegistry($stub);
}

it('fetches a PDF and converts it to markdown via the registry', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getContent')->andReturn('%PDF-1.4 fake-bytes');

    $client->expects('request')->with('GET', 'https://example.com/doc.pdf', Mockery::any())->andReturn($response);

    $converter = Mockery::mock(MediaConverterInterface::class);
    $converter->allows('supportedMimeTypes')->andReturn(['application/pdf']);
    $converter->allows('supportedExtensions')->andReturn(['pdf']);
    $converter->shouldReceive('toMarkdown')->once()->andReturn('# Heading\n\nbody text');

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, pdfRegistry($converter));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/doc.pdf'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Fetched PDF Content')
        ->and($result->content)->toContain('# Heading');
});

it('returns an error when no PDF converter is registered', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    MediaConverterDiscovery::reset();
    $stub = new class implements \Psr\Container\ContainerInterface {
        public function get(string $id): mixed
        {
            throw new RuntimeException("no converters: {$id}");
        }
        public function has(string $id): bool
        {
            return false;
        }
    };
    $registry = new MediaConverterRegistry($stub);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, $registry);
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/doc.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('No PDF converter is registered');
});

it('returns an error when the converter registry is not wired', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new ReadUrlTool($client, makeReadUrlToolConfig());
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/doc.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('no converter registry is wired');
});

it('returns an error when the converter throws during conversion', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getContent')->andReturn('%PDF-1.4 corrupt');

    $client->expects('request')->andReturn($response);

    $converter = Mockery::mock(MediaConverterInterface::class);
    $converter->allows('supportedMimeTypes')->andReturn(['application/pdf']);
    $converter->allows('supportedExtensions')->andReturn(['pdf']);
    $converter->shouldReceive('toMarkdown')->once()->andThrow(new RuntimeException('corrupt pdf'));

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->allows('error');
    $logger->allows('debug');

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), $logger, pdfRegistry($converter));
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

    $converter = Mockery::mock(MediaConverterInterface::class);
    $converter->allows('supportedMimeTypes')->andReturn(['application/pdf']);
    $converter->allows('supportedExtensions')->andReturn(['pdf']);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, pdfRegistry($converter));
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

    $converter = Mockery::mock(MediaConverterInterface::class);
    $converter->allows('supportedMimeTypes')->andReturn(['application/pdf']);
    $converter->allows('supportedExtensions')->andReturn(['pdf']);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, pdfRegistry($converter));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/big.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('PDF too large');
});

it('returns an error when the converter returns empty markdown', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('getContent')->andReturn('%PDF-1.4 scanned');

    $client->expects('request')->andReturn($response);

    $converter = Mockery::mock(MediaConverterInterface::class);
    $converter->allows('supportedMimeTypes')->andReturn(['application/pdf']);
    $converter->allows('supportedExtensions')->andReturn(['pdf']);
    $converter->shouldReceive('toMarkdown')->once()->andReturn('   ');

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, pdfRegistry($converter));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'https://example.com/scanned.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('no readable text was extracted');
});

it('refuses to fetch a PDF over the cloud-metadata IP (SSRF guard)', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $converter = Mockery::mock(MediaConverterInterface::class);
    $converter->allows('supportedMimeTypes')->andReturn(['application/pdf']);
    $converter->allows('supportedExtensions')->andReturn(['pdf']);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, pdfRegistry($converter));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'http://169.254.169.254/latest/meta-data/'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('private or loopback IP');
});

it('refuses to fetch a PDF from a loopback hostname', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $converter = Mockery::mock(MediaConverterInterface::class);
    $converter->allows('supportedMimeTypes')->andReturn(['application/pdf']);
    $converter->allows('supportedExtensions')->andReturn(['pdf']);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, pdfRegistry($converter));
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'http://localhost/secret.pdf'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('loopback or link-local hostname');
});

it('refuses to fetch a PDF from a private RFC1918 IP', function () {
    $client = Mockery::mock(HttpClientInterface::class);
    $client->shouldNotReceive('request');

    $converter = Mockery::mock(MediaConverterInterface::class);
    $converter->allows('supportedMimeTypes')->andReturn(['application/pdf']);
    $converter->allows('supportedExtensions')->andReturn(['pdf']);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, pdfRegistry($converter));
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

    $converter = Mockery::mock(MediaConverterInterface::class);
    $converter->allows('supportedMimeTypes')->andReturn(['application/pdf']);
    $converter->allows('supportedExtensions')->andReturn(['pdf']);

    $tool = new ReadUrlTool($client, makeReadUrlToolConfig(), null, pdfRegistry($converter));
    // `http://%20/path` is a syntactically valid URL whose host part
    // (%20) cannot resolve; verify it isn't fetched.
    $result = $tool->execute(['op' => 'fetch_pdf', 'url' => 'http://%20/path.pdf'], 1);

    expect($result->success)->toBeFalse();
});
