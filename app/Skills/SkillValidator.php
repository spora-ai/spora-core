<?php

declare(strict_types=1);

namespace Spora\Skills;

/**
 * Validates a parsed skill frontmatter block.
 *
 * Validation is manual (no JSON Schema library) to match the
 * {@see \Spora\AgentTemplates\AgentTemplateValidator} convention and keep
 * the runtime dependency surface small. The companion `skill.schema.json`
 * at the framework root mirrors these rules for editor / tooling support.
 *
 * Errors mean the skill cannot be used; warnings are advisory and surface
 * on the skill's summary (oversize body, unknown optional field, etc.).
 *
 * The validator never reads the filesystem — it works on the in-memory
 * frontmatter array. The scanner calls it after parsing the YAML block.
 */
final class SkillValidator
{
    /**
     * 1-64 chars, lowercase alphanumeric + hyphens, no leading/trailing
     * hyphen, no consecutive hyphens. Mirrors `skill.schema.json#name`.
     * The negative lookahead `(?![a-z0-9-]*--)` rejects any two
     * consecutive hyphens anywhere in the slug.
     */
    private const NAME_PATTERN = '/^(?![a-z0-9-]*--)[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/';
    private const CONSECUTIVE_HYPHEN_PATTERN = '/--/';

    private const DESCRIPTION_MAX_LENGTH = 1024;
    private const COMPATIBILITY_MAX_LENGTH = 500;
    private const BODY_SOFT_LINE_LIMIT = 500;
    private const BODY_SOFT_BYTE_LIMIT = 50_000;

    private const ALLOWED_TOP_KEYS = [
        '$schema', 'name', 'description', 'license', 'compatibility',
        'metadata', 'allowed-tools',
    ];

