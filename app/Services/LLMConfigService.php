<?php

declare(strict_types=1);

namespace Spora\Services;

use Spora\Core\SecurityManagerInterface;
use Spora\Models\LLMDriverConfiguration;

/**
 * Service for managing LLM driver configurations.
 *
 * Thin facade that keeps the public API stable while delegating the
 * heavy lifting to three focused collaborators:
 *  - {@see LLMConfigSchemaInspector} for driver discovery and schema introspection
 *  - {@see LLMConfigPersistence} for CRUD with per-field encryption
 *  - {@see LLMConfigPreferences} for the three-tier default-resolution path
 *
 * Public method signatures are preserved so the DI container wiring,
 * existing controllers, and tests remain unchanged.
 */
final class LLMConfigService implements LLMConfigServiceInterface
{
    private readonly LLMConfigSchemaInspector $schemaInspector;
    private readonly LLMConfigPersistence $persistence;
    private readonly LLMConfigPreferences $preferences;

    /**
     * @param list<class-string<\Spora\Drivers\LLMDriverConfigInterface>> $driverClasses
     *        Driver classes to register with the schema inspector when the
     *        collaborator is not provided explicitly.
     */
    public function __construct(
        SecurityManagerInterface $security,
        array $driverClasses = [],
        ?LLMConfigSchemaInspector $schemaInspector = null,
        ?LLMConfigPersistence $persistence = null,
        ?LLMConfigPreferences $preferences = null,
    ) {
        $this->schemaInspector = $schemaInspector ?? new LLMConfigSchemaInspector($driverClasses);
        $this->persistence = $persistence ?? new LLMConfigPersistence($security, $this->schemaInspector);
        $this->preferences = $preferences ?? new LLMConfigPreferences();
    }

    // ---------------------------------------------------------------------
    // Schema / driver discovery (delegated to SchemaInspector)
    // ---------------------------------------------------------------------

    /**
     * @return list<array{name: string, display_name: string, driver_class: string, settings_schema: list<array>}>
     */
    public function getDrivers(): array
    {
        return $this->schemaInspector->getDrivers();
    }

    // ---------------------------------------------------------------------
    // Settings encode / decode / mask (delegated to Persistence)
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function encodeSettings(string $driverClass, array $settings): array
    {
        return $this->persistence->encodeSettings($driverClass, $settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeSettings(string $driverClass, ?string $raw): array
    {
        return $this->persistence->decodeSettings($driverClass, $raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function decryptSettings(string $driverClass, ?string $raw): array
    {
        return $this->decodeSettings($driverClass, $raw);
    }

    /**
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

    // ---------------------------------------------------------------------
    // Configuration listing / lookup (read paths; no collaborator needed)
    // ---------------------------------------------------------------------

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

    // ---------------------------------------------------------------------
    // Configuration mutations (delegated to Persistence)
    // ---------------------------------------------------------------------

    public function createConfiguration(int $userId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        return $this->persistence->createConfiguration($userId, $data, $isAdmin);
    }

    public function updateConfiguration(int $configId, int $userId, array $data, bool $isAdmin): ?LLMDriverConfiguration
    {
        // $userId is preserved on the public facade signature for backwards
        // compatibility with controllers/callers; the persistence collaborator
        // dropped the parameter (it was unused in the body).
        unset($userId);

        return $this->persistence->updateConfiguration($configId, $data, $isAdmin);
    }

    public function deleteConfiguration(int $configId, int $userId, bool $isAdmin): bool
    {
        return $this->persistence->deleteConfiguration($configId, $userId, $isAdmin);
    }

    // ---------------------------------------------------------------------
    // Default + preference resolution (delegated to Preferences)
    // ---------------------------------------------------------------------

    public function setDefaultConfiguration(int $configId, int $userId, bool $isAdmin): ?LLMDriverConfiguration
    {
        // $userId is preserved on the public facade signature for backwards
        // compatibility; the preferences collaborator dropped the parameter
        // (it was unused in the body).
        unset($userId);

        return $this->preferences->setDefaultConfiguration($configId, $isAdmin);
    }

    public function getDefaultConfiguration(int $userId): ?LLMDriverConfiguration
    {
        return $this->preferences->getDefaultConfiguration($userId);
    }

    public function getEffectiveConfigForAgent(\Spora\Models\Agent $agent): ?LLMDriverConfiguration
    {
        return $this->preferences->getEffectiveConfigForAgent($agent);
    }

    public function getUserPreferredConfig(int $userId): ?LLMDriverConfiguration
    {
        return $this->preferences->getUserPreferredConfig($userId);
    }

    public function setUserPreferredConfig(int $userId, int $configId): bool
    {
        return $this->preferences->setUserPreferredConfig($userId, $configId);
    }

    public function unsetUserPreferredConfig(int $userId): void
    {
        $this->preferences->unsetUserPreferredConfig($userId);
    }

    // ---------------------------------------------------------------------
    // Resource DTO (composes schema + decode + mask; stays on the facade)
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function configResource(LLMDriverConfiguration $config): array
    {
        $settings = $this->decodeSettings($config->driver_class, $config->getRawOriginal('settings'));
        $schema = $this->schemaInspector->getSchemaForDriver($config->driver_class);
        $masked = $this->maskForApi($settings, $schema);

        return [
            'id' => $config->id,
            'name' => $config->name,
            'driver_class' => $config->driver_class,
            'driver_name' => $this->schemaInspector->getDriverName($config->driver_class),
            'driver_display_name' => $this->schemaInspector->getDriverDisplayName($config->driver_class),
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
}
