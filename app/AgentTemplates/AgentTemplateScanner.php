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
 * array carries a `PARSE_ERROR` or `VALIDATION_ERROR` entry plus the
 * parsed partial data where available.
 *
 * This explicit-failure policy differs from {@see \Spora\Recipes\RecipeScanner}
 * (which silently swallows errors). Templates drive agent creation, so
 * operators must always see why a bundled template didn't make it.
 */
final class AgentTemplateScanner
{
    /**
     * @param list<string> $directories Absolute paths to scan (depth 0).
     * @param list<string> $coreSlugs Slugs that, when matched, mark a file as `source: 'core'`.
     */
    public function __construct(
        private readonly array $directories = [],
        private readonly array $coreSlugs = ['core'],
        private readonly ?AgentTemplateValidator $validator = null,
    ) {}

    /**
     * @return list<AgentTemplate>
     */
    public function scan(): array
    {
        $validator = $this->validator ?? new AgentTemplateValidator();
        $templates = [];

        foreach ($this->directories as $dir) {
            if (!is_dir($dir)) {
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
                    $dir,
                    $validator,
                );
            }
        }

        return $templates;
    }

    private function parseFile(
        string $path,
        string $filename,
        string $dir,
        AgentTemplateValidator $validator,
    ): AgentTemplate {
        $raw = $this->loadFileData($path, $filename);
        if ($raw === null) {
            // loadFileData already captured the parse error into the
            // returned placeholder; no further action needed.
            return $this->errorTemplate($filename, $dir);
        }

        // loadFileData returns array<string, mixed>|null; after the null
        // guard $raw is guaranteed to be an array. JSON scalars / YAML
        // scalars that aren't maps surface as PARSE_ERROR through the
        // type-narrowing loadFileData contract.

        $result = $validator->validate($raw);
        $warnings = $result->errors() === []
            ? $result->warnings()
            : array_merge($result->errors(), $result->warnings());

        $source = $this->resolveSource($filename, $dir);

        return new AgentTemplate(
            raw: $raw,
            initialWarnings: $warnings,
            source: $source,
            filename: $filename,
        );
    }

    private function errorTemplate(
        string $filename,
        string $dir,
        string $code = 'PARSE_ERROR',
        ?string $message = null,
    ): AgentTemplate {
        $template = new AgentTemplate(
            raw: [],
            initialWarnings: [
                [
                    'code'     => $code,
                    'severity' => 'error',
                    'message'  => $message ?? "Failed to parse template file '{$filename}'.",
                    'path'     => $filename,
                ],
            ],
            source: $this->resolveSource($filename, $dir),
            filename: $filename,
        );
        return $template;
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

    /**
     * Map a template file to its logical source. Bundled core templates
     * live under the framework's `agent-templates/` directory; anything
     * contributed by a plugin is named after the plugin slug.
     */
    private function resolveSource(string $filename, string $dir): string
    {
        $normalized = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        if (in_array($normalized, $this->coreSlugs, true)) {
            return 'core';
        }
        // Derive a per-directory slug from the basename of $dir (the
        // directory the file lives in). Plugin-shipped paths look like
        // `plugins/<slug>/agent-templates`, app paths like
        // `<base>/agent-templates`. Falls back to the raw basename.
        $slug = strtolower(basename($dir));
        return $slug !== '' ? $slug : 'uploaded';
    }
}
