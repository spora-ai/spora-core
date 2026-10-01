<?php

declare(strict_types=1);

namespace Spora\Http;

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

    public function index(Request $request): JsonResponse
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return $this->unauthenticated();
        }

        $query = mb_substr(trim((string) $request->query->get('q', '')), 0, self::MAX_QUERY_LENGTH);

        if ($query === '') {
            return new JsonResponse(['data' => ['hits' => []]]);
        }

        // Resolved once and handed to every provider, so none can widen scope by
        // resolving it differently.
        $context = new SearchContext($this->principals->visiblePrincipalIdsFor($userId));
        $hits = $this->providers->search($query, $context);

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
