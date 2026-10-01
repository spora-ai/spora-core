<?php

declare(strict_types=1);

namespace Spora\Search;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Aggregates {@see SearchProviderInterface} implementations over the same
 * precedence rules as {@see \Spora\Skills\SkillProviderRegistry}: core first, so
 * installing a plugin cannot change an existing result.
 *
 * A provider that throws contributes nothing, and is logged rather than dropped
 * in silence — a provider that fails on every call is otherwise
 * indistinguishable from one that simply has no results. ⌘K is a global
 * affordance, so one broken plugin must not take the whole palette down; that is
 * the reason for the policy, and it applies to the palette only. The skills
 * registry does not fail soft, because a broken provider there reaches the
 * agent's tool definition on every tick and there is no palette to protect.
 */
final readonly class SearchProviderRegistry
{
    /** @var list<SearchProviderInterface> */
    private array $providers;

    private readonly ?LoggerInterface $logger;

    /**
     * @param list<SearchProviderInterface> $providers In precedence order.
     */
    public function __construct(array $providers = [], ?LoggerInterface $logger = null)
    {
        $this->providers = $providers;
        $this->logger = $logger;
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
            } catch (Throwable $e) {
                $this->logger?->warning('SearchProviderRegistry: provider failed, its section is omitted', [
                    'provider'  => $provider::class,
                    'exception' => $e,
                ]);

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
