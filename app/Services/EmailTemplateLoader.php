<?php

declare(strict_types=1);

namespace Spora\Services;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads email templates from the email-templates/ directory.
 * Templates are YAML files that can be version-controlled.
 */
final class EmailTemplateLoader
{
    private const TEMPLATES_DIR = BASE_PATH . '/email-templates';

    /** @var array<string, array{name: string, subject: string, body_text: string, body_html: string|null}|null> */
    private ?array $templates = null;

    public function getAll(): array
    {
        $this->load();

        return $this->templates;
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

        $files = glob(self::TEMPLATES_DIR . '/*.yaml');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $data = Yaml::parseFile($file);
            if (isset($data['name'])) {
                $this->templates[$data['name']] = $data;
            }
        }
    }
}
