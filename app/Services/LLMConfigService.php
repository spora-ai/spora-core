<?php

declare(strict_types=1);

namespace Spora\Services;

use ReflectionClass;
use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Drivers\LLMDriverConfigInterface;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\UserPreference;
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
final class LLMConfigService implements LLMConfigServiceInterface
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
     * Encode settings: encrypt password fields per-field, store others as plain JSON.
     * Returns an array ready for json_encode — NOT an encrypted blob.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function encodeSettings(string $driverClass, array $settings): array
    {
        $passwordKeys = $this->getPasswordKeys($driverClass);
        $encoded = [];

        foreach ($settings as $key => $value) {
            if (in_array($key, $passwordKeys, true) && $value !== null && $value !== '') {
                $encrypted = $this->security->encrypt((string) $value);
                $encoded[$key] = $encrypted->toStorageString();
            } else {
                $encoded[$key] = $value;
            }
        }

        return $encoded;
    }

    /**
     * Decode a JSON string or legacy encrypted blob back to a plain settings array.
     * Handles both per-field encoded JSON (new) and wholesale encrypted blobs (legacy).
     *
     * @return array<string, mixed>
     */
    public function decodeSettings(string $driverClass, ?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if ($this->security->looksEncrypted($raw)) {
            // Legacy wholesale-encrypted blob
            $json = $this->security->decrypt(new EncryptedValue($raw));
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true) ?? [];
            return $decoded;
        }

        // Per-field format: plain JSON, decrypt each password key
        $data = json_decode($raw, true) ?: [];
        $passwordKeys = $this->getPasswordKeys($driverClass);

        foreach ($passwordKeys as $key) {
            if (isset($data[$key]) && $data[$key] !== '' && is_string($data[$key])) {
                try {
                    $data[$key] = $this->security->decrypt(new EncryptedValue($data[$key]));
                } catch (DecryptionFailedException) {
                    $data[$key] = null;
                }
            }
        }

        return $data;
    }

    public function decryptSettings(string $driverClass, ?string $raw): array
    {
        return $this->decodeSettings($driverClass, $raw);
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

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * @return list<array>
     */
    public function getConfigurationsForUser(int $userId): array
    {
        return LLMDriverConfiguration::where('user_id', $userId)
            ->orWhere('is_global', true)
            ->get()
            ->map(fn(LLMDriverConfiguration $config): array => $this->configResource($config))
            ->all();
    }

    /**
     * @return list<array>
     */
    public function getGlobalConfigurations(): array
    {
        return LLMDriverConfiguration::where('is_global', true)
            ->orderBy('name')
            ->get()
            ->map(fn(LLMDriverConfiguration $config): array => $this->configResource($config))
            ->all();
    }

    public function getConfiguration(int $configId, int $userId): ?LLMDriverConfiguration
    {
        return LLMDriverConfiguration::where('id', $configId)->where('user_id', $userId)->first();
    }

    public function findConfiguration(int $configId): ?LLMDriverConfiguration
    {
        return LLMDriverConfiguration::find($configId);
    }

    public function createConfiguration(int $userId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $driverClass = trim((string) ($data['driver_class'] ?? ''));
        if ($driverClass === '' || !class_exists($driverClass)) {
            return null;
        }

        $rawSettings = $data['settings'] ?? null;
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $isGlobal = !empty($data['is_global']);

        if ($isGlobal && !$isAdmin) {
            return null;
        }

        $config = new LLMDriverConfiguration();
        $config->user_id = $isGlobal ? null : $userId;
        $config->is_global = $isGlobal;
        $config->name = $name;
        $config->driver_class = $driverClass;
        $config->settings = json_encode($this->encodeSettings($driverClass, $settings));
        $config->is_default = !empty($data['is_default']);
        if ($config->is_default) {
            if ($isGlobal) {
                LLMDriverConfiguration::where('is_global', true)->where('is_default', true)->update(['is_default' => false]);
            } else {
                LLMDriverConfiguration::where('user_id', $userId)->where('is_default', true)->update(['is_default' => false]);
            }
        }
        $config->context_window = isset($data['context_window']) ? (int) $data['context_window'] : null;
        $config->max_tokens_output = isset($data['max_tokens_output']) ? (int) $data['max_tokens_output'] : null;
        $config->save();

        return $config;
    }

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = LLMDriverConfiguration::find($configId);
        if ($config === null) {
            return null;
        }

        if (!$isAdmin && $config->is_global) {
            return null;
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return null;
            }
            $config->name = $name;
        }

        if (isset($data['settings']) && is_array($data['settings']) && !array_is_list($data['settings'])) {
            $existing = $this->decodeSettings($config->driver_class, $config->getRawOriginal('settings') ?? '');
            $merged = array_merge($existing, $data['settings']);
            $config->settings = json_encode($this->encodeSettings($config->driver_class, $merged));
        }

        if (isset($data['context_window'])) {
            $config->context_window = (int) $data['context_window'];
        }
        if (isset($data['max_tokens_output'])) {
            $config->max_tokens_output = (int) $data['max_tokens_output'];
        }

        $config->save();

        return $config;
    }

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool
    {
        $config = LLMDriverConfiguration::find($configId);
        if ($config === null) {
            return false;
        }

        if (!$isAdmin && $config->is_global) {
            return false;
        }

        // Check if config belongs to another user (only applies to non-global configs)
        if (!$config->is_global && $config->user_id !== $userId) {
            return false;
        }

        // Unset any agents using this config
        Agent::where('llm_driver_config_id', $configId)->update(['llm_driver_config_id' => null]);

        // Delete any user preferences referencing this config (cascade delete)
        UserPreference::where('preferred_llm_config_id', $configId)->delete();

        $config->delete();

        return true;
    }

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = LLMDriverConfiguration::find($configId);
        if ($config === null) {
            return null;
        }

        // Restrict to global configs only — personal default is now set via user preferences
        if (!$config->is_global) {
            return null;
        }

        if (!$isAdmin) {
            return null;
        }

        LLMDriverConfiguration::where('is_global', true)->where('is_default', true)->update(['is_default' => false]);

        $config->is_default = true;
        $config->save();

        return $config;
    }

    /**
     * Returns the default LLMDriverConfiguration (is_default = true).
     */
    public function getDefaultConfiguration(int $userId): ?LLMDriverConfiguration
    {
        return $this->getUserPreferredConfig($userId);
    }

    /**
     * Resolves the effective LLMDriverConfiguration for an agent using three-tier fallback.
     *
     * Tier 1: Agent-specific config     (agent.llm_driver_config_id)
     * Tier 2: User's preferred config   (user_preferences.preferred_llm_config_id)
     * Tier 3: Global default           (is_global=true, is_default=true)
     */
    public function getEffectiveConfigForAgent(Agent $agent): ?LLMDriverConfiguration
    {
        // Tier 1: agent-specific
        if ($agent->llm_driver_config_id !== null) {
            $config = LLMDriverConfiguration::find($agent->llm_driver_config_id);
            if ($config !== null) {
                return $config;
            }
        }

        // Tier 2: user preferred config (via user_preferences)
        if ($agent->user_id !== null) {
            $config = $this->getUserPreferredConfig($agent->user_id);
            if ($config !== null) {
                return $config;
            }
        }

        // Tier 3: global default
        return LLMDriverConfiguration::where('is_global', true)
            ->where('is_default', true)
            ->first();
    }

    // ── User Preferences ─────────────────────────────────────────────────────

    public function getUserPreferredConfig(int $userId): ?LLMDriverConfiguration
    {
        $preference = UserPreference::where('user_id', $userId)->first();
        if ($preference === null || $preference->preferred_llm_config_id === null) {
            return null;
        }

        return LLMDriverConfiguration::find($preference->preferred_llm_config_id);
    }

    public function setUserPreferredConfig(int $userId, int $configId): bool
    {
        $config = LLMDriverConfiguration::find($configId);
        if ($config === null) {
            return false;
        }

        // Config must belong to user OR be global
        if (!$config->is_global && $config->user_id !== $userId) {
            return false;
        }

        $preference = UserPreference::firstOrCreate(['user_id' => $userId]);
        $preference->preferred_llm_config_id = $configId;
        $preference->save();

        return true;
    }

    public function unsetUserPreferredConfig(int $userId): void
    {
        UserPreference::where('user_id', $userId)->delete();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function getPasswordKeys(string $driverClass): array
    {
        $reflection = new ReflectionClass($driverClass);
        $keys = [];

        foreach ($reflection->getAttributes(ToolSetting::class) as $attribute) {
            /** @var ToolSetting $instance */
            $instance = $attribute->newInstance();
            if ($instance->type === 'password') {
                $keys[] = $instance->key;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    public function configResource(LLMDriverConfiguration $config): array
    {
        $settings = $this->decodeSettings($config->driver_class, $config->getRawOriginal('settings'));
        $schema = $this->getSchemaForDriver($config->driver_class);
        $masked = $this->maskForApi($settings, $schema);

        return [
            'id' => $config->id,
            'name' => $config->name,
            'driver_class' => $config->driver_class,
            'driver_name' => $this->getDriverName($config->driver_class),
            'driver_display_name' => $this->getDriverDisplayName($config->driver_class),
            'settings' => $masked,
            'context_window' => $config->context_window,
            'max_tokens_output' => $config->max_tokens_output,
            'is_default' => $config->is_default,
            'user_id' => $config->user_id,
            'is_global' => $config->is_global,
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return list<array>
     */
    private function getSchemaForDriver(string $driverClass): array
    {
        if (!class_exists($driverClass)) {
            return [];
        }

        $schema = [];
        foreach ((new ReflectionClass($driverClass))->getAttributes(ToolSetting::class) as $attr) {
            /** @var ToolSetting $setting */
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

    private function getDriverName(string $driverClass): string
    {
        if (!class_exists($driverClass)) {
            return $driverClass;
        }
        return $driverClass::getName();
    }

    private function getDriverDisplayName(string $driverClass): string
    {
        if (!class_exists($driverClass)) {
            return $driverClass;
        }
        return $driverClass::getDisplayName();
    }
}
