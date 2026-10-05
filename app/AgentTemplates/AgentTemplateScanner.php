<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Scans one or more directories for agent template definition files
 * (.json / .yaml / .yml). Each file is parsed, validated, and returned
 * as an {@see AgentTemplate}. Files that fail to parse or validate are
 * NOT silently dropped — they return an AgentTemplate whose `warnings`
 * array carries a `PARSE_ERROR` entry for an unreadable or malformed
 * file, or the specific code the validator raised (`ID_REQUIRED`,
 * `TOOL_CLASS_REQUIRED`, `OPERATION_UNKNOWN`, `MAX_STEPS_RANGE`, …),
 * plus the parsed partial data where available.
 *
 * The `source` label on each root is what separates a bundled template
 * from a plugin's, in the gallery and in the namespace check below — so
 * it travels with the root rather than being derived from the directory
 * basename (every template directory is called `agent-templates`).
 *
 * Templates drive agent creation, so operators must always see why a
 * bundled template didn't make it — the failure is reported in the
 * gallery, not swallowed into an absent entry.
 */
final class AgentTemplateScanner
{
    /**
     * @param list<array{path: string, source: string}> $roots Scan roots (depth 0),
     *        each carrying the `source` label reported on every template it
     *        yields. Typical sources: `'project'`, `'core'`, or a plugin slug.
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

        // Namespace enforcement: a plugin- or project-supplied template
        // must declare an id whose namespace prefix is its own source
        // label, so two contributors shipping the same short id stay
        // distinguishable. Core and operator-uploaded templates are exempt
        // — neither has a competitor to be confused with.
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
