<?php

declare(strict_types=1);

namespace Spora\Search;

use Throwable;

/**
 * Aggregates {@see SearchProviderInterface} implementations over the same
 * precedence rules as {@see \Spora\Skills\SkillProviderRegistry}: core first, so
 * installing a plugin cannot change an existing result.
 *
 * A provider that throws contributes nothing. ⌘K is how operators reach agents
 * and chats; one plugin's broken search must not make those unreachable too.
 */
final readonly class SearchProviderRegistry
{
    /** @var list<SearchProviderInterface> */
    private array $providers;

    /** @param list<SearchProviderInterface> $providers In precedence order. */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    /**
     * @return list<SearchHit>
     */
    public function search(string $query, SearchContext $context): array
    {
        /** @var array<string, true> $claimed */
        $claimed = [];
        $out = [];

        foreach ($this->providers as $provider) {
            try {
                $hits = $provider->search($query, $context);
            } catch (Throwable) {
                continue;
            }

            /** @var array<string, true> $mine */
            $mine = [];

            foreach ($hits as $hit) {
                // A duplicate across providers is a precedence decision; one
                // inside a single provider is that provider reporting a defect.
                $key = $hit->type . '::' . $hit->id;
                if (isset($claimed[$key]) && !isset($mine[$key])) {
                    continue;
                }
                $mine[$key] = true;
                $claimed[$key] = true;
                $out[] = $hit;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_map(
            static fn(SearchProviderInterface $p): string => $p->type(),
            $this->providers,
        );
    }
}
