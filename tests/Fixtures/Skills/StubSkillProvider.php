<?php

declare(strict_types=1);

namespace Tests\Fixtures\Skills;

use Spora\Skills\SkillDescriptor;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillSummary;

/**
 * In-memory provider for registry tests.
 *
 * Stored as a **list**, not a name-keyed map, so a provider can hold two
 * skills with the same name — the case the registry must preserve rather than
 * silently collapse. Records the principal it was last called with, and can be
 * told to answer for a path it does not list: that is the "buggy provider" case
 * the caller's membership check exists to catch.
 */
final class StubSkillProvider implements SkillProviderInterface
{
    /** @var list<array{summary: SkillSummary, body: string, files: list<array{path: string, bytes: int}>, contents: array<string, string>, owner: ?int}> */
    private array $skills = [];

    public ?int $lastPrincipalId = null;

    public bool $answerUnlistedPaths = false;

    /**
     * Return content over {@see SkillProviderInterface::MAX_FILE_BYTES} for a
     * listed path, while the listing still advertises a small one. Models a
     * provider that skips the cap it is required to enforce.
     */
    public bool $ignoreSizeCap = false;

    public int $listCalls = 0;

    /**
     * When set, the provider becomes principal-scoped: it answers only for this
     * principal, and returns nothing at all for a null one. Left null the stub
     * behaves like a shipped-skill provider and ignores the principal.
     */
    public ?int $onlyVisibleTo = null;

    public function __construct(
        private readonly string $label = 'stub',
    ) {}

    public function source(): string
    {
        return $this->label;
    }

    /**
     * @param list<string> $paths
     * @param int|null     $owner        Principal this skill belongs to; null
     *                                    means principal-independent.
     * @param array<string, int>|null $pathBytes Per-path byte sizes to
     *                                    advertise in the listing, overriding
     *                                    the 4-byte default. Lets a test model
     *                                    a file the provider refuses to serve
     *                                    because it is over the cap.
     */
    public function add(
        string $name,
        array $paths = [],
        string $body = 'body',
        ?string $description = null,
        ?int $owner = null,
        ?array $pathBytes = null,
    ): self {
        $files = [];
        $contents = [];
        foreach ($paths as $path) {
            $files[] = ['path' => $path, 'bytes' => $pathBytes[$path] ?? 4];
            $contents[$path] = "content of {$path}";
        }

        $this->skills[] = [
            'summary'  => new SkillSummary(
                name: $name,
                description: $description ?? "Description of {$name}",
                license: 'MIT',
                source: $this->label,
                slug: $name,
                fileCount: count($files),
            ),
            'body'     => $body,
            'files'    => $files,
            'contents' => $contents,
            'owner'    => $owner,
        ];

        return $this;
    }

    public function getSkills(?int $principalId): array
    {
        $this->lastPrincipalId = $principalId;
        $this->listCalls++;

        $out = [];
        foreach ($this->skills as $skill) {
            if ($this->visibleTo($skill, $principalId)) {
                $out[] = $skill['summary'];
            }
        }

        return $out;
    }

    public function getSkillDetails(string $name, ?int $principalId): ?SkillDescriptor
    {
        $this->lastPrincipalId = $principalId;
        $skill = $this->firstNamed($name, $principalId);
        if ($skill === null) {
            return null;
        }

        return new SkillDescriptor(
            summary: $skill['summary'],
            body: $skill['body'],
            files: $skill['files'],
        );
    }

    public function getSkillFiles(string $name, ?int $principalId): ?array
    {
        $this->lastPrincipalId = $principalId;

        return $this->firstNamed($name, $principalId)['files'] ?? null;
    }

    public function getSkillFile(string $name, string $path, ?int $principalId): ?string
    {
        $this->lastPrincipalId = $principalId;
        $skill = $this->firstNamed($name, $principalId);
        if ($skill === null) {
            return null;
        }

        if ($this->answerUnlistedPaths) {
            return 'leaked ' . $path;
        }

        if ($this->ignoreSizeCap && ($skill['contents'][$path] ?? null) !== null) {
            return str_repeat('a', SkillProviderInterface::MAX_FILE_BYTES + 1);
        }

        // A compliant provider refuses an over-cap file before materialising
        // it, which is what `ignoreSizeCap = false` models.
        foreach ($skill['files'] as $entry) {
            if ($entry['path'] === $path && $entry['bytes'] > SkillProviderInterface::MAX_FILE_BYTES) {
                return null;
            }
        }

        return $skill['contents'][$path] ?? null;
    }

    /**
     * Fail closed for a scoped provider: an unresolvable principal sees nothing,
     * and a resolvable one sees only what it owns.
     *
     * @param array{summary: SkillSummary, body: string, files: list<array{path: string, bytes: int}>, contents: array<string, string>, owner: ?int} $skill
     */
    private function visibleTo(array $skill, ?int $principalId): bool
    {
        if ($this->onlyVisibleTo === null) {
            return true;
        }
        if ($principalId === null) {
            return false;
        }

        return $skill['owner'] === $principalId;
    }

    /**
     * @return array{summary: SkillSummary, body: string, files: list<array{path: string, bytes: int}>, contents: array<string, string>, owner: ?int}|null
     */
    private function firstNamed(string $name, ?int $principalId): ?array
    {
        foreach ($this->skills as $skill) {
            if ($skill['summary']->name === $name && $this->visibleTo($skill, $principalId)) {
                return $skill;
            }
        }

        return null;
    }
}
