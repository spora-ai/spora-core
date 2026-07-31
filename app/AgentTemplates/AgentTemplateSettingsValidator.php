<?php

declare(strict_types=1);

namespace Spora\AgentTemplates;

use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ToolSettingSchema;

/**
 * Validates the optional `tools[].settings` block against the declaring
 * tool's `#[ToolSetting]` attributes.
 *
 * Extracted from {@see AgentTemplateValidator} so the validator stays under
 * the SonarCloud 20-method-per-class ceiling (S1448). Errors are
 * appended to the caller's {@see ValidationResult}; password-typed keys
 * are always rejected at this layer (defence-in-depth — the exporter
 * never emits them).
 */
final class AgentTemplateSettingsValidator
{
    public const SETTINGS_PASSWORD_KEY_FORBIDDEN = 'SETTINGS_PASSWORD_KEY_FORBIDDEN';
    public const SETTINGS_UNKNOWN_KEY = 'SETTINGS_UNKNOWN_KEY';
    public const SETTINGS_INVALID_VALUE_TYPE = 'SETTINGS_INVALID_VALUE_TYPE';

    /** @var array<string, list<string>> keyed by tool_class */
    private array $passwordKeysCache = [];

    /**
     * @param array<string, mixed> $tool
     */
    public function validate(array $tool, string $toolClass, string $path, ValidationResult $result): void
    {
        $settings = $tool['settings'];
        if (!is_array($settings) || array_is_list($settings) && $settings !== []) {
            $this->addTypeError($result, $path . '.settings', 'Settings must be an object.');
            return;
        }
        if (!class_exists($toolClass)) {
            return;
        }

        $schema = $this->settingsByKey($toolClass);
        $passwordKeys = $this->passwordKeys($toolClass);
        foreach ($settings as $key => $value) {
            $settingPath = "{$path}.settings.{$key}";
            if (!isset($schema[$key])) {
                $this->addError($result, self::SETTINGS_UNKNOWN_KEY, "Unknown tool setting '{$key}'.", $settingPath);
            } elseif (in_array($key, $passwordKeys, true)) {
                $this->addError($result, self::SETTINGS_PASSWORD_KEY_FORBIDDEN, "Password setting '{$key}' cannot be imported from a template.", $settingPath);
            } elseif (!$this->isValidSettingValue($schema[$key], $value)) {
                $this->addTypeError($result, $settingPath, "Invalid value type for tool setting '{$key}'.");
            }
        }
    }

    /** @return array<string, ToolSetting> */
    private function settingsByKey(string $toolClass): array
    {
        $settings = [];
        foreach (ToolSettingSchema::collect($toolClass) as $setting) {
            $settings[$setting->key] = $setting;
        }
        return $settings;
    }

    /** @return list<string> */
    private function passwordKeys(string $toolClass): array
    {
        if (!isset($this->passwordKeysCache[$toolClass])) {
            $keys = [];
            foreach (ToolSettingSchema::collect($toolClass) as $setting) {
                if ($setting->type === 'password') {
                    $keys[] = $setting->key;
                }
            }
            $this->passwordKeysCache[$toolClass] = $keys;
        }
        return $this->passwordKeysCache[$toolClass];
    }

    private function isValidSettingValue(ToolSetting $setting, mixed $value): bool
    {
        return match ($setting->type) {
            'multi-select' => is_array($value)
                && array_is_list($value)
                && array_all($value, static fn(mixed $item): bool => is_string($item)),
            'toggle' => is_bool($value),
            default => is_scalar($value),
        };
    }

    private function addTypeError(ValidationResult $result, string $path, string $message): void
    {
        $this->addError($result, self::SETTINGS_INVALID_VALUE_TYPE, $message, $path);
    }

    private function addError(ValidationResult $result, string $code, string $message, string $path): void
    {
        $result->addError([
            'code'     => $code,
            'severity' => 'error',
            'message'  => $message,
            'path'     => $path,
        ]);
    }
}
