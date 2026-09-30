<?php

declare(strict_types=1);

namespace Spora\Skills\Providers;

use Spora\Skills\Skill;
use Spora\Skills\SkillDescriptor;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillScanner;
use Spora\Skills\SkillSummary;

/**
 * Serves every skill on disk: the project root, the framework bundle, and each
 * plugin's `skillPaths()` root, in that priority order.
 *
 * **The principal is ignored, on purpose.** Shipped skills are operator-authored
 * and identical for everyone; scoping them per principal would mean a copy per
 * user of a file the operator already controls. A principal-scoped provider is a
 * different implementation, not a different argument to this one.
 *
 * The scan is memoised with an explicit {@see flush()} rather than a `static`:
 * skill directories are mutable, and a `static` would survive the container with
 * no way to invalidate it from here.
 */
final class FilesystemSkillProvider implements SkillProviderInterface
{
    /** @var array<string, Skill>|null */
    private ?array $byName = null;

    public function __construct(
        private readonly SkillScanner $scanner,
    ) {}

    public function source(): string
    {
        return 'filesystem';
    }

    /**
     * Drop the memo so the next read rescans the configured roots.
     */
    public function flush(): void
    {
        $this->byName = null;
    }

    public function getSkills(?int $principalId): array
    {
        $out = [];
        foreach ($this->index() as $skill) {
            $out[] = self::summarize($skill);
        }

        return $out;
    }

    public function getSkillDetails(string $name, ?int $principalId): ?SkillDescriptor
    {
        $skill = $this->index()[$name] ?? null;
        if ($skill === null) {
            return null;
        }

        return new SkillDescriptor(
            summary: self::summarize($skill),
            body: $skill->body(),
            compatibility: $skill->compatibility(),
            allowedTools: $skill->allowedTools(),
            metadata: $skill->metadata(),
            files: $skill->files(),
            warnings: $skill->warnings(),
        );
    }

    public function getSkillFiles(string $name, ?int $principalId): ?array
    {
        return ($this->index()[$name] ?? null)?->files();
    }

    public function getSkillFile(string $name, string $path, ?int $principalId): ?string
    {
        $real = $this->readableFile($name, $path);
        if ($real === null) {
            return null;
        }

        $contents = @file_get_contents($real);

        return $contents === false ? null : $contents;
    }

    /**
     * Absolute path of a file that is listed, contained and within the cap, or
     * null. The size check sits on the stat, not the read: checking afterwards
     * would already have paid the memory the cap exists to avoid.
     */
    private function readableFile(string $name, string $path): ?string
    {
        $skill = $this->index()[$name] ?? null;
        if ($skill === null) {
            return null;
        }

        $real = $this->resolveContained($skill, $path);
        if ($real === null) {
            return null;
        }

        $size = @filesize($real);

        return ($size === false || $size > self::MAX_FILE_BYTES) ? null : $real;
    }

    /**
     * Resolve `$path` to an absolute path proven inside the skill directory.
     *
     * Two independent checks, both required. The scanned listing is the cheap
     * one: `SkillScanner::collectFiles()` does not follow symlinks, so a link
     * planted inside a skill directory never appears in it. `realpath()`
     * containment is the expensive one and catches what the listing misses — a
     * skill directory that is itself a link, and a file swapped after the scan.
     */
    private function resolveContained(Skill $skill, string $path): ?string
    {
        $candidate = ltrim($path, '/');
        if ($candidate === '') {
            $candidate = $skill->filename();
        }

        $listed = false;
        foreach ($skill->files() as $entry) {
            if ($entry['path'] === $candidate) {
                $listed = true;

                break;
            }
        }
        if (!$listed) {
            return null;
        }

        $absolute = rtrim($skill->dir(), '/') . '/' . $candidate;
        $real = realpath($absolute);
        $rootReal = realpath($skill->dir());
        if ($real === false || $rootReal === false) {
            return null;
        }

        return str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR) ? $real : null;
    }

    private static function summarize(Skill $skill): SkillSummary
    {
        return new SkillSummary(
            name: $skill->name(),
            description: $skill->description(),
            license: $skill->license(),
            source: $skill->source(),
            slug: $skill->slug(),
            fileCount: count($skill->files()),
            hasWarnings: $skill->hasWarnings(),
        );
    }

    /**
     * @return array<string, Skill>
     */
    private function index(): array
    {
        if ($this->byName !== null) {
            return $this->byName;
        }

        $byName = [];
        foreach ($this->scanner->scan() as $skill) {
            // First entry wins, mirroring the scanner's root precedence: a
            // project skill must not be replaced by a same-named plugin one.
            $byName[$skill->name()] ??= $skill;
        }

        return $this->byName = $byName;
    }
}
