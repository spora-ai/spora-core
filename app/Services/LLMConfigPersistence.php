<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Core\Exceptions\DecryptionFailedException;
use Spora\Core\SecurityManagerInterface;
use Spora\Core\ValueObjects\EncryptedValue;
use Spora\Models\Agent;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\UserPreference;

/**
 * Persistence and authorization for LLMDriverConfiguration rows.
 *
 * Owns the create / update / delete flows, including per-field settings
 * encryption via SecurityManager, the default-toggle bookkeeping, and
 * detaching references from agents and user preferences when a config
 * is removed. The schema inspector and security manager are injected
 * so this class never instantiates its own collaborators.
 */
final class LLMConfigPersistence
{
    public function __construct(
        private readonly SecurityManagerInterface $security,
        private readonly LLMConfigSchemaInspector $schemaInspector,
    ) {}

    public function createConfiguration(int $userId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        $validated = $this->validateNewConfigurationInputs($data, $isAdmin);
        if ($validated === null) {
            return null;
        }

        return $this->persistNewConfiguration(
            $userId,
            $validated['name'],
            $validated['driver_class'],
            $validated['settings'],
            $validated['is_global'],
            $data,
        );
    }

    public function updateConfiguration(int $configId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = $this->loadEditableConfiguration($configId, $isAdmin);
        if ($config === null) {
            return null;
        }

        $applied = $this->applyConfigurationUpdates($config, $data);
        if ($applied === null) {
            return null;
        }

        $config->save();

        return $config;
    }

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool
    {
        $config = LLMDriverConfiguration::find($configId);
        $allowed = $config !== null
            && ($isAdmin || !$config->is_global)
            && ($config->is_global || $config->user_id === $userId);

        if (!$allowed) {
            return false;
        }

        $this->detachConfigurationReferences($configId);
        $config->delete();

        return true;
    }

    /**
     * Encode settings: encrypt password fields per-field, store others as plain JSON.
     * Returns an array ready for json_encode — NOT an encrypted blob.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function encodeSettings(string $driverClass, array $settings): array
    {
        $passwordKeys = $this->schemaInspector->getPasswordKeysFor($driverClass);
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
            $json = $this->security->decrypt(new EncryptedValue($raw));
            return is_array($decoded = json_decode($json, true)) ? $decoded : [];
        }

        $data = json_decode($raw, true) ?: [];
        $passwordKeys = $this->schemaInspector->getPasswordKeysFor($driverClass);

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

    /**
     * @return array{name: string, driver_class: string, settings: array<string, mixed>, is_global: bool}|null
     */
    private function validateNewConfigurationInputs(array $data, bool $isAdmin): ?array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $driverClass = trim((string) ($data['driver_class'] ?? ''));
        $rawSettings = $data['settings'] ?? null;
        $settings = is_array($rawSettings) ? $rawSettings : [];
        $isGlobal = !empty($data['is_global']);

        $invalid = $name === ''
            || $driverClass === '' || !class_exists($driverClass)
            || ($isGlobal && !$isAdmin);

        if ($invalid) {
            return null;
        }

        return [
            'name' => $name,
            'driver_class' => $driverClass,
            'settings' => $settings,
            'is_global' => $isGlobal,
        ];
    }

    private function persistNewConfiguration(
        int $userId,
        string $name,
        string $driverClass,
        array $settings,
        bool $isGlobal,
        array $data,
    ): LLMDriverConfiguration {
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

    private function loadEditableConfiguration(int $configId, bool $isAdmin): ?LLMDriverConfiguration
    {
        $config = LLMDriverConfiguration::find($configId);
        if ($config === null) {
            return null;
        }

        if (!$isAdmin && $config->is_global) {
            return null;
        }

        return $config;
    }

    private function applyConfigurationUpdates(LLMDriverConfiguration $config, array $data): ?bool
    {
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

        return true;
    }

    private function detachConfigurationReferences(int $configId): void
    {
        // Unset any agents using this config
        Agent::where('llm_driver_config_id', $configId)->update(['llm_driver_config_id' => null]);

        // Delete any user preferences referencing this config (cascade delete)
        UserPreference::where('preferred_llm_config_id', $configId)->delete();
    }
}
