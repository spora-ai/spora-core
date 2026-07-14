<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

/**
 * Outcome of running {@see AgentTemplateValidator} against a parsed payload.
 *
 * Carries two parallel arrays — `errors` (the template cannot be applied)
 * and `warnings` (the template can be applied, but with caveats surfaced
 * to the operator). Each entry is a free-form assoc with at minimum
 * `code`, `severity`, `message`. Optional `path` (JSON-pointer-ish) lets
 * the UI highlight the offending field.
 *
 * The class itself is mutable only via constructor and named methods so
 * it can be passed around safely across the scanner → controller → UI.
 */
final class ValidationResult
{
    /**
     * @param list<array{code: string, severity: string, message: string, path?: string}> $errors
     * @param list<array{code: string, severity: string, message: string, path?: string}> $warnings
     */
    public function __construct(
        private array $errors = [],
        private array $warnings = [],
    ) {}

    /**
     * @param array{code: string, severity: string, message: string, path?: string} $entry
     */
    public function addError(array $entry): void
    {
        $this->errors[] = $entry;
    }

    /**
     * @param array{code: string, severity: string, message: string, path?: string} $entry
     */
    public function addWarning(array $entry): void
    {
        $this->warnings[] = $entry;
    }

    /**
     * @return list<array{code: string, severity: string, message: string, path?: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<array{code: string, severity: string, message: string, path?: string}>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array{valid: bool, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'valid'    => $this->isValid(),
            'errors'   => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
