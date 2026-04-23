<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\SemanticScholarTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

it('returns error if query is empty on paper_search', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'paper_search', 'query' => ''], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('query cannot be empty');
});

it('returns error if paper_id is missing on get_paper', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'get_paper', 'paper_id' => ''], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('paper_id parameter is required');
});

it('returns error if paper_id is missing on get_citations', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'get_citations', 'paper_id' => ''], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('paper_id parameter is required');
});

it('returns error if paper_id is missing on get_references', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'get_references', 'paper_id' => ''], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('paper_id parameter is required');
});

it('returns error if paper_id is missing on get_recommendations', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'get_recommendations', 'paper_id' => ''], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('paper_id parameter is required');
});

it('returns error for unknown action', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $client = Mockery::mock(HttpClientInterface::class);
    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'unknown_action'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Unknown action 'unknown_action'");
});

it('paper_search makes correct HTTP request and parses response', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SemanticScholarTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'total' => 2,
        'data' => [
            [
                'title' => 'Attention is All You Need',
                'abstract' => 'We propose a new simple network architecture.',
                'authors' => [['name' => 'Ashish Vaswani', 'authorId' => 'a1']],
                'year' => 2017,
                'venue' => 'NeurIPS',
                'citationCount' => 98523,
                'url' => 'https://arxiv.org/abs/1706.03762',
                'externalIds' => ['DOI' => '10.48550/arXiv.1706.03762', 'ArXiv' => '1706.03762'],
                'openAccessPdf' => ['url' => 'https://arxiv.org/pdf/1706.03762'],
            ],
            [
                'title' => 'BERT: Pre-training of Deep Bidirectional Transformers',
                'abstract' => 'We introduce a new language understanding model.',
                'authors' => [['name' => 'Jacob Devlin', 'authorId' => 'j1']],
                'year' => 2018,
                'venue' => 'NAACL',
                'citationCount' => 72341,
                'url' => 'https://arxiv.org/abs/1810.04805',
                'externalIds' => ['DOI' => '10.48550/arXiv.1810.04805'],
                'openAccessPdf' => null,
            ],
        ],
    ]);

    $client->expects('request')->with('GET', 'https://api.semanticscholar.org/graph/v1/paper/search', Mockery::on(function ($options) {
        return $options['query']['query'] === 'transformer attention'
            && $options['query']['limit'] === 10
            && str_contains($options['query']['fields'], 'title');
    }))->andReturn($response);

    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'paper_search', 'query' => 'transformer attention'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('PAPER SEARCH RESULTS')
        ->and($result->content)->toContain('Attention is All You Need')
        ->and($result->content)->toContain('Ashish Vaswani')
        ->and($result->content)->toContain('2017')
        ->and($result->content)->toContain('NeurIPS')
        ->and($result->content)->toContain('98523')
        ->and($result->content)->toContain('10.48550/arXiv.1706.03762');
});

it('paper_search returns empty message when no results', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SemanticScholarTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn(['total' => 0, 'data' => []]);

    $client->expects('request')->andReturn($response);

    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'paper_search', 'query' => 'xyznonexistent'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('No papers found');
});

it('get_paper fetches paper metadata by ID', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SemanticScholarTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'title' => 'Attention is All You Need',
        'abstract' => 'We propose a new simple network architecture.',
        'authors' => [['name' => 'Ashish Vaswani', 'authorId' => 'a1']],
        'year' => 2017,
        'venue' => 'NeurIPS',
        'citationCount' => 98523,
        'url' => 'https://arxiv.org/abs/1706.03762',
        'externalIds' => ['DOI' => '10.48550/arXiv.1706.03762', 'ArXiv' => '1706.03762'],
        'openAccessPdf' => ['url' => 'https://arxiv.org/pdf/1706.03762'],
    ]);

    $client->expects('request')->with('GET', Mockery::type('string'), Mockery::on(function ($options) {
        return str_contains($options['query']['fields'], 'title');
    }))->andReturn($response);

    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'get_paper', 'paper_id' => 'ArXiv:1706.03762'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('PAPER DETAILS')
        ->and($result->content)->toContain('Attention is All You Need');
});

