<?php

declare(strict_types=1);

use Spora\Auth\AuthService;
use Spora\Http\SearchController;
use Spora\Search\SearchContext;
use Spora\Search\SearchHit;
use Spora\Search\SearchProviderInterface;
use Spora\Search\SearchProviderRegistry;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /api/v1/search` — the endpoint the ⌘K palette calls. The behaviour worth
 * protecting here is the boring one: an empty query must not reach a provider at
 * all, since a palette rendering "everything" on an empty box looks hung.
 */
function searchControllerFixture(?int $userId, ?array $hits = []): SearchController
{
    $provider = new class ($hits) implements SearchProviderInterface {
        public int $calls = 0;

        /** @param list<SearchHit> $hits */
        public function __construct(private readonly array $hits) {}

        public function type(): string
        {
            return 'skill';
        }

        public function search(string $query, SearchContext $context): array
        {
            $this->calls++;

            return $this->hits;
        }
    };

    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn($userId);

    return new SearchController(
        $auth,
        new SearchProviderRegistry([$provider]),
        new PrincipalService(new PrincipalResolver()),
    );
}

function searchRequest(string $query): Request
{
    return Request::create('/api/v1/search', 'GET', $query === '' ? [] : ['q' => $query]);
}

it('refuses an unauthenticated caller', function () {
    $response = searchControllerFixture(null)->index(searchRequest('inv'));

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode((string) $response->getContent(), true))
        ->toHaveKey('error');
});

it('returns hits inside the standard data envelope', function () {
    $hit = new SearchHit(type: 'skill', id: 'invoice', label: 'invoice', subLabel: 'How to draft.');
    $body = json_decode(
        (string) searchControllerFixture(1, [$hit])->index(searchRequest('inv'))->getContent(),
        true,
    );

    expect($body)->toHaveKey('data')
        ->and($body['data']['hits'])->toHaveCount(1)
        ->and($body['data']['hits'][0]['id'])->toBe('invoice')
        ->and($body['data']['query'])->toBe('inv');
});

it('returns an empty result for an absent query', function () {
    $body = json_decode(
        (string) searchControllerFixture(1)->index(searchRequest(''))->getContent(),
        true,
    );

    expect($body['data']['hits'])->toBe([]);
});

it('truncates an over-long query instead of rejecting it', function () {
    $body = json_decode(
        (string) searchControllerFixture(1)->index(searchRequest(str_repeat('a', 5000)))->getContent(),
        true,
    );

    expect($body['data']['query'])->toBe(str_repeat('a', 200));
});

it('carries every hit field the palette reads', function () {
    $hit = new SearchHit(
        type: 'skill',
        id: 'invoice',
        label: 'invoice',
        subLabel: 'How to draft.',
        badge: '1 warning',
        href: '/apps/custom-skills?skill=invoice',
    );
    $body = json_decode(
        (string) searchControllerFixture(1, [$hit])->index(searchRequest('inv'))->getContent(),
        true,
    );

    expect($body['data']['hits'][0])->toBe([
        'type'     => 'skill',
        'id'       => 'invoice',
        'label'    => 'invoice',
        'subLabel' => 'How to draft.',
        'badge'    => '1 warning',
        'href'     => '/apps/custom-skills?skill=invoice',
    ]);
});

it('serialises a hit with nowhere to go as an explicit null', function () {
    $body = json_decode(
        (string) searchControllerFixture(1, [new SearchHit(type: 'skill', id: 'typst', label: 'typst')])
            ->index(searchRequest('typst'))->getContent(),
        true,
    );

    expect($body['data']['hits'][0]['href'])->toBeNull();
});
