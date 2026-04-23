<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Drivers\LLMDriverConfigInterface;
use Spora\Models\LLMDriverConfiguration;
use Spora\Tools\Attributes\ToolSetting;

/**
 * Service for managing LLM driver configurations.
 *
 * Handles:
 * - Discovery of registered driver classes (implementing LLMDriverConfigInterface)
 * - Encryption/decryption of settings JSON
 * - CRUD operations via Eloquent
 *
 * Settings are encrypted using SecurityManager before being stored in the DB.
 */
final class LLMConfigService
{
    /**
     * @param list<class-string<LLMDriverConfigInterface>> $driverClasses
     */
    public function __construct(
        private readonly SecurityManagerInterface $security,
        private readonly array $driverClasses = [],
    ) {}

    // ── Discovery ───────────────────────────────────────────────────────────────

    /**
     * Returns all registered driver classes with their resolved schemas.
     *
     * @return list<array{name: string, display_name: string, driver_class: string, settings_schema: list<array>} >
     */
    public function getDrivers(): array
    {
        $drivers = [];

        foreach ($this->driverClasses as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $name = $class::getName();
            $displayName = $class::getDisplayName();
            $settingsSchema = $this->buildSchemaFromClass($class);

            $drivers[] = [
                'name' => $name,
                'display_name' => $displayName,
                'driver_class' => $class,
                'settings_schema' => $settingsSchema,
            ];
        }

        return $drivers;
    }

    /**
     * @return list<array{key: string, label: string, type: string, description: string, default: mixed, required: bool, scope: string, options: array|null}>
     */
    private function buildSchemaFromClass(string $class): array
    {
        $ref = new ReflectionClass($class);

        $schema = [];
        foreach ($ref->getAttributes(ToolSetting::class) as $attr) {
            $setting = $attr->newInstance();
            $schema[] = [
                'key' => $setting->key,
                'label' => $setting->label,
                'type' => $setting->type,
                'description' => $setting->description,
                'default' => $setting->default,
                'required' => $setting->required,
                'scope' => $setting->scope,
                'options' => $setting->options,
            ];
        }

        return $schema;
    }

    // ── Encryption helpers ──────────────────────────────────────────────────────

    /**
     * Encrypt a settings array to a storage string (base64-encoded encrypted JSON).
     *
     * @param array<string, mixed> $settings
     */
    public function encryptSettings(array $settings): string
    {
        $encrypted = $this->security->encrypt(json_encode($settings, JSON_THROW_ON_ERROR));
        return $encrypted->toStorageString();
    }

    /**
     * Decrypt a storage string back to a plain settings array.
     *
     * @return array<string, mixed>
     */
    public function decryptSettings(string $storageString): array
    {
        $encrypted = new EncryptedValue($storageString);
        $json = $this->security->decrypt($encrypted);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true) ?? [];
        return $decoded;
    }

    /**
     * Mask a settings array for API responses — replace password fields with "***".
     *
     * @param array<string, mixed> $settings
     * @param list<array> $schema
     * @return array<string, mixed>
     */
    public function maskForApi(array $settings, array $schema): array
    {
        $passwordKeys = [];
        foreach ($schema as $field) {
            if (($field['type'] ?? '') === 'password') {
                $passwordKeys[] = $field['key'];
            }
        }

        $masked = [];
        foreach ($settings as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== '') {
                $masked[$key] = '***';
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    /**
     * Returns the default LLMDriverConfiguration (is_default = true).
     */
    public function getDefaultConfiguration(int $userId): ?LLMDriverConfiguration
    {
        return LLMDriverConfiguration::where('user_id', $userId)->where('is_default', true)->first();
    }
}