it('get_citations returns citing papers with pagination', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SemanticScholarTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'total' => 1,
        'data' => [
            ['citingPaper' => [
                'title' => 'Improving language understanding by generative pre-training',
                'authors' => [['name' => 'Alec Radford', 'authorId' => 'r1']],
                'year' => 2018,
                'venue' => '',
                'citationCount' => 45678,
                'url' => 'https://openai.com/research/gpt',
                'externalIds' => ['DOI' => '10.1000/xyz'],
                'openAccessPdf' => null,
            ]],
        ],
    ]);

    $client->expects('request')->with('GET', Mockery::type('string'), Mockery::on(function ($options) {
        return $options['query']['limit'] === 20
            && $options['query']['offset'] === 0;
    }))->andReturn($response);

    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'get_citations', 'paper_id' => 'abc123'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('CITATIONS OF')
        ->and($result->content)->toContain('Alec Radford');
});

it('get_references returns referenced papers', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SemanticScholarTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'total' => 1,
        'data' => [
            ['referencedPaper' => [
                'title' => 'Sequence to sequence learning with neural networks',
                'authors' => [['name' => 'Ilya Sutskever', 'authorId' => 's1']],
                'year' => 2014,
                'venue' => 'NeurIPS',
                'citationCount' => 23456,
                'url' => 'https://papers.nips.cc/paper/5346',
                'externalIds' => [],
                'openAccessPdf' => null,
            ]],
        ],
    ]);

    $client->expects('request')->andReturn($response);

    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'get_references', 'paper_id' => 'abc123'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('REFERENCES OF')
        ->and($result->content)->toContain('Ilya Sutskever');
});

it('get_recommendations returns recommended papers', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SemanticScholarTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn([
        'recommendedPapers' => [
            [
                'title' => 'Deep learning for natural language processing',
                'authors' => [['name' => 'Yoav Goldberg', 'authorId' => 'g1']],
                'year' => 2017,
                'venue' => 'Morgan & Claypool',
                'citationCount' => 3456,
                'url' => 'https://www.morganclaypool.com/doi/10.2200/S00762ED1V01Y201703HLT037',
                'externalIds' => ['DOI' => '10.2200/S00762ED1V01Y201703HLT037'],
                'openAccessPdf' => null,
            ],
        ],
    ]);

    $client->expects('request')->andReturn($response);

    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'get_recommendations', 'paper_id' => 'abc123'], 1);
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('RECOMMENDED PAPERS')
        ->and($result->content)->toContain('Yoav Goldberg');
});

it('handles HTTP error codes gracefully', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SemanticScholarTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(429);
    $response->allows('getContent')->andReturn('Rate limit exceeded');

    $client->expects('request')->andReturn($response);
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->allows('error');
    $logger->allows('debug');

    $tool = new SemanticScholarTool($config, $client, $logger);

    $result = $tool->execute(['action' => 'paper_search', 'query' => 'test'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('HTTP 429');
});

it('handles HTTP timeout from settings', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SemanticScholarTool::class, 1)->andReturn([
        'core.semantic_scholar.http_timeout' => '60',
    ]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn(['total' => 0, 'data' => []]);

    $client->expects('request')->with('GET', Mockery::any(), Mockery::on(function ($options) {
        return $options['timeout'] === 60;
    }))->andReturn($response);

    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute(['action' => 'paper_search', 'query' => 'test'], 1);
    expect($result->success)->toBeTrue();
});

it('paper_search respects open_access_only and year filters', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->with(SemanticScholarTool::class, 1)->andReturn([]);

    $client = Mockery::mock(HttpClientInterface::class);
    $response = Mockery::mock(ResponseInterface::class);

    $response->allows('getStatusCode')->andReturn(200);
    $response->allows('toArray')->andReturn(['total' => 0, 'data' => []]);

    $client->expects('request')->with('GET', Mockery::any(), Mockery::on(function ($options) {
        return $options['query']['year'] === '2023'
            && $options['query']['openAccessPdf'] === 'true';
    }))->andReturn($response);

    $tool = new SemanticScholarTool($config, $client);

    $result = $tool->execute([
        'action' => 'paper_search',
        'query' => 'test',
        'year' => '2023',
        'open_access_only' => true,
    ], 1);
    expect($result->success)->toBeTrue();
});
