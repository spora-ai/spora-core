<?php

declare(strict_types=1);

namespace Spora\Skills;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Scans one or more skill roots for skill definitions.
 *
 * A skill is a directory containing (at minimum) a `SKILL.md` file with
 * YAML frontmatter. The scanner walks each configured root depth-1,
 * picks up every `SKILL.md`, parses the frontmatter + body, validates
 * the result, and returns {@see Skill} value objects. Sidecar files
 * under each skill directory are enumerated in the `files` listing.
 *
 * Sources are scanned in priority order — caller is responsible for
 * passing project, then framework, then plugin roots. Earlier roots
 * win on name conflict; same-source conflicts surface a
 * `SKILL_NAME_CONFLICT` warning rather than silently dropping either
 * copy. Two skills with the same name from DIFFERENT sources are
 * treated as distinct entries (the source prefix is part of the
 * dedup key), since the project/framework/plugin priority model
 * only suppresses lower-priority entries for the same source bucket.
 *
 * Files that fail to parse, fail frontmatter parsing, or fail
 * validation are NOT silently dropped — they return a Skill whose
 * `warnings` array carries the failure. Operators must always see
 * why a bundled skill didn't make it (mirrors
 * {@see \Spora\AgentTemplates\AgentTemplateScanner}).
 */
final class SkillScanner
{
    /**
     * @param list<array{path: string, source: string}> $roots
     *        Scan roots, each carrying a `source` label used to bucket
     *        same-named skills and to populate `Skill::source()`.
     *        Typical sources: `'project'`, `'core'`, or a plugin slug.
     */
    public function __construct(
        private readonly array $roots = [],
    ) {}

    /**
     * @return list<Skill>
     */
    public function scan(): array
    {
        $validator = new SkillValidator();
        $seen = [];
        $skills = [];

        foreach ($this->roots as $root) {
            $dir = $root['path'];
            $source = $root['source'];
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }

            foreach ($this->skillDirectoriesIn($dir) as $skillDir) {
                $slug = basename($skillDir);
                $key = $source . '::' . $slug;

                if (isset($seen[$key])) {
                    $skills[] = $this->errorSkill(
                        $skillDir,
                        $source,
                        'SKILL_NAME_CONFLICT',
                        sprintf(
                            "Skill '%s' is duplicated at '%s' (already seen at '%s' under source '%s').",
                            $slug,
                            $skillDir,
                            $seen[$key],
                            $source,
                        ),
                    );
                    continue;
                }
                $seen[$key] = $skillDir;

                $skills[] = $this->loadSkill($skillDir, $source, $validator);
            }
        }

        return $skills;
    }

    /**
     * List skill directories inside a scan root. Depth 1 — only immediate
     * subdirectories are considered; a subdirectory only counts when it
     * contains a SKILL.md file.
     *
     * @return list<string>
     */
    private function skillDirectoriesIn(string $root): array
    {
        $finder = (new Finder())
            ->directories()
            ->in($root)
            ->depth(0)
            ->sortByName();

        $out = [];
        foreach ($finder as $dir) {
            $real = $dir->getRealPath();
            if (is_string($real) && is_file($real . '/SKILL.md')) {
                $out[] = $real;
            }
        }
        return $out;
    }

    private function loadSkill(string $skillDir, string $source, SkillValidator $validator): Skill
    {
        $slug = basename($skillDir);
        $skillMd = $skillDir . '/SKILL.md';

        $contents = @file_get_contents($skillMd);
        if ($contents === false) {
            return $this->errorSkill(
                $skillDir,
                $source,
                'SKILL_MD_UNREADABLE',
                "Could not read '{$skillMd}'.",
            );
        }

        [$frontmatter, $body] = $this->parseFrontmatter($contents);
        if ($frontmatter === null) {
            return $this->errorSkill(
                $skillDir,
                $source,
                'SKILL_FRONTMATTER_MISSING',
                "SKILL.md in '{$slug}' must start with a YAML frontmatter block delimited by '---'.",
            );
        }

        $result = $validator->validate($frontmatter, $body, $slug);

        $files = $this->collectFiles($skillDir);

        return new Skill(
            frontmatter: $frontmatter,
            body: $body,
            dir: $skillDir,
            filename: 'SKILL.md',
            files: $files,
            initialWarnings: $result->isValid() ? $result->warnings() : array_merge($result->errors(), $result->warnings()),
            source: $source,
        );
    }

    /**
     * Split a SKILL.md into its frontmatter and body. The frontmatter is
     * the YAML block delimited by `---` on its own line at the very top of
     * the file and another `---` line closing it; the body is everything
     * after the closing delimiter, trimmed of the leading newline.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function parseFrontmatter(string $contents): array
    {
        $contents = ltrim($contents, "\xEF\xBB\xBF");
        if (!str_starts_with($contents, '---')) {
            return [null, ''];
        }

        $rest = substr($contents, 3);
        $newlinePos = strpos($rest, "\n");
        if ($newlinePos === false) {
            return [null, ''];
        }
        $afterFirst = substr($rest, $newlinePos + 1);
        $closePos = strpos($afterFirst, "\n---");
        if ($closePos === false) {
            return [null, ''];
        }

        $yamlBlock = substr($afterFirst, 0, $closePos);
        $afterClose = substr($afterFirst, $closePos + 4);
        $body = ltrim($afterClose, "\n\r");

        try {
            $parsed = Yaml::parse($yamlBlock);
        } catch (Throwable) {
            return [null, $body];
        }
        if (!is_array($parsed)) {
            return [null, $body];
        }
        /** @var array<string, mixed> $parsed */
        return [$parsed, $body];
    }

    /**
     * Recursively list every file under the skill directory, with paths
     * relative to the skill root and byte sizes. Returns the listing in
     * stable sort order so the LLM-facing `files` payload is deterministic.
     *
     * @return list<array{path: string, bytes: int}>
     */
    private function collectFiles(string $skillDir): array
    {
        $finder = (new Finder())
            ->files()
            ->in($skillDir)
            ->ignoreVCS(true)
            ->ignoreDotFiles(true)
            ->sortByName();

        $out = [];
        foreach ($finder as $file) {
            $relative = $file->getRelativePathname();
            $out[] = [
                'path'  => $relative,
                'bytes' => (int) $file->getSize(),
            ];
        }
        return $out;
    }

    private function errorSkill(string $skillDir, string $source, string $code, string $message): Skill
    {
        return new Skill(
            frontmatter: [],
            body: '',
            dir: $skillDir,
            filename: 'SKILL.md',
            files: [],
            initialWarnings: [[
                'code'     => $code,
                'severity' => 'error',
                'message'  => $message,
                'path'     => basename($skillDir) . '/SKILL.md',
            ]],
            source: $source,
        );
    }
}
