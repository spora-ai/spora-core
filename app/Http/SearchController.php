<?php

declare(strict_types=1);

namespace Spora\Http;

use OpenApi\Attributes as OA;
use Spora\Auth\AuthService;
use Spora\Search\SearchContext;
use Spora\Search\SearchProviderRegistry;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /api/v1/search` — the one index the host ⌘K palette can query, aggregating
 * every {@see \Spora\Search\SearchProviderInterface}.
 *
 * The query is clamped rather than rejected: ⌘K is a fire-everything-typed box,
 * and a 422 on a stray character would surface as an empty palette with no
 * explanation.
 */
final class SearchController
{
    use JsonControllerHelpers;

    /** Matches the palette's own practical input length. */
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(
        private readonly AuthService $auth,
        private readonly SearchProviderRegistry $providers,
        private readonly PrincipalService $principals,
    ) {}

    #[OA\Parameter(
        name: 'q',
        in: 'query',
        required: false,
        description: 'Search query, clamped to 200 characters rather than rejected. An empty or absent query returns no hits and never reaches a provider. Results are scoped to the caller\'s visible principals, resolved server-side and handed to every provider.',
        schema: new OA\Schema(type: 'string'),
    )]
    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $query = mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY_LENGTH);

        // An empty query is answered with the same envelope as a real one, so a
        // client reading `data.query` does not get an undefined index depending
        // on whether the palette sent a blank box.
        if ($query === '') {
            return new JsonResponse(['data' => ['hits' => [], 'query' => '']]);
        }

        // Resolved once and handed to every provider, so none can widen scope by
        // resolving it differently.
        //
        // `visiblePrincipalIdsFor()` does not auto-materialise, so a user whose
        // principal row is absent would get an empty scope and therefore an empty
        // palette — including for shipped skills, which no principal owns. The
        // materialise-on-empty shape is the same one `SkillController` uses.
        $principalIds = $this->principals->visiblePrincipalIdsFor($userId);
        if ($principalIds === []) {
            $principalIds = [(int) $this->principals->ensureUserPrincipal($userId)->id];
        }

        $hits = $this->providers->search($query, new SearchContext($principalIds));

        return new JsonResponse([
            'data' => [
                'hits'  => array_map(
                    static fn($hit): array => $hit->toArray(),
                    $hits,
                ),
                'query' => $query,
            ],
        ]);
    }
}
