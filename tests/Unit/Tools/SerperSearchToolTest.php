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

    $client->expects('request')->with('POST', 'https://serper.dev/search', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'apple';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['action' => 'search', 'q' => 'apple'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Steve Jobs')
        ->and($result->content)->toContain('Apple')
        ->and($result->content)->toContain('https://apple.com');
});

it('image_search makes correct http request and parses results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'images' => [
            ['title' => 'iPhone 15', 'imageUrl' => 'https://example.com/iphone.jpg', 'sourceUrl' => 'https://example.com', 'sourceName' => 'Example'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://serper.dev/images', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'iphone';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['action' => 'image_search', 'q' => 'iphone'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('iPhone 15')
        ->and($result->content)->toContain('https://example.com/iphone.jpg');
});

it('news_search makes correct http request and parses results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'news' => [
            ['title' => 'Tech News', 'source' => 'BBC', 'date' => '2024-01-01', 'link' => 'https://bbc.com/news', 'snippet' => 'Latest news'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://serper.dev/news', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'tech';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['action' => 'news_search', 'q' => 'tech'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Tech News')
        ->and($result->content)->toContain('BBC')
        ->and($result->content)->toContain('https://bbc.com/news');
});

it('video_search makes correct http request and parses results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'videos' => [
            ['title' => 'PHP Tutorial', 'source' => 'YouTube', 'date' => '2024-01-01', 'link' => 'https://youtube.com/watch?v=123', 'snippet' => 'Learn PHP'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://serper.dev/videos', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'php';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['action' => 'video_search', 'q' => 'php'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('PHP Tutorial')
        ->and($result->content)->toContain('YouTube');
});

it('scholar_search makes correct http request and parses results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'organic' => [
            ['title' => 'Machine Learning Paper', 'link' => 'https://scholar.google.com/paper', 'snippet' => 'Abstract here', 'publicationInfo' => 'Nature 2024'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://serper.dev/scholar', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'machine learning';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['action' => 'scholar_search', 'q' => 'machine learning'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Machine Learning Paper')
        ->and($result->content)->toContain('Nature 2024');
});

it('shopping_search makes correct http request and parses results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'shopping' => [
            ['title' => 'MacBook Pro', 'source' => 'Amazon', 'price' => '$1999', 'link' => 'https://amazon.com/macbook', 'snippet' => 'M3 Pro chip'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://serper.dev/shopping', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'macbook';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['action' => 'shopping_search', 'q' => 'macbook'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('MacBook Pro')
        ->and($result->content)->toContain('$1999')
        ->and($result->content)->toContain('Amazon');
});

it('patents_search makes correct http request and parses results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'patents' => [
            ['title' => 'Battery Patent', 'patentId' => 'US123456', 'date' => '2024-01-01', 'link' => 'https://patents.google.com/patent', 'snippet' => 'Improved battery tech'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://serper.dev/patents', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'battery';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['action' => 'patents_search', 'q' => 'battery'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Battery Patent')
        ->and($result->content)->toContain('US123456');
});

it('maps_search makes correct http request and parses results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'places' => [
            ['title' => 'Coffee Shop', 'address' => '123 Main St', 'phone' => '+1-555-0100', 'rating' => '4.5', 'hours' => '9am-5pm', 'website' => 'https://coffeeshop.com'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://serper.dev/maps', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'coffee';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['action' => 'maps_search', 'q' => 'coffee'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Coffee Shop')
        ->and($result->content)->toContain('123 Main St')
        ->and($result->content)->toContain('4.5');
});

it('places_search makes correct http request and parses results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SerperSearchTool::class, 1)->andReturn(['core.serper.api_key' => 'serp_123']);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'places' => [
            ['title' => 'Restaurant XYZ', 'address' => '456 Oak Ave', 'phone' => '+1-555-0200', 'rating' => '4.8', 'type' => 'Italian', 'openingHours' => ['Mon-Fri 10am-10pm', 'Sat-Sun 11am-11pm'], 'website' => 'https://restaurant.com', 'url' => 'https://restaurant.com/page'],
        ],
    ]);

    $client->expects('request')->with('POST', 'https://serper.dev/places', Mockery::on(function ($options) {
        return $options['headers']['X-API-KEY'] === 'serp_123' && $options['json']['q'] === 'restaurant';
    }))->andReturn($response);

    $tool = new SerperSearchTool($config, $client);

    $result = $tool->execute(['action' => 'places_search', 'q' => 'restaurant'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('Restaurant XYZ')
        ->and($result->content)->toContain('456 Oak Ave')
        ->and($result->content)->toContain('Italian');
});
