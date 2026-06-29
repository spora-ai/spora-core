<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Core\Paths;
use Spora\Services\Exceptions\EmailTemplateParseException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads email templates from the email-templates/ directory.
 * Templates are YAML files that can be version-controlled.
 *
 * Searches the project's email-templates/ directory first (if it exists),
 * then the framework's bundled defaults. Project templates override framework
 * templates with the same name.
 */
final class EmailTemplateLoader
{
    /** @var array<string, array{name: string, subject: string, body_text: string, body_html: string|null}|null> */
    private ?array $templates = null;

    public function __construct(
        private readonly Paths $paths,
    ) {}

    public function getAll(): array
    {
        $this->load();

        return $this->templates ?? [];
    }

    public function get(string $name): ?array
    {
        $this->load();

        return $this->templates[$name] ?? null;
    }

    private function load(): void
    {
        if ($this->templates !== null) {
            return;
        }

        $this->templates = [];

        foreach ($this->paths->emailTemplatesPaths() as $dir) {
            foreach ($this->yamlFilesIn($dir) as $file) {
                $this->mergeTemplateFile($file);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function yamlFilesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.yaml');

        return $files === false ? [] : $files;
    }

    private function mergeTemplateFile(string $file): void
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new EmailTemplateParseException(sprintf('Failed to parse email template "%s": %s', $file, $e->getMessage()), 0, $e);
        }

        if (!is_array($data) || !isset($data['name'])) {
            return;
        }

        $name = (string) $data['name'];

        // Project overrides win: skip if a higher-priority dir already provided this template.
        if (isset($this->templates[$name])) {
            return;
        }

        $this->templates[$name] = $data;
    }
}

