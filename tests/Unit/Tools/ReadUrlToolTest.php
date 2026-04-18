<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
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
