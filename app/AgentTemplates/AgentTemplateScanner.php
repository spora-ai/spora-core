<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Scans agent template definition files (.json / .yaml / .yml), parsing and
 * validating each into an {@see AgentTemplate}. A file that fails either is
 * returned carrying a `PARSE_ERROR` or the validator's own code rather than
 * dropped: a template that silently fails to appear is indistinguishable from
 * one that was never installed.
 *
 * Each root carries the `source` label its templates report and are
 * namespace-checked against. It travels with the root because every template
 * directory is named `agent-templates` — deriving it from the path would
 * collapse every contributor onto one label.
 */
final class AgentTemplateScanner
{
    /**
     * @param list<array{path: string, source: string}> $roots Scan roots (depth 0).
     *        `source` is `'project'`, `'core'`, `'app'`, or a plugin slug.
     */
    public function __construct(
        private readonly array $roots = [],
        private readonly ?AgentTemplateValidator $validator = null,
    ) {}

    /**
     * @return list<AgentTemplate>
     */
    public function scan(): array
    {
        $validator = $this->validator ?? new AgentTemplateValidator();
        $templates = [];

        foreach ($this->roots as $root) {
            $dir = $root['path'];
            $source = $root['source'];
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }

            $finder = (new Finder())
                ->files()
                ->in($dir)
                ->depth(0)
                ->name(['*.json', '*.yaml', '*.yml'])
                ->sortByName();

            foreach ($finder as $file) {
                $templates[] = $this->parseFile(
                    $file->getRealPath(),
                    $file->getFilename(),
                    $source,
                    $validator,
                );
            }
        }

        return $templates;
    }

    private function parseFile(
        string $path,
        string $filename,
        string $source,
        AgentTemplateValidator $validator,
    ): AgentTemplate {
        $raw = $this->loadFileData($path, $filename);
        if ($raw === null) {
            return $this->errorTemplate($filename, $source);
        }

        // loadFileData returns array<string, mixed>|null; after the null
        // guard $raw is guaranteed to be an array. JSON scalars / YAML
        // scalars that aren't maps surface as PARSE_ERROR through the
        // type-narrowing loadFileData contract.

        $result = $validator->validate($raw);
        $warnings = $result->errors() === []
            ? $result->warnings()
            : array_merge($result->errors(), $result->warnings());

        // `core` and `uploaded` are exempt: neither has a second contributor
        // for a matching namespace to keep apart.
        $declaredId = is_string($raw['id'] ?? null) ? (string) $raw['id'] : '';
        if ($declaredId !== '' && $source !== 'core' && $source !== 'uploaded') {
            $namespace = strstr($declaredId, '/', true);
            if ($namespace === false || $namespace !== $source) {
                $warnings[] = [
                    'code'     => 'NAMESPACE_MISMATCH',
                    'severity' => 'warning',
                    'message'  => sprintf(
                        "Template id '%s' does not start with the source namespace '%s/'. Expected format: '%s/<name>'.",
                        $declaredId,
                        $source,
                        $source,
                    ),
                    'path'     => 'id',
                ];
            }
        }

        return new AgentTemplate(
            raw: $raw,
            initialWarnings: $warnings,
            source: $source,
            filename: $filename,
        );
    }

    private function errorTemplate(
        string $filename,
        string $source,
        string $code = 'PARSE_ERROR',
        ?string $message = null,
    ): AgentTemplate {
        return new AgentTemplate(
            raw: [],
            initialWarnings: [
                [
                    'code'     => $code,
                    'severity' => 'error',
                    'message'  => $message ?? "Failed to parse template file '{$filename}'.",
                    'path'     => $filename,
                ],
            ],
            source: $source,
            filename: $filename,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadFileData(string $path, string $filename): ?array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            $data = match ($ext) {
                'json'        => $this->parseJson($path),
                'yaml', 'yml' => $this->parseYaml($path),
                default       => null,
            };
        } catch (Throwable $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            return json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return mixed
     */
    private function parseYaml(string $path): mixed
    {
        return Yaml::parseFile($path);
    }
}
