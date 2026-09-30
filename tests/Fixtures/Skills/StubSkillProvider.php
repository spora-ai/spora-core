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
    /** @var list<array{summary: SkillSummary, body: string, files: list<array{path: string, bytes: int}>, contents: array<string, string>}> */
    private array $skills = [];

    public ?int $lastPrincipalId = null;

    public bool $answerUnlistedPaths = false;

    public int $listCalls = 0;

    public function __construct(
        private readonly string $label = 'stub',
    ) {}

    public function source(): string
    {
        return $this->label;
    }

    /**
     * @param list<string> $paths
     */
    public function add(string $name, array $paths = [], string $body = 'body', ?string $description = null): self
    {
        $files = [];
        $contents = [];
        foreach ($paths as $path) {
            $files[] = ['path' => $path, 'bytes' => 4];
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
        ];

        return $this;
    }

    public function getSkills(?int $principalId): array
    {
        $this->lastPrincipalId = $principalId;
        $this->listCalls++;

        return array_map(static fn(array $s): SkillSummary => $s['summary'], $this->skills);
    }

    public function getSkillDetails(string $name, ?int $principalId): ?SkillDescriptor
    {
        $this->lastPrincipalId = $principalId;
        $skill = $this->firstNamed($name);
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

        return $this->firstNamed($name)['files'] ?? null;
    }

    public function getSkillFile(string $name, string $path, ?int $principalId): ?string
    {
        $this->lastPrincipalId = $principalId;
        $skill = $this->firstNamed($name);
        if ($skill === null) {
            return null;
        }

        if ($this->answerUnlistedPaths) {
            return 'leaked ' . $path;
        }

        return $skill['contents'][$path] ?? null;
    }

    /**
     * @return array{summary: SkillSummary, body: string, files: list<array{path: string, bytes: int}>, contents: array<string, string>}|null
     */
    private function firstNamed(string $name): ?array
    {
        foreach ($this->skills as $skill) {
            if ($skill['summary']->name === $name) {
                return $skill;
            }
        }

        return null;
    }
}
