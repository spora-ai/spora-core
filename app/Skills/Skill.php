<?php

declare(strict_types=1);

namespace Spora\Skills;

use Spora\Skills\Exceptions\SkillNotFoundException;

/**
 * A parsed, validated Skill.
 *
 * Carries the raw frontmatter as a typed value object, the SKILL.md body
 * (with frontmatter stripped), the recursive file listing under the skill
 * directory, and any warnings the scanner or validator produced. Designed
 * so importers, the HTTP controller, and the Skill tool can read typed
 * accessors without re-parsing.
 *
 * `source` distinguishes bundled skills (`core`), project-shipped
 * (`project`), and plugin-shipped (`<plugin-slug>`). `dir` is the
 * absolute path to the skill's directory on disk — the Skill tool uses it
 * to resolve `skill_read` and `skill_files` calls.
 */
final class Skill
{
    /** @var list<array{code: string, severity: string, message: string, path?: string}> */
    private array $warnings = [];

    /** @var list<array{path: string, bytes: int}> */
    private readonly array $files;

    /**
     * @param array<string, mixed>       $frontmatter  Parsed YAML frontmatter block.
     * @param string                     $body         SKILL.md body with frontmatter stripped.
     * @param string                     $dir          Absolute path to the skill directory.
     * @param string                     $filename     Filename of the entry file, typically 'SKILL.md'.
     * @param list<array{path: string, bytes: int}> $files  Recursive file listing under the skill directory.
     * @param list<array{code: string, severity: string, message: string, path?: string}> $initialWarnings
     */
    public function __construct(
        private readonly array $frontmatter,
        private readonly string $body,
        private readonly string $dir,
        private readonly string $filename = 'SKILL.md',
        array $files = [],
        array $initialWarnings = [],
        private readonly ?string $source = null,
    ) {
        $this->files = $files;
        $this->warnings = $initialWarnings;
    }

    public function name(): string
    {
        return (string) ($this->frontmatter['name'] ?? '');
    }

    public function description(): string
    {
        return (string) ($this->frontmatter['description'] ?? '');
    }

    public function license(): ?string
    {
        $value = $this->frontmatter['license'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function compatibility(): ?string
    {
        $value = $this->frontmatter['compatibility'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    public function metadata(): array
    {
        $value = $this->frontmatter['metadata'] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function allowedTools(): ?string
    {
        $value = $this->frontmatter['allowed-tools'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function bodyBytes(): int
    {
        return strlen($this->body);
    }

    /**
     * @return list<array{path: string, bytes: int}>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * @return list<array{code: string, severity: string, message: string, path?: string}>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array{code: string, severity: string, message: string, path?: string} $entry
     */
    public function addWarning(array $entry): void
    {
        $this->warnings[] = $entry;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function frontmatter(): array
    {
        return $this->frontmatter;
    }

    /**
     * Resolve a relative file path inside the skill's directory. Returns the
     * absolute filesystem path on success; raises {@see SkillNotFoundException}
     * when the path is not present in the scanned file listing.
     *
     * The caller is responsible for path-traversal hardening (see SkillTool).
     * This method only normalises `path/to/file.md` to the absolute form
     * `${dir}/path/to/file.md` against the cached listing.
     */
    public function resolveFilePath(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '') {
            $relativePath = $this->filename;
        }
        foreach ($this->files as $entry) {
            if ($entry['path'] === $relativePath) {
                return rtrim($this->dir, '/') . '/' . $relativePath;
            }
        }
        throw new SkillNotFoundException(
            "File '{$relativePath}' is not part of skill '{$this->name()}'.",
        );
    }
}
