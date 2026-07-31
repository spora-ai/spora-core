<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

/**
 * Validates the optional `metadata` block against the enum and type
 * rules documented in `agent-template.schema.json`.
 *
 * Extracted from {@see AgentTemplateValidator} so the validator stays under
 * the SonarCloud 20-method-per-class ceiling (S1448). All errors and
 * warnings are appended to the caller's {@see ValidationResult}.
 */
final class AgentTemplateMetadataValidator
{
    public const METADATA_ICON_TYPE = 'METADATA_ICON_TYPE';

    public const ALLOWED_CATEGORIES = [
        'general', 'productivity', 'research', 'communication', 'media', 'data', 'automation',
    ];

    public const ALLOWED_ARCHETYPES = [
        'assistant', 'researcher', 'analyst', 'writer', 'coder', 'explorer', 'advisor', 'creative',
    ];

    public const ALLOWED_PALETTES = [
        'slate', 'red', 'orange', 'amber', 'green', 'teal', 'blue', 'indigo', 'violet', 'pink',
    ];

    public const ALLOWED_VARIANTS = ['v0', 'v1', 'v2'];

    public const ALLOWED_KEYS = ['category', 'icon', 'archetype', 'variant_key', 'palette_key'];

    /**
     * @param array<string, mixed> $raw
     */
    public function validate(array $raw, ValidationResult $result): void
    {
        if (!array_key_exists('metadata', $raw)) {
            return;
        }
        $metadata = $raw['metadata'];
        if (!is_array($metadata)) {
            $result->addError([
                'code'     => 'METADATA_NOT_OBJECT',
                'severity' => 'error',
                'message'  => "Field 'metadata' must be an object.",
                'path'     => 'metadata',
            ]);
            return;
        }
        foreach (array_keys($metadata) as $key) {
            if (in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }
            $result->addError([
                'code'     => 'UNKNOWN_METADATA_KEY',
                'severity' => 'error',
                'message'  => sprintf("Unknown field 'metadata.%s'.", $key),
                'path'     => 'metadata.' . $key,
            ]);
        }
        if (isset($metadata['category'])) {
            $this->validateEnum(
                $result,
                'category',
                $metadata['category'],
                self::ALLOWED_CATEGORIES,
                'METADATA_CATEGORY_UNKNOWN',
            );
        }
        $this->validateStringField($result, 'icon', $metadata['icon'] ?? null, self::METADATA_ICON_TYPE);
        $this->validateEnumWithType(
            $result,
            'archetype',
            $metadata['archetype'] ?? null,
            self::ALLOWED_ARCHETYPES,
            'METADATA_ARCHETYPE_TYPE',
            'METADATA_ARCHETYPE_UNKNOWN',
        );
        $this->validateEnumWithType(
            $result,
            'variant_key',
            $metadata['variant_key'] ?? null,
            self::ALLOWED_VARIANTS,
            'METADATA_VARIANT_KEY_TYPE',
            'METADATA_VARIANT_KEY_UNKNOWN',
        );
        $this->validateEnumWithType(
            $result,
            'palette_key',
            $metadata['palette_key'] ?? null,
            self::ALLOWED_PALETTES,
            'METADATA_PALETTE_KEY_TYPE',
            'METADATA_PALETTE_KEY_UNKNOWN',
        );
    }

    private function validateStringField(ValidationResult $result, string $field, mixed $value, string $errorCode): void
    {
        if ($value === null) {
            return;
        }
        if (!is_string($value)) {
            $result->addError([
                'code'     => $errorCode,
                'severity' => 'error',
                'message'  => "Field 'metadata.{$field}' must be a string.",
                'path'     => 'metadata.' . $field,
            ]);
        }
    }

    /**
     * @param list<string> $allowed
     * @param mixed $value
     */
    private function validateEnum(
        ValidationResult $result,
        string $field,
        mixed $value,
        array $allowed,
        string $unknownCode,
    ): void {
        if (!in_array($value, $allowed, true)) {
            $result->addWarning([
                'code'     => $unknownCode,
                'severity' => 'warning',
                'message'  => sprintf(
                    "Unknown %s '%s'. Expected one of: %s.",
                    $field,
                    (string) $value,
                    implode(', ', $allowed),
                ),
                'path'     => 'metadata.' . $field,
            ]);
        }
    }

    /**
     * @param list<string> $allowed
     * @param mixed $value
     */
    private function validateEnumWithType(
        ValidationResult $result,
        string $field,
        mixed $value,
        array $allowed,
        string $typeErrorCode,
        string $unknownCode,
    ): void {
        if ($value === null) {
            return;
        }
        if (!is_string($value)) {
            $this->addStringTypeError($result, $field, $typeErrorCode);
            return;
        }
        $this->validateEnum($result, $field, $value, $allowed, $unknownCode);
    }

    private function addStringTypeError(ValidationResult $result, string $field, string $code): void
    {
        $result->addError([
            'code'     => $code,
            'severity' => 'error',
            'message'  => "Field 'metadata.{$field}' must be a string.",
            'path'     => 'metadata.' . $field,
        ]);
    }
}
