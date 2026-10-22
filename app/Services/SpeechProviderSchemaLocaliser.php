<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\I18n\Translator;

/**
 * The operator-facing copy of a speech provider's schema.
 *
 * `label` and `options[].label` are never translated — see
 * {@see \Spora\Build\CatalogHarvester}. The description is the blurb the
 * operator reads under the setting, so it does translate.
 */
final class SpeechProviderSchemaLocaliser
{
    public function __construct(
        private readonly Translator $translator,
    ) {}

    /**
     * The provider's name as the operator sees it.
     */
    public function displayName(string $name): string
    {
        return $this->translator->trans($name);
    }

    /**
     * The settings schema with every description translated.
     *
     * @param list<array<string, mixed>> $settings
     * @return list<array<string, mixed>>
     */
    public function descriptions(array $settings): array
    {
        foreach ($settings as $index => $setting) {
            $settings[$index]['description'] = $this->translator->trans((string) $setting['description']);
        }

        return $settings;
    }
}
