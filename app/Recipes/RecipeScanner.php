<?php

declare(strict_types=1);

namespace Spora\Recipes;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Scans one or more directories for recipe definition files (.json / .yaml / .yml).
 * Each file must contain at minimum the keys: id, name, description.
 * Files that are missing required keys or cannot be parsed are silently skipped.
 */
final class RecipeScanner
{
    /**
     * @param  string[]  $directories  Absolute paths to directories to scan.
     */
    public function __construct(
        private readonly array $directories = [],
    ) {}

    /**
     * Scan all configured directories and return a flat list of recipe metadata.
     *
     * @return list<array{id: string, name: string, description: string, filename: string}>
     */
    public function scan(): array
    {
        $recipes = [];

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
                $recipe = $this->parseFile($file->getRealPath(), $file->getFilename());

                if ($recipe !== null) {
                    $recipes[] = $recipe;
                }
            }
        }

        return $recipes;
    }

    /**
     * @return array{id: string, name: string, description: string, filename: string}|null
     */
    private function parseFile(string $path, string $filename): ?array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            $data = match ($ext) {
                'json'        => $this->parseJson($path),
                'yaml', 'yml' => $this->parseYaml($path),
                default       => null,
            };
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        if (
            !isset($data['id'], $data['name'], $data['description']) ||
            !is_string($data['id']) || $data['id'] === '' ||
            !is_string($data['name']) || $data['name'] === '' ||
            !is_string($data['description'])
        ) {
            return null;
        }

        return [
            'id'          => $data['id'],
            'name'        => $data['name'],
            'description' => $data['description'],
            'filename'    => $filename,
        ];
    }

    /**
     * @return mixed
     */
    private function parseJson(string $path): mixed
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        return json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return mixed
     * @throws ParseException
     */
    private function parseYaml(string $path): mixed
    {
        return Yaml::parseFile($path);
    }
}
