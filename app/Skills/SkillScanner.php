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
     * Canonical entry file inside a skill directory. Kept as a constant
     * so the scanner, the Skill tool, and any future consumers agree on
     * the filename.
     */
    public const SKILL_FILE = 'SKILL.md';

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
     * contains the SKILL.md entry file.
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

        $entryFile = self::SKILL_FILE;
        $out = [];
        foreach ($finder as $dir) {
            $real = $dir->getRealPath();
            if (is_string($real) && is_file($real . '/' . $entryFile)) {
                $out[] = $real;
            }
        }
        return $out;
    }

    private function loadSkill(string $skillDir, string $source, SkillValidator $validator): Skill
    {
        $parsed = $this->parseSkillFile($skillDir, $source, $validator);
        if ($parsed['error'] !== null) {
            return $parsed['error'];
        }

        return new Skill(
            frontmatter: $parsed['frontmatter'],
            body: $parsed['body'],
            dir: $skillDir,
            filename: self::SKILL_FILE,
            files: $this->collectFiles($skillDir),
            initialWarnings: $parsed['warnings'],
            source: $source,
        );
    }

    /**
     * Parse + validate one skill directory's SKILL.md, returning a single
     * result structure: either a populated Skill (via the inner `error`
     * key being null) or a Skill error placeholder. Owns the
     * parse → validate → warn ordering.
     *
     * @return array{
     *   error: ?Skill,
     *   frontmatter: array<string, mixed>,
     *   body: string,
     *   warnings: list<array{code: string, severity: string, message: string, path?: string}>
     * }
     */
    private function parseSkillFile(string $skillDir, string $source, SkillValidator $validator): array
    {
        $skillMd = $skillDir . '/' . self::SKILL_FILE;
        $contents = @file_get_contents($skillMd);
        if ($contents === false) {
            return $this->errorParseResult(
                $this->errorSkill($skillDir, $source, 'SKILL_MD_UNREADABLE', "Could not read '{$skillMd}'."),
            );
        }

        [$frontmatter, $body] = $this->parseFrontmatter($contents);
        if ($frontmatter === null) {
            $slug = basename($skillDir);
            return $this->errorParseResult(
                $this->errorSkill(
                    $skillDir,
                    $source,
                    'SKILL_FRONTMATTER_MISSING',
                    self::SKILL_FILE . " in '{$slug}' must start with a YAML frontmatter block delimited by '---'.",
                ),
            );
        }

        $result = $validator->validate($frontmatter, $body, basename($skillDir));
        $warnings = $result->isValid()
            ? $result->warnings()
            : array_merge($result->errors(), $result->warnings());

        return [
            'error'        => null,
            'frontmatter'  => $frontmatter,
            'body'         => $body,
            'warnings'     => $warnings,
        ];
    }

    /**
     * @return array{
     *   error: Skill,
     *   frontmatter: array<string, mixed>,
     *   body: string,
     *   warnings: list<array{code: string, severity: string, message: string, path?: string}>
     * }
     */
    private function errorParseResult(Skill $error): array
    {
        return [
            'error'       => $error,
            'frontmatter' => [],
            'body'        => '',
            'warnings'    => [],
        ];
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
        $bounds = $this->locateFrontmatterBounds($contents);
        if ($bounds === null) {
            return [null, ''];
        }

        [$yamlBlock, $body] = $bounds;
        $parsed = $this->parseYamlBlock($yamlBlock);
        if ($parsed === null) {
            return [null, $body];
        }
        /** @var array<string, mixed> $parsed */
        return [$parsed, $body];
    }

    /**
     * Find the [start, end) byte offsets of the YAML block delimited by
     * `---` on its own line at the top of the file and a closing `---`
     * line. Returns [yamlBlock, trimmedBody] on success, or null when
     * the file is missing one or both delimiters.
     *
     * @return array{0: string, 1: string}|null
     */
    private function locateFrontmatterBounds(string $contents): ?array
    {
        $contents = ltrim($contents, "\xEF\xBB\xBF");
        if (!str_starts_with($contents, '---')) {
            return null;
        }

        $body = $this->bodyAfterClosingDelimiter($contents);
        if ($body === null) {
            return null;
        }

        $bodyStart = strpos($contents, "\n---");
        $yamlBlock = substr($contents, 3, $bodyStart - 3);
        return [$yamlBlock, ltrim($body, "\n\r")];
    }

    /**
     * Extract everything after the closing `---` frontmatter line, or null
     * when the closing delimiter is missing.
     */
    private function bodyAfterClosingDelimiter(string $contents): ?string
    {
        $rest = substr($contents, 3);
        $newlinePos = strpos($rest, "\n");
        if ($newlinePos === false) {
            return null;
        }
        $afterFirstNewline = substr($rest, $newlinePos + 1);
        $closePos = strpos($afterFirstNewline, "\n---");
        if ($closePos === false) {
            return null;
        }

        return substr($afterFirstNewline, $closePos + 4);
    }

    /**
     * Parse the inner YAML block. Returns the assoc array on success,
     * or null on parse error / non-array result.
     *
     * @return array<string, mixed>|null
     */
    private function parseYamlBlock(string $yamlBlock): ?array
    {
        try {
            $parsed = Yaml::parse($yamlBlock);
        } catch (Throwable) {
            return null;
        }
        return is_array($parsed) ? $parsed : null;
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
            filename: self::SKILL_FILE,
            files: [],
            initialWarnings: [[
                'code'     => $code,
                'severity' => 'error',
                'message'  => $message,
                'path'     => basename($skillDir) . '/' . self::SKILL_FILE,
            ]],
            source: $source,
        );
    }
}
