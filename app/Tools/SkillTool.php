<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\Skill;
use Spora\Skills\SkillScanner;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Lets the LLM list the files in a skill, or read one of its files.
 *
 * Per-agent allowlist is the `allowed_skills` multi-select
 * (`exposeToLlm: true`, `resolveAs: 'skill'` — resolved to
 * "name: short description" pairs via {@see \Spora\Services\ToolConfigSchemaInspector}).
 *
 * Operations (selected via the `action` discriminator):
 *   - 'read'  — return the body of one file (default `SKILL.md`); for
 *               `SKILL.md` the frontmatter is stripped (the LLM has
 *               already seen the name + description at tool-definition
 *               time via the agent's `allowed_skills` summary).
 *   - 'files' — return the recursive file listing as
 *               `[{path, bytes}]`.
 *
 * Security: the LLM's choice of `name` and `filename` is re-validated
 * server-side — `name` must be in the agent's `allowed_skills`; the
 * `filename` is path-traversal-hardened before any FS read.
 */
#[Tool(
    name: 'skill',
    displayName: 'Skill',
    category: 'agent',
    icon: 'puzzle',
    description: 'List the files in a skill, or read one of its files (default SKILL.md). '
               . 'Use when a task matches one of the allowed skills listed in the effective configuration.',
)]
#[ToolSetting(
    key: 'allowed_skills',
    label: 'Allowed skills',
    type: 'multi-select',
    description: 'Skills the agent may load. The LLM sees the name and short description of each in the tool definition.',
    required: true,
    // 'skill' stores string[] slugs and resolves them via the SkillScanner
    // to "name: short description" pairs for the LLM-facing projection.
    // Path is relative to /api/v1 (the api client prepends it); an absolute
    // path here would double up to `/api/v1/api/v1/skills` and 404.
    resolveAs: 'skill',
    dataSource: '/skills?select=name,description',
    exposeToLlm: true,
)]
#[ToolOperation(
    name: 'read',
    description: 'Read a single file from a skill (default SKILL.md).',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'name',
    type: 'string',
    description: 'Skill slug. Must be in the configured allowed_skills list.',
    required: true,
)]
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Relative path inside the skill. Defaults to SKILL.md. Only used when action is "read".',
    required: false,
    default: 'SKILL.md',
)]
#[ToolOperation(
    name: 'files',
    description: 'List the files available inside a skill.',
    enabledByDefault: true,
    requiresApprovalByDefault: false,
)]
#[ToolParameter(
    name: 'name',
    type: 'string',
    description: 'Skill slug. Must be in the configured allowed_skills list.',
    required: true,
)]
final class SkillTool extends AbstractTool
{
    private const FILE_SIZE_HARD_LIMIT = 50_000;
    private const SKILL_ENTRY_FILE = 'SKILL.md';

    public function __construct(
        private readonly SkillScanner $scanner,
        private readonly ToolConfigServiceInterface $config,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $auth = $this->resolveAndAuthorize($arguments, $agentId, $userId);
        if ($auth instanceof ToolResult) {
            return $auth;
        }
        $skill = $auth;

        return match ($this->getOperationName($arguments)) {
            'read'  => $this->doRead($skill, $arguments),
            'files' => $this->doFiles($skill),
            default => new ToolResult(false, "Unknown operation '{$this->getOperationName($arguments)}'."),
        };
    }

