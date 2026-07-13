<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

/**
 * A parsed, validated Agent Template.
 *
 * Carries the full raw assoc-array plus a flat set of warning entries
 * produced by the scanner (parse errors) and the validator (semantic
 * warnings). Designed so importers and exporters can read typed
 * accessors without re-parsing the JSON.
 *
 * `source` distinguishes bundled templates (`core`), plugin-shipped
 * (`<plugin-slug>`), and operator-uploaded (`uploaded`). `filename`
 * is the originating file basename for scanner-sourced entries; null
 * for parsed-from-request bodies.
 */
final class AgentTemplate
{
    /** @var list<array{code: string, severity: string, message: string, path?: string}> */
    private array $warnings = [];

    /**
     * @param array<string, mixed> $raw
     * @param list<array{code: string, severity: string, message: string, path?: string}> $initialWarnings
     */
    public function __construct(
        private readonly array $raw,
        array $initialWarnings = [],
        private readonly ?string $source = null,
        private readonly ?string $filename = null,
    ) {
        $this->warnings = $initialWarnings;
    }

    public function id(): string
    {
        return (string) ($this->raw['id'] ?? '');
    }

    public function name(): string
    {
        return (string) ($this->raw['name'] ?? '');
    }

    public function description(): ?string
    {
        $value = $this->raw['description'] ?? null;
        return is_string($value) ? $value : null;
    }

    public function version(): string
    {
        return (string) ($this->raw['version'] ?? '1.0.0');
    }

    /**
     * @return array<string, mixed>
     */
    public function agent(): array
    {
        $value = $this->raw['agent'] ?? [];
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tools(): array
    {
        $value = $this->raw['tools'] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function requiredPlugins(): array
    {
        $value = $this->raw['required_plugins'] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $slug) {
            if (is_string($slug) && $slug !== '') {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $value = $this->raw['metadata'] ?? [];
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function filename(): ?string
    {
        return $this->filename;
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
}
