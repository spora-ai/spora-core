<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Services\Exceptions\EmailTemplateParseException;
use Symfony\Component\Yaml\Exception\ParseException;
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

        $files = glob(self::TEMPLATES_DIR . '/*.yaml');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            try {
                $data = Yaml::parseFile($file);
                if (is_array($data) && isset($data['name'])) {
                    $this->templates[$data['name']] = $data;
                }
            } catch (ParseException $e) {
                throw new EmailTemplateParseException(sprintf('Failed to parse email template "%s": %s', $file, $e->getMessage()), 0, $e);
            }
        }
    }
}
