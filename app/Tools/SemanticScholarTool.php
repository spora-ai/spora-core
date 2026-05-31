<?php

declare(strict_types=1);

namespace Spora\Tools;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\Traits\HasOperations;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Searches academic papers and retrieves metadata via the Semantic Scholar API.
 * Supports paper search, citations, references, and recommendations. Free, no API key required.
 */
#[Tool(
    name: 'semantic_scholar',
    description: 'Search academic papers, fetch metadata, citations, references, and recommendations using the Semantic Scholar API. Free, no API key required.',
    displayName: 'Semantic Scholar',
    category: 'research',
)]
#[ToolOperation(name: 'paper_search', description: 'Search for academic papers by keyword', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_paper', description: 'Get full metadata for a specific paper by its ID', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_citations', description: 'List papers that cite a specific paper', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_references', description: 'List papers referenced by a specific paper', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get_recommendations', description: 'Find related or recommended papers for a given paper', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'core.semantic_scholar.http_timeout',
    label: 'HTTP Timeout',
    type: 'text',
    description: 'Seconds before an HTTP request fails (default: 30)',

)]
#[ToolParameter(name: 'action', type: 'string', description: 'The research operation: paper_search, get_paper, get_citations, get_references, get_recommendations', required: true, enum: ['paper_search', 'get_paper', 'get_citations', 'get_references', 'get_recommendations'])]
#[ToolParameter(name: 'query', type: 'string', description: 'Search query for paper_search (e.g. "machine learning protein folding")', required: false)]
#[ToolParameter(name: 'paper_id', type: 'string', description: 'Semantic Scholar paper ID (40-char hex), DOI, ArXiv ID, or PubMed ID. Required for get_paper, get_citations, get_references, get_recommendations.', required: false)]
#[ToolParameter(name: 'limit', type: 'number', description: 'Maximum number of results (1-100 for search/citations/references, 1-20 for recommendations).', required: false)]
#[ToolParameter(name: 'offset', type: 'number', description: 'Pagination offset for citations/references results.', required: false)]
#[ToolParameter(name: 'year', type: 'string', description: 'Year filter for paper_search (e.g. "2023", "2020-2024", "<2020").', required: false)]
#[ToolParameter(name: 'open_access_only', type: 'boolean', description: 'Restrict paper_search to open-access papers with available PDFs.', required: false)]
final class SemanticScholarTool implements ToolInterface
{
    use HasOperations;

    private const BASE_URL = 'https://api.semanticscholar.org';
    private const GRAPH_FIELDS = 'title,abstract,authors,year,venue,citationCount,url,openAccessPdf,externalIds,isOpenAccess';
    private const REC_FIELDS = 'title,abstract,authors,year,venue,citationCount,url,openAccessPdf,externalIds';

    public function __construct(
        private readonly ToolConfigService $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null): ToolResult
    {
        $action = $arguments['action'] ?? 'paper_search';

        return match ($action) {
            'paper_search' => $this->paperSearch($arguments, $agentId, $userId),
            'get_paper' => $this->getPaper($arguments, $agentId, $userId),
            'get_citations' => $this->getCitations($arguments, $agentId, $userId),
            'get_references' => $this->getReferences($arguments, $agentId, $userId),
            'get_recommendations' => $this->getRecommendations($arguments, $agentId, $userId),
            default => new ToolResult(false, "Unknown action '{$action}'. Valid actions: paper_search, get_paper, get_citations, get_references, get_recommendations."),
        };
    }

    public function describeAction(array $arguments): string
    {
        $action = $arguments['action'] ?? 'paper_search';

        return match ($action) {
            'paper_search' => "Search Semantic Scholar for: '" . ($arguments['query'] ?? '') . "'",
            'get_paper' => "Get paper details for: " . ($arguments['paper_id'] ?? ''),
            'get_citations' => "Get citations for paper: " . ($arguments['paper_id'] ?? ''),
            'get_references' => "Get references for paper: " . ($arguments['paper_id'] ?? ''),
            'get_recommendations' => "Get recommended papers for: " . ($arguments['paper_id'] ?? ''),
            default => "Unknown research action: {$action}",
        };
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'The research operation to perform.',
                    'enum' => ['paper_search', 'get_paper', 'get_citations', 'get_references', 'get_recommendations'],
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query (required for paper_search). E.g. "machine learning protein folding"',
                ],
                'paper_id' => [
                    'type' => 'string',
                    'description' => 'Semantic Scholar paper ID (40-char hex) or external ID (DOI, ArXiv, PubMed). Required for get_paper, get_citations, get_references, get_recommendations.',
                ],
                'limit' => [
                    'type' => 'number',
                    'description' => 'Maximum number of results (1-100 for search/citations/references, 1-20 for recommendations).',
                ],
                'offset' => [
                    'type' => 'number',
                    'description' => 'Pagination offset for citations/references results.',
                ],
                'year' => [
                    'type' => 'string',
                    'description' => 'Year filter for paper_search (e.g. "2023", "2020-2024", "<2020").',
                ],
                'open_access_only' => [
                    'type' => 'boolean',
                    'description' => 'Restrict paper_search to open-access papers with available PDFs.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function paperSearch(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return new ToolResult(false, 'The search query cannot be empty.');
        }