    /**
     * @param array<string, mixed> $frontmatter
     */
    public function validate(array $frontmatter, ?string $body = null, ?string $parentDirName = null): ValidationResult
    {
        $result = new ValidationResult();

        if ($frontmatter === []) {
            $result->addError([
                'code'     => 'EMPTY_FRONTMATTER',
                'severity' => 'error',
                'message'  => 'SKILL.md frontmatter is empty.',
            ]);
            return $result;
        }

        $this->validateTopLevelKeys($frontmatter, $result);
        $this->validateName($frontmatter, $parentDirName, $result);
        $this->validateDescription($frontmatter, $result);
        $this->validateLicense($frontmatter, $result);
        $this->validateCompatibility($frontmatter, $result);
        $this->validateMetadata($frontmatter, $result);
        $this->validateAllowedTools($frontmatter, $result);
        $this->validateBody($body, $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateTopLevelKeys(array $frontmatter, ValidationResult $result): void
    {
        foreach (array_keys($frontmatter) as $key) {
            if (in_array($key, self::ALLOWED_TOP_KEYS, true)) {
                continue;
            }
            $result->addError([
                'code'     => 'UNKNOWN_TOP_LEVEL_KEY',
                'severity' => 'error',
                'message'  => sprintf("Unknown frontmatter field '%s'.", $key),
                'path'     => $key,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateName(array $frontmatter, ?string $parentDirName, ValidationResult $result): void
    {
        if (!array_key_exists('name', $frontmatter)) {
            $result->addError([
                'code'     => 'NAME_REQUIRED',
                'severity' => 'error',
                'message'  => "Field 'name' is required.",
                'path'     => 'name',
            ]);
            return;
        }

        $name = $frontmatter['name'];
        if (!is_string($name) || $name === '') {
            $result->addError([
                'code'     => 'NAME_INVALID',
                'severity' => 'error',
                'message'  => "Field 'name' must be a non-empty string.",
                'path'     => 'name',
            ]);
            return;
        }

        if (preg_match(self::CONSECUTIVE_HYPHEN_PATTERN, $name) === 1) {
            $result->addError([
                'code'     => 'NAME_CONSECUTIVE_HYPHEN',
                'severity' => 'error',
                'message'  => "Field 'name' must not contain consecutive hyphens.",
                'path'     => 'name',
            ]);
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $result->addError([
                'code'     => 'NAME_PATTERN',
                'severity' => 'error',
                'message'  => "Field 'name' does not match the required pattern (lowercase alnum + hyphens, no leading/trailing hyphen, 1-64 chars).",
                'path'     => 'name',
            ]);
        }

        if ($parentDirName !== null && $parentDirName !== '' && $name !== $parentDirName) {
            $result->addError([
                'code'     => 'NAME_DIR_MISMATCH',
                'severity' => 'error',
                'message'  => sprintf(
                    "Skill 'name' ('%s') must equal the parent directory name ('%s').",
                    $name,
                    $parentDirName,
                ),
                'path'     => 'name',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateDescription(array $frontmatter, ValidationResult $result): void
    {
        if (!array_key_exists('description', $frontmatter)) {
            $result->addError([
                'code'     => 'DESCRIPTION_REQUIRED',
                'severity' => 'error',
                'message'  => "Field 'description' is required.",
                'path'     => 'description',
            ]);
            return;
        }

        $description = $frontmatter['description'];
        if (!is_string($description) || $description === '') {
            $result->addError([
                'code'     => 'DESCRIPTION_INVALID',
                'severity' => 'error',
                'message'  => "Field 'description' must be a non-empty string.",
                'path'     => 'description',
            ]);
            return;
        }

        if (strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            $result->addError([
                'code'     => 'DESCRIPTION_TOO_LONG',
                'severity' => 'error',
                'message'  => sprintf(
                    "Field 'description' is %d characters; max is %d.",
                    strlen($description),
                    self::DESCRIPTION_MAX_LENGTH,
                ),
                'path'     => 'description',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateLicense(array $frontmatter, ValidationResult $result): void
    {
        if (!array_key_exists('license', $frontmatter)) {
            return;
        }
        $value = $frontmatter['license'];
        if (!is_string($value)) {
            $result->addError([
                'code'     => 'LICENSE_INVALID',
                'severity' => 'error',
                'message'  => "Field 'license' must be a string.",
                'path'     => 'license',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateCompatibility(array $frontmatter, ValidationResult $result): void
    {
        if (!array_key_exists('compatibility', $frontmatter)) {
            return;
        }
        $value = $frontmatter['compatibility'];
        if (!is_string($value)) {
            $result->addError([
                'code'     => 'COMPATIBILITY_INVALID',
                'severity' => 'error',
                'message'  => "Field 'compatibility' must be a string.",
                'path'     => 'compatibility',
            ]);
            return;
        }
        if (strlen($value) > self::COMPATIBILITY_MAX_LENGTH) {
            $result->addError([
                'code'     => 'COMPATIBILITY_TOO_LONG',
                'severity' => 'error',
                'message'  => sprintf(
                    "Field 'compatibility' is %d characters; max is %d.",
                    strlen($value),
                    self::COMPATIBILITY_MAX_LENGTH,
                ),
                'path'     => 'compatibility',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateMetadata(array $frontmatter, ValidationResult $result): void
    {
        if (!array_key_exists('metadata', $frontmatter)) {
            return;
        }
        $metadata = $frontmatter['metadata'];
        if (!is_array($metadata)) {
            $result->addError([
                'code'     => 'METADATA_INVALID',
                'severity' => 'error',
                'message'  => "Field 'metadata' must be an object of string keys to string values.",
                'path'     => 'metadata',
            ]);
            return;
        }
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                $result->addError([
                    'code'     => 'METADATA_VALUE_INVALID',
                    'severity' => 'error',
                    'message'  => "Field 'metadata' must be an object of string keys to string values.",
                    'path'     => 'metadata',
                ]);
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateAllowedTools(array $frontmatter, ValidationResult $result): void
    {
        if (!array_key_exists('allowed-tools', $frontmatter)) {
            return;
        }
        $value = $frontmatter['allowed-tools'];
        if (!is_string($value)) {
            $result->addError([
                'code'     => 'ALLOWED_TOOLS_INVALID',
                'severity' => 'error',
                'message'  => "Field 'allowed-tools' must be a space-separated string.",
                'path'     => 'allowed-tools',
            ]);
        }
    }

    private function validateBody(?string $body, ValidationResult $result): void
    {
        if ($body === null) {
            return;
        }
        $lineCount = substr_count($body, "\n") + 1;
        if ($lineCount > self::BODY_SOFT_LINE_LIMIT || strlen($body) > self::BODY_SOFT_BYTE_LIMIT) {
            $result->addWarning([
                'code'     => 'SKILL_BODY_OVERSIZE',
                'severity' => 'warning',
                'message'  => sprintf(
                    "SKILL.md body is %d lines / %d bytes; the agentskills.io spec recommends ≤ %d lines (soft). Move detailed content to references/ files.",
                    $lineCount,
                    strlen($body),
                    self::BODY_SOFT_LINE_LIMIT,
                ),
                'path'     => 'body',
            ]);
        }
    }
}
