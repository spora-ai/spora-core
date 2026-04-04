<?php

declare(strict_types=1);

use Spora\Services\ToolConfigService;
use Spora\Tools\NewsApiTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

it('returns error if api key is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(NewsApiTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new NewsApiTool($config, $client);

    $result = $tool->execute(['q' => 'news'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('is not configured');
});

it('makes correct http request and parses articles', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(NewsApiTool::class, 1)->andReturn(['core.newsapi.api_key' => 'news_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'totalResults' => 1,
        'articles' => [
            [
                'title' => 'Important Event',
                'source' => ['name' => 'BBC'],
                'publishedAt' => '2026-04-03',
                'description' => 'Something happened.',
                'url' => 'https://bbc.co.uk/news',
            ],
        ],
    ]);

    $client->expects('request')->with('GET', 'https://newsapi.org/v2/everything', Mockery::on(function ($options) {
        return $options['headers']['X-Api-Key'] === 'news_123' && $options['query']['q'] === 'news';
    }))->andReturn($response);

    $tool = new NewsApiTool($config, $client);

    $result = $tool->execute(['q' => 'news'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Important Event')
        ->and($result->content)->toContain('BBC')
        ->and($result->content)->toContain('Something happened.');
});
