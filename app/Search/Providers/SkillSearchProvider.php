<?php

declare(strict_types=1);

namespace Spora\Search\Providers;

use Spora\Apps\AppRegistry;
use Spora\Search\SearchContext;
use Spora\Search\SearchHit;
use Spora\Search\SearchProviderInterface;
use Spora\Skills\SkillProviderRegistry;

/**
 * Makes every visible skill searchable in the host palette.
 *
 * Reads through {@see SkillProviderRegistry} rather than querying skills, so
 * shipped and plugin-authored skills appear together with no work from the
 * plugin. Scope is structural: it iterates {@see SearchContext::principalIds()}
 * and nothing else, so no branch here can be edited into widening.
 */
final readonly class SkillSearchProvider implements SearchProviderInterface
{
    /** Palette shows a bounded list; a skill install is not a document set. */
    private const MAX_HITS = 20;

    public function __construct(
        private SkillProviderRegistry $skills,
        private AppRegistry $apps,
    ) {}

    public function type(): string
    {
        return 'skill';
    }

    /**
     * @return list<SearchHit>
     */
    public function search(string $query, SearchContext $context): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        /** @var list<array{int, string, SearchHit}> $scored */
        $scored = [];

        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($context->principalIds() as $principalId) {
            foreach ($this->skills->getSkills($principalId) as $summary) {
                $rank = $this->rank($summary->name, $summary->description, $needle);
                if ($rank === null) {
                    continue;
                }

                // A shipped skill resolves to the same summary for every
                // principal, because the filesystem provider ignores the
                // principal. Without this the loop emits one identical hit per
                // visible principal, and each duplicate also spends a slot of
                // `MAX_HITS`. Keyed exactly as `SkillController::index()` keys
                // its own loop, so the two endpoints cannot disagree.
                $key = $summary->source . '::' . $summary->name;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $scored[] = [$rank, $summary->name, new SearchHit(
                    type: $this->type(),
                    id: $summary->name,
                    label: $summary->name,
                    subLabel: $summary->description,
                    badge: $summary->hasWarnings ? '1 warning' : null,
                    href: $this->hrefFor($summary->source, $summary->name),
                )];
            }
        }

        usort(
            $scored,
            static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]],
        );

        return array_slice(array_column($scored, 2), 0, self::MAX_HITS);
    }

    /**
     * Lower is better; null means no match.
     *
     * The tiers are ordered best-to-worst and the first match wins, so the
     * order here is the ranking. Name matches outrank description matches
     * because prose is weak evidence: without the split every descriptive word
     * outranks the one skill actually typed.
     */
    private function rank(string $name, string $description, string $needle): ?int
    {
        $subject = mb_strtolower($name);
        $prose = mb_strtolower($description);

        $tiers = [
            $subject === $needle,
            str_starts_with($subject, $needle),
            str_contains($subject, $needle),
            $description !== '' && str_contains($prose, $needle),
        ];

        foreach ($tiers as $rank => $matched) {
            if ($matched) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * Route for a skill, or null when the host has nowhere to show it.
     *
     * The host has no skills page, so a plugin app is the only destination that
     * can exist. Shipped skills match no app and come back unrouted rather than
     * with a link that would 404.
     *
     * A path segment, not a query parameter, which is what this emitted first. The
     * host router registers no child route for either, so the app parses the path
     * itself — but a path is what browser back/forward, a hard refresh and a
     * pasted link all carry, and a query parameter on an app route is invisible to
     * every one of them. It also matches what `spora-plugin-media-archive` already
     * does for an asset (`/apps/media-archive/asset/{id}`), so the palette hands out
     * one shape of link rather than two.
     */
    private function hrefFor(?string $source, string $name): ?string
    {
        if ($source === null || $source === '' || $this->apps->get($source) === null) {
            return null;
        }

        return '/apps/' . rawurlencode($source) . '/skill/' . rawurlencode($name);
    }
}
