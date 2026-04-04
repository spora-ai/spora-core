<?php

declare(strict_types=1);

use Spora\Services\ToolConfigService;
use Spora\Tools\SerperSearchTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

it('returns error if api key is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['q' => 'apple'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('makes correct http request and parses organic and answer box results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'answerBox' => [
            'answer' => 'Steve Jobs',
        ],
        'organic' => [
            ['title' => 'Apple', 'link' => 'https://apple.com', 'snippet' => 'Tech company'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://google.serper.dev/search', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'apple';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['q' => 'apple'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Steve Jobs')
        ->and($result->content)->toContain('Apple')
        ->and($result->content)->toContain('https://apple.com');
});