        $limit = min(100, max(1, (int) ($arguments['limit'] ?? 10)));
        $year = trim((string) ($arguments['year'] ?? ''));
        $openAccessOnly = !empty($arguments['open_access_only']);

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $params = [
            'query' => $query,
            'limit' => $limit,
            'fields' => self::GRAPH_FIELDS,
        ];
        if ($year !== '') {
            $params['year'] = $year;
        }
        if ($openAccessOnly) {
            $params['openAccessPdf'] = 'true';
        }

        try {
            $url = self::BASE_URL . '/graph/v1/paper/search';
            $this->logger?->debug('SemanticScholarTool: HTTP request', [
                'method' => 'GET',
                'url' => $url,
                'query' => $params,
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $response = $this->httpClient->request(
                'GET',
                $url,
                ['query' => $params, 'timeout' => $this->effectiveTimeout($settings)],
            );

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('SemanticScholarTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => $url,
            ]);

            if ($statusCode >= 400) {
                $this->logger?->error('SemanticScholar API error', ['status' => $statusCode]);
                return new ToolResult(false, "Research tool failed with HTTP {$statusCode}");
            }

            $data = $response->toArray(false);
            $papers = $data['data'] ?? [];
            $total = $data['total'] ?? 0;

            if ($papers === []) {
                return new ToolResult(true, "No papers found for query '{$query}'.");
            }

            $output = "PAPER SEARCH RESULTS for '{$query}' ({$total} total, showing " . count($papers) . ")\n\n";
            foreach ($papers as $i => $paper) {
                $num = $i + 1;
                $output .= "[{$num}] " . $this->formatPaperSummary($paper) . "\n\n";
            }

            return new ToolResult(true, $output, [
                'total' => $total,
                'returned' => count($papers),
                'query' => $query,
            ]);
        } catch (Throwable $e) {
            $this->logger?->error('SemanticScholarTool Exception', ['exception' => $e]);
            return new ToolResult(false, "Research tool error: " . $e->getMessage());
        }
    }

