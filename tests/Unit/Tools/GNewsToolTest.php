<?php

declare(strict_types=1);

use Spora\Services\ToolConfigService;
use Spora\Tools\GNewsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

it('returns error if api key is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(GNewsTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new GNewsTool($config, $client);

    $result = $tool->execute(['q' => 'news'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('is not configured');
});

it('makes correct http request and parses articles', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(GNewsTool::class, 1)->andReturn(['core.gnews.api_key' => 'gnews_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'totalArticles' => 1,
        'articles' => [
            [
                'title' => 'GNews Event',
                'source' => ['name' => 'CNN'],
                'publishedAt' => '2026-04-03',
                'description' => 'GNews desc.',
                'url' => 'https://cnn.com',
            ],
        ],
    ]);

    $client->expects('request')->with('GET', 'https://gnews.io/api/v4/search', Mockery::on(function ($options) {
        return $options['query']['apikey'] === 'gnews_123' && $options['query']['q'] === 'news';
    }))->andReturn($response);

    $tool = new GNewsTool($config, $client);

    $result = $tool->execute(['q' => 'news'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('GNews Event')
        ->and($result->content)->toContain('CNN')
        ->and($result->content)->toContain('GNews desc.');
});
