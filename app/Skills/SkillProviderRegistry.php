<?php

declare(strict_types=1);

namespace Spora\Skills;

/**
 * The single read path for skills, over an ordered list of
 * {@see SkillProviderInterface} implementations.
 *
 * Built once from a **static** class list — core first, then plugin
 * `skillProviders()`. Two consequences, both load-bearing:
 *
 * - **Core wins every collision**, so installing a plugin can never change what
 *   an existing agent's `allowed_skills` resolves to.
 * - **Duplicates across providers are dropped; duplicates inside one provider
 *   are not.** A provider surfacing two skills with one name is reporting a real
 *   defect in itself, and hiding it would discard the evidence. Cross-provider
 *   duplicates are a genuine precedence decision instead.
 *
 * No lookup cache: providers differ in lookup cost and freshness and each knows
 * which applies to it, and caching here would hide a database-backed skill
 * written mid-worker-run.
 */
final readonly class SkillProviderRegistry
{
    /** @var list<SkillProviderInterface> */
    private array $providers;

    /**
     * @param list<SkillProviderInterface> $providers In precedence order; the
     *        first entry wins a name collision.
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    /**
     * @return list<SkillSummary>
     */
    public function getSkills(?int $principalId): array
    {
        /** @var array<string, true> $claimed */
        $claimed = [];
        $out = [];

        foreach ($this->providers as $provider) {
            // Tracked apart from $claimed so a provider's own duplicates
            // survive; only a name held by an *earlier* provider is suppressed.
            /** @var array<string, true> $mine */
            $mine = [];

            foreach ($provider->getSkills($principalId) as $summary) {
                if (isset($claimed[$summary->name]) && !isset($mine[$summary->name])) {
                    continue;
                }
                $mine[$summary->name] = true;
                $claimed[$summary->name] = true;
                $out[] = $summary;
            }
        }

        return $out;
    }

    public function getSkillDetails(string $name, ?int $principalId): ?SkillDescriptor
    {
        foreach ($this->providers as $provider) {
            $descriptor = $provider->getSkillDetails($name, $principalId);
            if ($descriptor !== null) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * @return list<array{path: string, bytes: int}>|null
     */
    public function getSkillFiles(string $name, ?int $principalId): ?array
    {
        $provider = $this->ownerOf($name, $principalId);

        return $provider?->getSkillFiles($name, $principalId);
    }

    public function getSkillFile(string $name, string $path, ?int $principalId): ?string
    {
        $provider = $this->ownerOf($name, $principalId);
        if ($provider === null) {
            return null;
        }

        return $provider->getSkillFile($name, $path, $principalId);
    }

    /**
     * The provider that owns `$name`, or null when no provider has it.
     *
     * Resolved by asking for the listing rather than the summary, so a provider
     * that knows the name but stores no files still wins it. Routing both
     * `getSkillFiles()` and `getSkillFile()` through one owner is what keeps a
     * membership check and the read after it from landing in different providers.
     */
    private function ownerOf(string $name, ?int $principalId): ?SkillProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getSkillFiles($name, $principalId) !== null) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        $sources = [];
        foreach ($this->providers as $provider) {
            $sources[] = $provider->source();
        }

        return $sources;
    }
}