    public function getPaper(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $paperId = trim((string) ($arguments['paper_id'] ?? ''));
        if ($paperId === '') {
            return new ToolResult(false, 'The paper_id parameter is required.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);

        try {
            $url = self::BASE_URL . '/graph/v1/paper/' . urlencode($paperId);
            $this->logger?->debug('SemanticScholarTool: HTTP request', [
                'method' => 'GET',
                'url' => $url,
                'query' => ['fields' => self::GRAPH_FIELDS],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $response = $this->httpClient->request(
                'GET',
                $url,
                [
                    'query' => ['fields' => self::GRAPH_FIELDS],
                    'timeout' => $this->effectiveTimeout($settings),
                ],
            );

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('SemanticScholarTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => $url,
            ]);

            if ($statusCode >= 400) {
                $this->logger?->error('SemanticScholar API error', ['status' => $statusCode]);
                return new ToolResult(false, "Research tool failed with HTTP {$statusCode}");
            }

            $paper = $response->toArray(false);

            return new ToolResult(true, "PAPER DETAILS\n\n" . $this->formatPaperFull($paper), [
                'paper_id' => $paperId,
                'title' => $paper['title'] ?? 'Unknown',
            ]);
        } catch (Throwable $e) {
            $this->logger?->error('SemanticScholarTool Exception', ['exception' => $e]);
            return new ToolResult(false, "Research tool error: " . $e->getMessage());
        }
    }

    public function getCitations(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $paperId = trim((string) ($arguments['paper_id'] ?? ''));
        if ($paperId === '') {
            return new ToolResult(false, 'The paper_id parameter is required.');
        }

        $limit = min(100, max(1, (int) ($arguments['limit'] ?? 20)));
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);

        try {
            $url = self::BASE_URL . '/graph/v1/paper/' . urlencode($paperId) . '/citations';
            $this->logger?->debug('SemanticScholarTool: HTTP request', [
                'method' => 'GET',
                'url' => $url,
                'query' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'fields' => self::GRAPH_FIELDS,
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $response = $this->httpClient->request(
                'GET',
                $url,
                [
                    'query' => [
                        'limit' => $limit,
                        'offset' => $offset,
                        'fields' => self::GRAPH_FIELDS,
                    ],
                    'timeout' => $this->effectiveTimeout($settings),
                ],
            );

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('SemanticScholarTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => $url,
            ]);

            if ($statusCode >= 400) {
                $this->logger?->error('SemanticScholar API error', ['status' => $statusCode]);
                return new ToolResult(false, "Research tool failed with HTTP {$statusCode}");
            }

            $data = $response->toArray(false);
            $citations = $data['data'] ?? [];
            $total = $data['total'] ?? 0;

            if ($citations === []) {
                return new ToolResult(true, "No citations found for paper '{$paperId}'.");
            }

            $output = "CITATIONS OF {$paperId} ({$total} total, showing " . count($citations) . " from offset {$offset})\n\n";
            foreach ($citations as $i => $entry) {
                $num = $i + 1;
                $citingPaper = $entry['citingPaper'] ?? [];
                $output .= "[{$num}] " . $this->formatPaperSummary($citingPaper) . "\n\n";
            }

            return new ToolResult(true, $output, [
                'paper_id' => $paperId,
                'total' => $total,
                'returned' => count($citations),
                'offset' => $offset,
            ]);
        } catch (Throwable $e) {
            $this->logger?->error('SemanticScholarTool Exception', ['exception' => $e]);
            return new ToolResult(false, "Research tool error: " . $e->getMessage());
        }
    }

    public function getReferences(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $paperId = trim((string) ($arguments['paper_id'] ?? ''));
        if ($paperId === '') {
            return new ToolResult(false, 'The paper_id parameter is required.');
        }

        $limit = min(100, max(1, (int) ($arguments['limit'] ?? 20)));
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);

        try {
            $url = self::BASE_URL . '/graph/v1/paper/' . urlencode($paperId) . '/references';
            $this->logger?->debug('SemanticScholarTool: HTTP request', [
                'method' => 'GET',
                'url' => $url,
                'query' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'fields' => self::GRAPH_FIELDS,
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $response = $this->httpClient->request(
                'GET',
                $url,
                [
                    'query' => [
                        'limit' => $limit,
                        'offset' => $offset,
                        'fields' => self::GRAPH_FIELDS,
                    ],
                    'timeout' => $this->effectiveTimeout($settings),
                ],
            );

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('SemanticScholarTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => $url,
            ]);

            if ($statusCode >= 400) {
                $this->logger?->error('SemanticScholar API error', ['status' => $statusCode]);
                return new ToolResult(false, "Research tool failed with HTTP {$statusCode}");
            }

            $data = $response->toArray(false);
            $references = $data['data'] ?? [];
            $total = $data['total'] ?? 0;

            if ($references === []) {
                return new ToolResult(true, "No references found for paper '{$paperId}'.");
            }

            $output = "REFERENCES OF {$paperId} ({$total} total, showing " . count($references) . " from offset {$offset})\n\n";
            foreach ($references as $i => $entry) {
                $num = $i + 1;
                $referencedPaper = $entry['citedPaper'] ?? [];
                $output .= "[{$num}] " . $this->formatPaperSummary($referencedPaper) . "\n\n";
            }

            return new ToolResult(true, $output, [
                'paper_id' => $paperId,
                'total' => $total,
                'returned' => count($references),
                'offset' => $offset,
            ]);
        } catch (Throwable $e) {
            $this->logger?->error('SemanticScholarTool Exception', ['exception' => $e]);
            return new ToolResult(false, "Research tool error: " . $e->getMessage());
        }
    }

    public function getRecommendations(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $paperId = trim((string) ($arguments['paper_id'] ?? ''));
        if ($paperId === '') {
            return new ToolResult(false, 'The paper_id parameter is required.');
        }

        $limit = min(20, max(1, (int) ($arguments['limit'] ?? 10)));

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);

        try {
            $url = self::BASE_URL . '/recommendations/v1/papers/forpaper/' . urlencode($paperId);
            $this->logger?->debug('SemanticScholarTool: HTTP request', [
                'method' => 'GET',
                'url' => $url,
                'query' => [
                    'limit' => $limit,
                    'fields' => self::REC_FIELDS,
                ],
                'timeout' => $this->effectiveTimeout($settings),
            ]);

            $response = $this->httpClient->request(
                'GET',
                $url,
                [
                    'query' => [
                        'limit' => $limit,
                        'fields' => self::REC_FIELDS,
                    ],
                    'timeout' => $this->effectiveTimeout($settings),
                ],
            );

            $statusCode = $response->getStatusCode();
            $this->logger?->debug('SemanticScholarTool: HTTP response', [
                'status_code' => $statusCode,
                'url' => $url,
            ]);

            if ($statusCode >= 400) {
                $this->logger?->error('SemanticScholar API error', ['status' => $statusCode]);
                return new ToolResult(false, "Research tool failed with HTTP {$statusCode}");
            }

            $data = $response->toArray(false);
            $recommended = $data['recommendedPapers'] ?? [];

            if ($recommended === []) {
                return new ToolResult(true, "No recommendations found for paper '{$paperId}'.");
            }

            $output = "RECOMMENDED PAPERS for {$paperId} (" . count($recommended) . " results)\n\n";
            foreach ($recommended as $i => $paper) {
                $num = $i + 1;
                $output .= "[{$num}] " . $this->formatPaperSummary($paper) . "\n\n";
            }

            return new ToolResult(true, $output, [
                'paper_id' => $paperId,
                'returned' => count($recommended),
            ]);
        } catch (Throwable $e) {
            $this->logger?->error('SemanticScholarTool Exception', ['exception' => $e]);
            return new ToolResult(false, "Research tool error: " . $e->getMessage());
        }
    }

    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['core.semantic_scholar.http_timeout']) && (int) $settings['core.semantic_scholar.http_timeout'] > 0) {
            return (int) $settings['core.semantic_scholar.http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    private function formatPaperSummary(array $paper): string
    {
        $title = $paper['title'] ?? 'Unknown Title';
        $authors = $this->formatAuthors($paper['authors'] ?? []);
        $year = $paper['year'] ?? 'Unknown Year';
        $venue = $paper['venue'] ?? '';
        $citations = $paper['citationCount'] ?? 0;
        $url = $paper['url'] ?? '';
        $doi = $paper['externalIds']['DOI'] ?? '';
        $isOpenAccess = !empty($paper['openAccessPdf']['url']);

        $lines = [];
        $lines[] = "{$title}";
        $lines[] = "Authors: {$authors} [{$year}]";
        if ($venue !== '') {
            $lines[] = "Venue: {$venue}";
        }
        $lines[] = "Citations: {$citations}";
        if ($url !== '') {
            $lines[] = "URL: {$url}";
        }
        if ($doi !== '') {
            $lines[] = "DOI: {$doi}";
        }
        $lines[] = "Open Access: " . ($isOpenAccess ? 'Yes' : 'No');

        $abstract = $paper['abstract'] ?? '';
        if ($abstract !== '') {
            $truncated = mb_strlen($abstract) > 500
                ? mb_substr($abstract, 0, 500) . '...'
                : $abstract;
            $lines[] = "Abstract: {$truncated}";
        }

        return implode("\n    ", $lines);
    }

    private function formatPaperFull(array $paper): string
    {
        $title = $paper['title'] ?? 'Unknown Title';
        $authors = $this->formatAuthors($paper['authors'] ?? []);
        $year = $paper['year'] ?? 'Unknown Year';
        $venue = $paper['venue'] ?? '';
        $citations = $paper['citationCount'] ?? 0;
        $url = $paper['url'] ?? '';
        $doi = $paper['externalIds']['DOI'] ?? '';
        $arxiv = $paper['externalIds']['ArXiv'] ?? '';
        $pubmed = $paper['externalIds']['PubMed'] ?? '';
        $isOpenAccess = !empty($paper['openAccessPdf']['url']);
        $pdfUrl = $paper['openAccessPdf']['url'] ?? '';
        $abstract = $paper['abstract'] ?? '';

        $lines = [];
        $lines[] = "Title: {$title}";
        $lines[] = "Authors: {$authors}";
        $lines[] = "Year: {$year}";
        if ($venue !== '') {
            $lines[] = "Venue: {$venue}";
        }
        $lines[] = "Citations: {$citations}";
        $lines[] = "Open Access: " . ($isOpenAccess ? 'Yes' : 'No');
        if ($pdfUrl !== '') {
            $lines[] = "PDF: {$pdfUrl}";
        }
        if ($url !== '') {
            $lines[] = "URL: {$url}";
        }
        if ($doi !== '') {
            $lines[] = "DOI: {$doi}";
        }
        if ($arxiv !== '') {
            $lines[] = "ArXiv: {$arxiv}";
        }
        if ($pubmed !== '') {
            $lines[] = "PubMed: {$pubmed}";
        }
        if ($abstract !== '') {
            $lines[] = "\nAbstract:\n{$abstract}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{name: string, authorId: string}> $authors
     */
    private function formatAuthors(array $authors): string
    {
        if ($authors === []) {
            return 'Unknown';
        }
        $names = array_map(fn($a) => $a['name'], $authors);
        if (count($names) > 3) {
            return implode(', ', array_slice($names, 0, 3)) . ', et al.';
        }
        return implode(', ', $names);
    }
}
