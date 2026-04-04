<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\TavilySearchTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

it('returns error if api key is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(TavilySearchTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new TavilySearchTool($config, $client);

    $result = $tool->execute(['query' => 'apple'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('returns error if query is empty', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new TavilySearchTool($config, $client);

    $result = $tool->execute(['query' => ''], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('query cannot be empty');
});

it('makes correct http request and parses response', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(TavilySearchTool::class, 1)->andReturn(['core.tavily.api_key' => 'tav_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'answer' => 'Apples are red.',
        'results' => [
            ['title' => 'Apple Wikipedia', 'url' => 'https://wiki.org', 'content' => 'Lots of text.'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://api.tavily.com/search', Mockery::on(function ($options) {
        return $options['json']['api_key'] === 'tav_123' && $options['json']['query'] === 'apple';
    }))->andReturn($response);

    $tool = new TavilySearchTool($config, $client);

    $result = $tool->execute(['query' => 'apple'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Apples are red.')
        ->and($result->content)->toContain('Apple Wikipedia');
});

it('handles http error codes gracefully', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(TavilySearchTool::class, 1)->andReturn(['core.tavily.api_key' => 'tav_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(500);
    $response->allows('getContent')->andReturn('Server crash');

    $client->expects('request')->andReturn($response);
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->allows('error');

    $tool = new TavilySearchTool($config, $client, $logger);

    $result = $tool->execute(['query' => 'apple'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('HTTP 500');
});
