<?php

declare(strict_types=1);

namespace Spora\Skills;

use Spora\Skills\Providers\FilesystemSkillProvider;

/**
 * The single read path for skills, over an ordered list of
 * {@see SkillProviderInterface} implementations.
 *
 * Built once by the container from a **static** class list — core's
 * {@see FilesystemSkillProvider} first, then whatever plugins declare through
 * `SporaExtensionInterface::skillProviders()`. Two properties follow from that
 * ordering and both are load-bearing:
 *
 * - **Core wins every collision.** A plugin cannot shadow a shipped skill by
 *   reusing its name, so installing a plugin can never change what an existing
 *   agent's `allowed_skills` resolves to.
 * - **Duplicates across providers are dropped; duplicates inside one provider
 *   are not.** A provider surfacing two skills with the same name is reporting
 *   a real defect in that provider (the filesystem scanner already turns the
 *   on-disk case into a `SKILL_NAME_CONFLICT` warning), so hiding it here would
 *   discard the evidence. Cross-provider duplicates are a different thing — a
 *   genuine precedence decision — so the earlier provider takes them.
 *
 * The registry owns no state beyond its provider list. It deliberately does not
 * cache lookups: providers differ in how expensive a lookup is and how long it
 * stays fresh, and each already knows which of those applies to it. Caching here
 * would impose one policy on both and, for a database-backed provider, would
 * hide a skill written mid-worker-run.
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
            // Names this provider contributed. Tracked separately from
            // $claimed so a provider's own duplicates survive — only a name
            // already held by an *earlier* provider is suppressed.
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
     * Ownership is resolved by asking each provider for the listing rather than
     * for the summary, so that a provider which knows the name but stores no
     * files (every one of them, for a `[]` listing) still wins it. Routing both
     * `getSkillFiles()` and `getSkillFile()` through one owner is what keeps a
     * membership check and the read that follows it from landing in different
     * providers.
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
