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
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Counts the calls it receives, so a test can assert a provider was *not*
 * reached — the empty-query guard is invisible from the response body alone,
 * because a provider that ignores the query returns the same hits either way.
 */
final class CountingSearchProvider implements SearchProviderInterface
{
    public int $calls = 0;

    /** @param list<SearchHit> $hits */
    public function __construct(private readonly array $hits) {}

    public function type(): string
    {
        return 'skill';
    }

    /** @return list<SearchHit> */
    public function search(string $query, SearchContext $context): array
    {
        $this->calls++;

        return $this->hits;
    }
}

/**
 * `GET /api/v1/search` — the endpoint the ⌘K palette calls.
 *
 * The behaviour worth protecting here is the boring one: an empty query must
 * not reach a provider at all, since a palette rendering "everything" on an
 * empty box looks hung. So the fixture hands back the provider alongside the
 * controller — a provider stub that ignored the query would satisfy every
 * response assertion in this file while the guard was gone.
 *
 * @return array{controller: SearchController, provider: CountingSearchProvider}
 */
function searchControllerFixture(?int $userId, ?array $hits = []): array
{
    $provider = new CountingSearchProvider($hits ?? []);

    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn($userId);

    return [
        'controller' => new SearchController(
            $auth,
            new SearchProviderRegistry([$provider]),
            new PrincipalService(new PrincipalResolver()),
        ),
        'provider'   => $provider,
    ];
}

/**
 * A user id with no principal row yet.
 *
 * The controller resolves a scope and, when `visiblePrincipalIdsFor()` comes
 * back empty, materialises the user-principal — which needs a real `users` row
 * and throws otherwise. `currentUserId()` can only return a real id in
 * production, so an arbitrary `1` is not a fixture the controller can honour.
 */
function searchCallerWithoutPrincipal(): int
{
    return bootAuth(bootAuthLayer(), 'search-scope-' . uniqid('', false) . '@example.com', 'Password1!');
}

function searchRequest(string $query): Request
{
    return Request::create('/api/v1/search', 'GET', $query === '' ? [] : ['q' => $query]);
}

it('refuses an unauthenticated caller', function () {
    $response = searchControllerFixture(null)['controller']->index(searchRequest('inv'));

    expect($response->getStatusCode())->toBe(401)
        ->and(json_decode((string) $response->getContent(), true))
        ->toHaveKey('error');
});

it('returns hits inside the standard data envelope', function () {
    $hit = new SearchHit(type: 'skill', id: 'invoice', label: 'invoice', subLabel: 'How to draft.');
    $body = json_decode(
        (string) searchControllerFixture(searchCallerWithoutPrincipal(), [$hit])['controller']->index(searchRequest('inv'))->getContent(),
        true,
    );

    expect($body)->toHaveKey('data')
        ->and($body['data']['hits'])->toHaveCount(1)
        ->and($body['data']['hits'][0]['id'])->toBe('invoice')
        ->and($body['data']['query'])->toBe('inv');
});

it('returns an empty result for an absent query', function () {
    // The provider is loaded with a hit it would return for *any* query, so
    // deleting the controller's empty-query short-circuit shows up twice: in
    // `data.hits` and in the call count.
    $fixture = searchControllerFixture(
        searchCallerWithoutPrincipal(),
        [new SearchHit(type: 'skill', id: 'invoice', label: 'invoice')],
    );

    $body = json_decode(
        (string) $fixture['controller']->index(searchRequest(''))->getContent(),
        true,
    );

    expect($body['data']['hits'])->toBe([])
        ->and($fixture['provider']->calls)->toBe(0);
});

it('answers an empty query with the same envelope as a real one', function () {
    // The palette sends a blank box on focus, so this is the common path, and a
    // client reading `data.query` got an undefined index on it.
    $fixture = searchControllerFixture(
        searchCallerWithoutPrincipal(),
        [new SearchHit(type: 'skill', id: 'invoice', label: 'invoice')],
    );

    $body = json_decode(
        (string) $fixture['controller']->index(searchRequest(''))->getContent(),
        true,
    );

    expect($body['data'])->toHaveKeys(['hits', 'query'])
        ->and($body['data']['query'])->toBe('')
        ->and($fixture['provider']->calls)->toBe(0);
});

it('lets Symfony reject an array-valued q rather than searching for "Array"', function () {
    // `?q[]=a` is not a string the controller has to defend against: Symfony's
    // InputBag throws BadRequestException before the value is ever read, so a
    // guard here would be a fallback for a case that cannot arrive. This test
    // pins that, so a future "just is_string() it" change is a deliberate
    // decision rather than an accident.
    $request = Request::create('/api/v1/search', 'GET', ['q' => ['a', 'b']]);

    expect(fn() => searchControllerFixture(searchCallerWithoutPrincipal())['controller']->index($request))
        ->toThrow(BadRequestException::class);
});

it('searches with a scope even when the caller has no principal row', function () {
    // `visiblePrincipalIdsFor()` does not auto-materialise, so a user whose
    // principal row is absent produced an empty SearchContext and an empty
    // palette — including for shipped skills, which no principal owns. The
    // provider here records the scope it was handed.
    $userId = searchCallerWithoutPrincipal();

    // Precondition: the caller must have no principal row yet.
    expect(
        Illuminate\Database\Capsule\Manager::table('principals')
            ->where('type', 'user')->where('user_id', $userId)->value('id'),
    )->toBeNull();

    $provider = new class implements SearchProviderInterface {
        /** @var list<int>|null */
        public ?array $seenScope = null;

        public function type(): string
        {
            return 'skill';
        }

        public function search(string $query, SearchContext $context): array
        {
            $this->seenScope = $context->principalIds();

            return [new SearchHit('skill', 'git', 'Git', null, null, '/apps/x/skill/git')];
        }
    };

    $auth = Mockery::mock(AuthService::class);
    $auth->shouldReceive('currentUserId')->andReturn($userId);
    $controller = new SearchController(
        $auth,
        new SearchProviderRegistry([$provider]),
        new PrincipalService(new PrincipalResolver()),
    );

    $body = json_decode((string) $controller->index(searchRequest('git'))->getContent(), true);

    // A provider must never be handed an empty scope: it cannot tell that apart
    // from "this caller has no skills", and returns nothing for both.
    expect($provider->seenScope)->not->toBeEmpty()
        ->and($body['data']['hits'])->toHaveCount(1);
});

it('truncates an over-long query instead of rejecting it', function () {
    $body = json_decode(
        (string) searchControllerFixture(searchCallerWithoutPrincipal())['controller']->index(searchRequest(str_repeat('a', 5000)))->getContent(),
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
        href: '/apps/custom-skills/skill/invoice',
    );
    $body = json_decode(
        (string) searchControllerFixture(searchCallerWithoutPrincipal(), [$hit])['controller']->index(searchRequest('inv'))->getContent(),
        true,
    );

    expect($body['data']['hits'][0])->toBe([
        'type'     => 'skill',
        'id'       => 'invoice',
        'label'    => 'invoice',
        'subLabel' => 'How to draft.',
        'badge'    => '1 warning',
        'href'     => '/apps/custom-skills/skill/invoice',
    ]);
});

it('serialises a hit with nowhere to go as an explicit null', function () {
    $body = json_decode(
        (string) searchControllerFixture(searchCallerWithoutPrincipal(), [new SearchHit(type: 'skill', id: 'typst', label: 'typst')])['controller']
            ->index(searchRequest('typst'))->getContent(),
        true,
    );

    expect($body['data']['hits'][0]['href'])->toBeNull();
});