    public function describeAction(array $arguments): string
    {
        $name = (string) ($arguments['name'] ?? '?');
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'read'  => "Read a file from skill '{$name}'.",
            'files' => "List the files in skill '{$name}'.",
            default => "Use the skill tool on '{$name}'.",
        };
    }

    /**
     * Validate `name` (non-empty, in allowed_skills, on disk) and return
     * the resolved Skill, or a failure ToolResult.
     *
     * @param array<string, mixed> $arguments
     */
    private function resolveAndAuthorize(array $arguments, int $agentId, ?int $userId): Skill|ToolResult
    {
        $name = strtolower(trim((string) ($arguments['name'] ?? '')));
        $authError = $this->authorizationErrorFor($name, $agentId, $userId);
        if ($authError !== null) {
            return $authError;
        }

        $skill = $this->findSkill($name);
        return $skill ?? new ToolResult(false, "Skill '{$name}' is not currently available on disk.");
    }

    private function authorizationErrorFor(string $name, int $agentId, ?int $userId): ?ToolResult
    {
        if ($name === '') {
            return new ToolResult(false, 'name is required.');
        }
        if (!$this->isSkillAllowed($name, $agentId, $userId)) {
            return new ToolResult(false, "Skill '{$name}' is not in the allowed_skills list for this agent.");
        }
        return null;
    }

    private function isSkillAllowed(string $name, int $agentId, ?int $userId): bool
    {
        $settings = $this->config->getEffectiveSettings(self::class, $agentId, $userId);
        $allowed  = $settings['allowed_skills'] ?? [];

        if (!is_array($allowed)) {
            return false;
        }
        foreach ($allowed as $candidate) {
            if (is_string($candidate) && strtolower(trim($candidate)) === $name) {
                return true;
            }
        }
        return false;
    }

    private function findSkill(string $name): ?Skill
    {
        foreach ($this->scanner->scan() as $skill) {
            if ($skill->name() === $name) {
                return $skill;
            }
        }
        return null;
    }

    private function doRead(Skill $skill, array $arguments): ToolResult
    {
        $resolved = $this->resolveReadableFile($skill, (string) ($arguments['filename'] ?? self::SKILL_ENTRY_FILE));
        if ($resolved instanceof ToolResult) {
            return $resolved;
        }
        [$sanitized, $contents] = $resolved;

        // SKILL.md frontmatter is stripped — the LLM already saw
        // name+description in the tool definition (Stage 1).
        $body = $sanitized === self::SKILL_ENTRY_FILE
            ? $this->stripFrontmatter($contents)
            : $contents;

        return new ToolResult(
            true,
            $body,
            [
                'name'     => $skill->name(),
                'filename' => $sanitized,
                'bytes'    => strlen($contents),
            ],
        );
    }

    /**
     * Locate, sanitise, contain, stat-size-cap, and read the requested
     * file. Returns a [sanitised, contents] tuple on success or a
     * failure ToolResult.
     *
     * @return array{0: string, 1: string}|ToolResult
     */
    private function resolveReadableFile(Skill $skill, string $filename): array|ToolResult
    {
        $sanitized = $this->sanitizeRelativePath($filename);
        if ($sanitized === null) {
            return new ToolResult(
                false,
                "Invalid filename '{$filename}'. Paths must be relative, must not contain '..' or null bytes, and must be present in the skill's file listing.",
            );
        }

        $real = $this->resolveAndValidatePath($skill, $sanitized);
        if ($real instanceof ToolResult) {
            return $real;
        }

        $contents = $this->readContentsAt($real, $sanitized);
        return $contents instanceof ToolResult ? $contents : [$sanitized, $contents];
    }

    /**
     * Resolve the skill-relative path to its realpath on disk, or return
     * a failure ToolResult. Defense-in-depth containment check (realpath
     * inside the skill dir) lives here.
     */
    private function resolveAndValidatePath(Skill $skill, string $sanitized): string|ToolResult
    {
        try {
            $absolute = $skill->resolveFilePath($sanitized);
        } catch (\Spora\Skills\Exceptions\SkillNotFoundException) {
            return new ToolResult(false, "File '{$sanitized}' is not part of skill '{$skill->name()}'.");
        }

        $real = realpath($absolute);
        $rootReal = realpath($skill->dir());
        if ($real === false || $rootReal === false || !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
            return new ToolResult(false, "File '{$sanitized}' is not part of skill '{$skill->name()}'.");
        }

        return $real;
    }

    private function readContentsAt(string $real, string $sanitized): string|ToolResult
    {
        $sizeError = $this->sizeCapErrorFor($real, $sanitized);
        if ($sizeError !== null) {
            return $sizeError;
        }

        $contents = @file_get_contents($real);
        return $contents === false
            ? new ToolResult(false, "Could not read '{$sanitized}'.")
            : $contents;
    }

    private function sizeCapErrorFor(string $real, string $sanitized): ?ToolResult
    {
        $size = @filesize($real);
        if ($size === false) {
            return new ToolResult(false, "Could not stat '{$sanitized}'.");
        }
        if ($size > self::FILE_SIZE_HARD_LIMIT) {
            return new ToolResult(
                false,
                "File '{$sanitized}' is {$size} bytes; skill_read is capped at " . self::FILE_SIZE_HARD_LIMIT . ' bytes.',
            );
        }
        return null;
    }

    private function doFiles(Skill $skill): ToolResult
    {
        $files = $skill->files();
        if ($files === []) {
            return new ToolResult(
                true,
                "Skill '{$skill->name()}' has no files listed.",
                ['name' => $skill->name(), 'files' => []],
            );
        }

        $lines = ["Files in skill '{$skill->name()}':"];
        foreach ($files as $entry) {
            $lines[] = sprintf('  - %s (%d bytes)', $entry['path'], $entry['bytes']);
        }

        return new ToolResult(
            true,
            implode("\n", $lines),
            [
                'name'  => $skill->name(),
                'files' => $files,
            ],
        );
    }

    /**
     * Reject path-traversal attempts and other unsafe inputs. Returns
     * the sanitised path on success or null on rejection.
     *
     * - No leading slash (must be relative to the skill root).
     * - No null bytes.
     * - No `..` segments (the resolved realpath check is the final
     *   defence, but we filter obvious attempts here too).
     */
    private function sanitizeRelativePath(string $path): ?string
    {
        if ($this->isUnsafeRelativePath($path)) {
            return null;
        }
        $segments = preg_split('#[/\\\\]+#', $path) ?: [];
        foreach ($segments as $seg) {
            if ($seg === '..' || $seg === '.') {
                return null;
            }
        }
        return implode('/', $segments);
    }

    /**
     * Cheap pre-filter for {@see sanitizeRelativePath()}: empty input,
     * embedded null bytes, and leading slashes are the most common
     * attacks; checking them up-front lets the segment-walk below stay
     * focused on traversal.
     */
    private function isUnsafeRelativePath(string $path): bool
    {
        return $path === '' || str_contains($path, "\0") || str_starts_with($path, '/');
    }

    /**
     * Strip the YAML frontmatter from a SKILL.md body, returning just
     * the Markdown content the LLM should consume.
     */
    private function stripFrontmatter(string $contents): string
    {
        $body = $this->findFrontmatterBody($contents);
        return $body ?? $contents;
    }

    /**
     * Locate the body text after the closing frontmatter `---` line.
     * Returns null when the file is missing one or both delimiters, in
     * which case {@see stripFrontmatter()} falls back to the original
     * contents verbatim.
     */
    private function findFrontmatterBody(string $contents): ?string
    {
        $contents = ltrim($contents, "\xEF\xBB\xBF");
        if (!str_starts_with($contents, '---')) {
            return null;
        }

        $body = $this->bodyAfterClosingDelimiter($contents);
        return $body === null ? null : ltrim($body, "\n\r");
    }

    /**
     * Everything after the closing `---` line, or null when the closing
     * delimiter is missing.
     */
    private function bodyAfterClosingDelimiter(string $contents): ?string
    {
        $rest = substr($contents, 3);
        $newlinePos = strpos($rest, "\n");
        if ($newlinePos === false) {
            return null;
        }
        $afterFirst = substr($rest, $newlinePos + 1);
        $closePos = strpos($afterFirst, "\n---");
        if ($closePos === false) {
            return null;
        }

        return substr($afterFirst, $closePos + 4);
    }
}
