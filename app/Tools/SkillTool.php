<?php

declare(strict_types=1);

namespace Spora\Tools;

use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Lets the LLM list the files in a skill, or read one of its files.
 *
 * **Read-only by design.** Which skills an agent may load is the *agent's*
 * configuration, not a property of the tool that reads them, so the write
 * lives on the AgentTool — `configure_tools` carries a `settings` block, and
 * `get_available_tools` carries the matching `skills` block for discovery.
 * A tool that could both read a skill and grant itself one would put the
 * second half of the permission decision inside the first.
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
 * **Two gates, in this order, and the order is the point.** `name` must be in the
 * agent's `allowed_skills`, *and* visible to the execution's principal. A skill
 * allowlisted on a group agent whose provider scopes by principal fails the
 * second gate, which is what keeps one tenant's `allowed_skills` from becoming a
 * cross-tenant read primitive.
 *
 * `filename` is checked against the provider's own listing before the read, and
 * the size cap is re-asserted on what comes back. Both are deliberate: the
 * provider is plugin-supplied code, and a check the caller cannot enforce on the
 * callee is not a check. {@see SkillToolProviderTest} pins the case with a
 * provider that answers for a path it does not list.
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
    // 'skill' stores string[] slugs and resolves them via the skill providers to
    // "name: short description" pairs for the LLM-facing projection.
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
    private const SKILL_ENTRY_FILE = 'SKILL.md';

    public function __construct(
        private readonly SkillProviderRegistry $skills,
        private readonly ToolConfigServiceInterface $config,
        private readonly PrincipalResolver $principals,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $userId = $context?->ownerUserId;
        $operation = $this->getOperationName($arguments);
        $name = strtolower(trim((string) ($arguments['name'] ?? '')));

        $authError = $this->authorizationErrorFor($name, $agentId, $userId, $context);
        if ($authError !== null) {
            return $authError;
        }

        $principalId = $this->resolvePrincipalId($agentId, $context);

        $files = $this->skills->getSkillFiles($name, $principalId);
        if ($files === null) {
            // "Not available" rather than "not on disk": a provider need not be
            // backed by a filesystem, and telling the model otherwise teaches it
            // to retry a path that does not exist.
            return new ToolResult(false, "Skill '{$name}' is not available.");
        }

        return match ($operation) {
            'read'  => $this->doRead($name, $files, $principalId, $arguments),
            'files' => $this->doFiles($name, $files),
            default => new ToolResult(false, "Unknown operation '{$operation}'."),
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
     * The principal whose skills this call may see.
     *
     * The execution's context, else the agent's own principal. **`$userId` is
     * never used** — it is the runner, not the owner, so a group agent triggered
     * by one member would otherwise resolve against that member's personal
     * skills. The resolver fallback is what keeps a scheduled run working: there
     * is no runner, but there is an agent. An unresolvable principal becomes
     * `null` so a provider fails closed.
     */
    private function resolvePrincipalId(int $agentId, ?PrincipalContext $context): ?int
    {
        $resolved = $context ?? $this->principals->resolveForToolExecute($agentId);

        return $resolved->isResolvable() ? $resolved->principalId : null;
    }

    /**
     * @param list<array{path: string, bytes: int}> $files
     * @return array{0: string, 1: string}|ToolResult
     */
    private function resolveReadableFile(
        string $name,
        array $files,
        ?int $principalId,
        string $filename,
    ): array|ToolResult {
        $sanitized = $this->sanitizeRelativePath($filename);
        if ($sanitized === null) {
            return new ToolResult(
                false,
                "Invalid filename '{$filename}'. Paths must be relative, must not contain '..' or null bytes, and must be present in the skill's file listing.",
            );
        }

        $listed = $this->listedBytes($files, $sanitized);
        if ($listed === null) {
            return new ToolResult(false, "File '{$sanitized}' is not part of skill '{$name}'.");
        }

        $contents = $this->skills->getSkillFile($name, $sanitized, $principalId);
        $rejection = $this->readRejection($sanitized, $listed, $contents);

        return $rejection ?? [$sanitized, (string) $contents];
    }

    /**
     * The advertised size of `$path`, or null when the provider does not list
     * it. The listing is the caller's own answer to "is this a member?", taken
     * before any read is attempted.
     *
     * @param list<array{path: string, bytes: int}> $files
     */
    private function listedBytes(array $files, string $path): ?int
    {
        foreach ($files as $entry) {
            if ($entry['path'] === $path) {
                return $entry['bytes'];
            }
        }

        return null;
    }

    /**
     * The failure a completed read can still warrant, or null when the content
     * is usable. Two distinctions matter to the model, and conflating either
     * sends it looking for the wrong thing:
     *
     * - A null read of a *listed* path is not "not part of skill" — the listing
     *   is the caller's own proof it is a member, and the advertised size is
     *   what separates "too big" from "unreadable".
     * - Content arriving over the cap is re-checked here. The provider is
     *   required to enforce it, but this is the boundary where untrusted content
     *   enters the model's context and a provider bug must not widen it.
     */
    private function readRejection(string $path, int $listedBytes, ?string $contents): ?ToolResult
    {
        $bytes = $contents === null ? $listedBytes : strlen($contents);

        if ($contents === null && $bytes <= SkillProviderInterface::MAX_FILE_BYTES) {
            return new ToolResult(false, "Could not read '{$path}'.");
        }

        if ($contents === null || $bytes > SkillProviderInterface::MAX_FILE_BYTES) {
            return new ToolResult(
                false,
                "File '{$path}' is {$bytes} bytes; skill reads are capped at "
                    . SkillProviderInterface::MAX_FILE_BYTES . ' bytes.',
            );
        }

        return null;
    }

    /**
     * @param list<array{path: string, bytes: int}> $files
     */
    private function doRead(string $name, array $files, ?int $principalId, array $arguments): ToolResult
    {
        $resolved = $this->resolveReadableFile(
            $name,
            $files,
            $principalId,
            (string) ($arguments['filename'] ?? self::SKILL_ENTRY_FILE),
        );
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
                'name'     => $name,
                'filename' => $sanitized,
                'bytes'    => strlen($contents),
            ],
        );
    }

    /**
     * @param list<array{path: string, bytes: int}> $files
     */
    private function doFiles(string $name, array $files): ToolResult
    {
        if ($files === []) {
            return new ToolResult(
                true,
                "Skill '{$name}' has no files listed.",
                ['name' => $name, 'files' => []],
            );
        }

        $lines = ["Files in skill '{$name}':"];
        foreach ($files as $entry) {
            $lines[] = sprintf('  - %s (%d bytes)', $entry['path'], $entry['bytes']);
        }

        return new ToolResult(
            true,
            implode("\n", $lines),
            [
                'name'  => $name,
                'files' => $files,
            ],
        );
    }

    private function authorizationErrorFor(string $name, int $agentId, ?int $userId, ?PrincipalContext $context): ?ToolResult
    {
        if ($name === '') {
            return new ToolResult(false, 'name is required.');
        }
        if (!$this->isSkillAllowed($name, $agentId, $userId, $context)) {
            return new ToolResult(false, "Skill '{$name}' is not in the allowed_skills list for this agent.");
        }
        return null;
    }

    /**
     * Read the allowlist through the execution's context.
     *
     * `$context` is forwarded for the same reason gate 2 uses it: the setting
     * being read is the *agent's*, so it has to be resolved against the agent's
     * principal. Omitting it lets the cascade fall back to the runner's
     * principals, which is a different set — a group agent's group-level
     * `allowed_skills` is then invisible and a legitimately configured skill is
     * refused, and a scheduled run with no runner resolves nothing at all.
     */
    private function isSkillAllowed(string $name, int $agentId, ?int $userId, ?PrincipalContext $context): bool
    {
        $settings = $this->config->getEffectiveSettings(self::class, $agentId, $userId, $context);
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

    /**
     * Reject path-traversal attempts and other unsafe inputs. Returns
     * the sanitised path on success or null on rejection.
     *
     * - No leading slash (must be relative to the skill root).
     * - No null bytes.
     * - No `..` segments (the provider re-validates containment
     *   independently — this filter is the cheap first pass, not the defence).
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
