<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Http\Exceptions\SpeechProviderConfigException;
use Spora\Speech\SpeechToTextRegistry;
use Spora\Tools\Attributes\ToolSetting;

/**
 * Schema validation for the speech provider configuration surface.
 *
 * Owns the per-provider-class `#[ToolSetting]` walk + per-field
 * required/regex check, plus the "is this a registered provider
 * class?" gate. Extracted from {@see SpeechProviderConfigService}
 * so the umbrella stays under the SonarCloud S1448 20-method
 * ceiling.
 */
final class SpeechProviderConfigValidator
{
    public function __construct(
        private readonly SpeechToTextRegistry $registry,
    ) {}

    public function assertRegisteredProviderClass(string $class): void
    {
        if (!$this->isRegisteredProviderClass($class)) {
            throw SpeechProviderConfigException::notFound(
                "Speech provider class '{$class}' is not registered.",
            );
        }
    }

    public function isRegisteredProviderClass(string $class): bool
    {
        foreach ($this->registry->all() as $provider) {
            if ($provider::class === $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    public function passwordKeysFor(string $providerClass): array
    {
        $keys = [];
        foreach ($this->collectSettingsSchema($providerClass) as $setting) {
            if (($setting['type'] ?? '') === 'password') {
                $keys[] = (string) $setting['key'];
            }
        }
        return $keys;
    }

    /**
     * Walk `#[ToolSetting]` attributes on a provider class and return
     * each entry as a flat dict (the shape the SPA needs to render
     * the dynamic form).
     *
     * @return list<array<string, mixed>>
     */
    public function collectSettingsSchema(string $providerClass): array
    {
        if (!class_exists($providerClass)) {
            return [];
        }
        $ref = new ReflectionClass($providerClass);
        $settings = [];
        foreach ($ref->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $instance */
            $instance = $attr->newInstance();
            $settings[] = [
                'key'         => $instance->key,
                'label'       => $instance->label,
                'type'        => $instance->type,
                'description' => $instance->description,
                'default'     => $instance->default,
                'required'    => $instance->required,
                'options'     => $instance->options,
                'validation'  => $instance->validation,
            ];
        }
        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @throws SpeechProviderConfigException
     */
    public function assertSettingsAgainstSchema(string $providerClass, array $settings): void
    {
        if (!class_exists($providerClass)) {
            throw SpeechProviderConfigException::notFound(
                "Speech provider class '{$providerClass}' is not registered.",
            );
        }

        $schema = $this->collectSettingsSchema($providerClass);
        $allowed = [];
        foreach ($schema as $entry) {
            $allowed[(string) $entry['key']] = true;
        }

        foreach ($settings as $key => $value) {
            if (!isset($allowed[$key])) {
                throw SpeechProviderConfigException::validation(
                    "Settings key '{$key}' is not declared on {$providerClass}.",
                );
            }
        }

        foreach ($schema as $entry) {
            if (!($entry['required'] ?? false)) {
                continue;
            }
            $this->assertRequiredSetting((string) $entry['key'], (string) $entry['label'], (string) ($entry['validation'] ?? ''), $settings);
        }
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @throws SpeechProviderConfigException
     */
    private function assertRequiredSetting(string $key, string $label, string $validation, array $settings): void
    {
        $value = $settings[$key] ?? null;
        if ($value === null || $value === '') {
            throw SpeechProviderConfigException::validation(
                "Field '{$label}' is required.",
            );
        }
        if ($validation !== '' && is_string($value) && !preg_match($validation, $value)) {
            throw SpeechProviderConfigException::validation(
                "Field '{$label}' has an invalid value.",
            );
        }
    }
}
